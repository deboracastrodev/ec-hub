<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Event\TrackProductInteraction;
use App\Controller\ProductInteractionController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class ProductInteractionHttpEndpointTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testPostRoutesDispatchJsonAndRedactInternalSession(): void
    {
        header_remove();
        http_response_code(200);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('product.clicked', self::isArray());
        $this->installContainer($this->controller($publisher, true));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/events';
        $GLOBALS['EC_HUB_TEST_JSON_BODY'] = '{"product_id":7,"interaction":"click"}';

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $decoded = json_decode((string) ob_get_clean(), true);

        self::assertSame(200, http_response_code());
        self::assertSame('product.clicked', $decoded['data']['event']);
        self::assertArrayNotHasKey('session_id', $decoded['data']);
    }

    #[RunInSeparateProcess]
    public function testCartRouteMutatesCartAndReturnsRedactedJson(): void
    {
        header_remove();
        http_response_code(200);
        $sessions = new HttpInMemorySessionRepository();
        $this->installContainer($this->controller($this->createStub(EventPublisherInterface::class), true, $sessions));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/cart/items';
        $GLOBALS['EC_HUB_TEST_JSON_BODY'] = '{"product_id":7,"quantity":2}';

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $decoded = json_decode((string) ob_get_clean(), true);

        self::assertSame(200, http_response_code());
        self::assertSame(['7' => 2], $sessions->get(str_repeat('d', 64), 'cart.items'));
        self::assertArrayNotHasKey('session_id', $decoded['data']);
    }

    #[RunInSeparateProcess]
    public function testUnknownProductReturns404WithoutPublishing(): void
    {
        header_remove();
        http_response_code(200);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $this->installContainer($this->controller($publisher, false));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/events';
        $GLOBALS['EC_HUB_TEST_JSON_BODY'] = '{"product_id":999,"interaction":"click"}';

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $decoded = json_decode((string) ob_get_clean(), true);

        self::assertSame(404, http_response_code());
        self::assertArrayHasKey('error', $decoded);
    }

    #[RunInSeparateProcess]
    public function testInvalidPayloadReturns400WithoutPublishing(): void
    {
        header_remove();
        http_response_code(200);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $this->installContainer($this->controller($publisher, true));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/cart/items';
        $GLOBALS['EC_HUB_TEST_JSON_BODY'] = '{"product_id":7,"quantity":0}';

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';
        $decoded = json_decode((string) ob_get_clean(), true);

        self::assertSame(400, http_response_code());
        self::assertArrayHasKey('error', $decoded);
    }

    private function controller(
        EventPublisherInterface $publisher,
        bool $productExists,
        ?SessionRepositoryInterface $sessions = null
    ): ProductInteractionController {
        $products = $this->createStub(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($productExists ? $this->createStub(Product::class) : null);
        $history = new class () implements EventHistoryRepositoryInterface {
            public function append(string $sessionId, ?string $userId, array $event): void
            {
            }

            public function getBySession(string $sessionId): array
            {
                return [];
            }

            public function getByUserId(string $userId): array
            {
                return [];
            }
        };
        $tracker = new TrackProductInteraction(
            $products,
            $sessions ?? new HttpInMemorySessionRepository(),
            $history,
            $publisher,
            new NullLogger()
        );
        $_COOKIE[SessionContext::COOKIE_NAME] = str_repeat('d', 64);
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac(
            'sha256',
            $_COOKIE[SessionContext::COOKIE_NAME],
            'phpunit-only-session-cookie-secret-32'
        );

        return new ProductInteractionController($tracker, new SessionContext('phpunit-only-session-cookie-secret-32'));
    }

    private function installContainer(ProductInteractionController $controller): void
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'));
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            ProductInteractionController::class => fn () => $controller,
        ]);
    }
}

final class HttpInMemorySessionRepository implements SessionRepositoryInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    public function save(string $sessionId, string $field, mixed $value): void
    {
        $this->values[$sessionId . ':' . $field] = $value;
    }

    public function get(string $sessionId, string $field): mixed
    {
        return $this->values[$sessionId . ':' . $field] ?? null;
    }
}
