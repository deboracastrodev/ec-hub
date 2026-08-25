<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Event\TrackProductInteraction;
use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Controller\ProductController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;

final class ProductControllerTrackingTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]);
    }

    public function testShowTracksViewAndStillRendersWhenRedisFails(): void
    {
        $detail = $this->createMock(GetProductDetail::class);
        $detail->method('executeByIdentifier')->willReturn([
            'id' => 7, 'name' => 'Produto', 'description' => '', 'category' => 'A',
            'price' => 10, 'image_url' => '', 'slug' => 'prod',
        ]);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publishedEvent = null;
        $publisher->expects(self::once())->method('publish')->with('product.viewed', self::isArray())
            ->willReturnCallback(static function (string $event, array $payload) use (&$publishedEvent): void {
                $publishedEvent = $payload;

                throw new \RuntimeException('Redis indisponível');
            });
        $cookies = [];
        $session = new SessionContext('phpunit-only-session-cookie-secret-32', static function (string $name, string $value, array $options) use (&$cookies): bool {
            $cookies[$name] = compact('name', 'value', 'options');

            return true;
        });
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');
        $controller = new ProductController(
            $this->createMock(GetProductList::class),
            $detail,
            $twig,
            null,
            $this->tracker($publisher),
            $session
        );

        self::assertSame('<html></html>', $controller->show('prod', ['user_id' => 'user-1']));
        self::assertSame($cookies[SessionContext::COOKIE_NAME]['value'], $publishedEvent['session_id']);
        self::assertSame('user-1', $publishedEvent['user_id']);
        self::assertSame(7, $publishedEvent['product_id']);
        self::assertArrayHasKey('timestamp', $publishedEvent);
        self::assertTrue($cookies[SessionContext::COOKIE_NAME]['options']['httponly']);
        self::assertSame('Lax', $cookies[SessionContext::COOKIE_NAME]['options']['samesite']);
        self::assertArrayHasKey(SessionContext::SIGNATURE_COOKIE_NAME, $cookies);
    }

    private function tracker(EventPublisherInterface $publisher): TrackProductInteraction
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createMock(Product::class));
        $sessions = new class () implements SessionRepositoryInterface {
            /** @var array<string, mixed> */
            private array $values = [];

            public function save(string $sessionId, string $field, mixed $value): void
            {
                $this->values[$sessionId . ':' . $field] = $value;
            }

            public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
            {
                $key = $sessionId . ':' . $field;
                if (($this->values[$key] ?? null) !== $expected) {
                    return false;
                }

                $this->values[$key] = $value;
                return true;
            }

            public function get(string $sessionId, string $field): mixed
            {
                return $this->values[$sessionId . ':' . $field] ?? null;
            }
        };
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

        return new TrackProductInteraction($products, $sessions, $history, $publisher, new NullLogger());
    }
}
