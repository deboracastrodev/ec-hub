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
        unset($_COOKIE[SessionContext::COOKIE_NAME]);
    }

    public function testShowTracksProductViewWithCookieSession(): void
    {
        $_COOKIE[SessionContext::COOKIE_NAME] = str_repeat('b', 64);
        $detail = $this->createMock(GetProductDetail::class);
        $detail->expects(self::once())->method('executeByIdentifier')->with('prod')->willReturn([
            'id' => 7,
            'name' => 'Produto',
            'description' => '',
            'category' => 'A',
            'price' => 10,
            'image_url' => '',
            'slug' => 'prod',
        ]);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('product.viewed', self::callback(
            static fn (array $event): bool => $event['session_id'] === str_repeat('b', 64) && $event['product_id'] === 7
        ));
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html></html>');
        $controller = new ProductController(
            $this->createMock(GetProductList::class),
            $detail,
            $twig,
            null,
            $this->tracker($publisher),
            new SessionContext()
        );

        self::assertSame('<html></html>', $controller->show('prod'));
    }

    private function tracker(EventPublisherInterface $publisher): TrackProductInteraction
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->with(7)->willReturn($this->createMock(Product::class));
        $sessions = new class () implements SessionRepositoryInterface {
            public function save(string $sessionId, string $field, mixed $value): void
            {
            }

            public function get(string $sessionId, string $field): mixed
            {
                return null;
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
