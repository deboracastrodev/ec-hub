<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Cart\ManageCart;
use App\Application\Event\TrackProductInteraction;
use App\Controller\CartController;
use App\Domain\Cart\Model\Cart;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Shared\Http\Request;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\AdminTestTwig;
use Tests\Support\InMemoryEventStore;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemorySessionRepository;

/** Story 8.6: redirects (post/redirect/get) and status messages of the cart actions. */
final class CartControllerTest extends TestCase
{
    private const SECRET = 'phpunit-only-session-cookie-secret-32';
    private const SESSION = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private InMemorySessionRepository $sessions;

    private int $emissions = 0;

    protected function setUp(): void
    {
        $this->sessions = new InMemorySessionRepository();
        $_COOKIE[SessionContext::COOKIE_NAME] = self::SESSION;
        $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME] = hash_hmac('sha256', self::SESSION, self::SECRET);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]);
    }

    private function controller(): CartController
    {
        $products = new InMemoryProductRepository();
        $tracker = new TrackProductInteraction(
            $products,
            $this->sessions,
            $this->createStub(EventHistoryRepositoryInterface::class),
            new InMemoryEventStore(),
            $this->createStub(EventPublisherInterface::class),
            new NullLogger()
        );

        return new CartController(
            new ManageCart($this->sessions, $products),
            $tracker,
            new SessionContext(self::SECRET, function (): bool {
                $this->emissions++;

                return true;
            }),
            new SessionCsrf(self::SECRET),
            AdminTestTwig::create()
        );
    }

    /** @param array<string, mixed> $body */
    private function post(array $body, array $params = []): Request
    {
        return new Request('POST', $params, [], $body + ['_csrf' => (new SessionCsrf(self::SECRET))->token(self::SESSION)]);
    }

    public function testRedirectLocations(): void
    {
        $controller = $this->controller();

        $added = $controller->add($this->post(['product_id' => '1']));
        self::assertSame(303, $added->status);
        self::assertSame('/cart?status=added', $added->headers['Location']);
        self::assertSame('no-store', $added->headers['Cache-Control']);
        self::assertSame(['1' => 1], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD));

        self::assertSame('/cart?status=updated', $controller->update($this->post(['quantity' => '4'], ['1']))->headers['Location']);
        self::assertSame('/cart?status=removed', $controller->update($this->post(['quantity' => '0'], ['1']))->headers['Location']);
        self::assertSame('/cart?status=removed', $controller->remove($this->post([], ['1']))->headers['Location']);
        self::assertSame(0, $this->emissions);
    }

    public function testAddReportsAFailedCartWrite(): void
    {
        $controller = $this->controller();
        $this->sessions->failWith = new \RuntimeException('Redis fora');

        $response = $controller->add($this->post(['product_id' => '1', 'quantity' => '2']));

        self::assertSame(303, $response->status);
        self::assertSame('/cart?status=add_failed', $response->headers['Location']);
    }

    public function testIndexRendersA503PageWhenTheSessionStoreFails(): void
    {
        $controller = $this->controller();
        $this->sessions->failWith = new \Exception('Connection refused');

        $response = $controller->index(new Request('GET', [], ['status' => 'added']));

        self::assertSame(503, $response->status);
        self::assertStringContainsString('role="alert">' . CartController::LOAD_FAILED_MESSAGE, $response->body);
        self::assertStringNotContainsString('Produto adicionado ao carrinho.', $response->body);
        self::assertStringNotContainsString('Seu carrinho está vazio.', $response->body);
    }

    public function testUpdateAndRemoveRedirectToAddFailedWhenTheSessionStoreFails(): void
    {
        $controller = $this->controller();
        $controller->add($this->post(['product_id' => '1']));
        $this->sessions->failWith = new \Exception('Connection refused');

        foreach ([
            $controller->update($this->post(['quantity' => '3'], ['1'])),
            $controller->remove($this->post([], ['1'])),
        ] as $response) {
            self::assertSame(303, $response->status);
            self::assertSame('/cart?status=add_failed', $response->headers['Location']);
        }

        $this->sessions->failWith = null;
        $this->sessions->casConflicts = 5;
        self::assertSame('/cart?status=add_failed', $controller->remove($this->post([], ['1']))->headers['Location']);
    }

    public function testAddValidatesInput(): void
    {
        $controller = $this->controller();

        foreach ([['product_id' => '0'], ['product_id' => '-1'], ['product_id' => '1', 'quantity' => '1.5'], ['product_id' => '1', 'user_id' => '  ']] as $body) {
            self::assertSame(400, $controller->add($this->post($body))->status, json_encode($body));
        }
        self::assertNull($this->sessions->get(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testIndexStatusMessagesAreAClosedList(): void
    {
        $controller = $this->controller();

        foreach (CartController::STATUS_MESSAGES as $status => $message) {
            $body = $controller->index(new Request('GET', [], ['status' => $status]))->body;
            self::assertStringContainsString('role="status">' . $message, $body);
        }
        $failed = $controller->index(new Request('GET', [], ['status' => 'add_failed']));
        self::assertSame(200, $failed->status);
        self::assertStringContainsString(
            'class="cart__alert cart__alert--error" role="alert">' . CartController::ERROR_STATUS_MESSAGES['add_failed'],
            $failed->body
        );
        self::assertStringNotContainsString('role="status"', $failed->body);
        $other = $controller->index(new Request('GET', [], ['status' => 'hacked']));
        self::assertSame(200, $other->status);
        self::assertStringNotContainsString('role="status"', $other->body);
        self::assertStringNotContainsString('hacked', $other->body);
    }
}
