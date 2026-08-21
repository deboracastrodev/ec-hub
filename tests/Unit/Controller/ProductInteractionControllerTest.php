<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Event\TrackProductInteraction;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\ProductInteractionController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Http\SessionContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProductInteractionControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE[SessionContext::COOKIE_NAME]);
    }

    public function testTracksValidClick(): void
    {
        $_COOKIE[SessionContext::COOKIE_NAME] = str_repeat('a', 64);
        $publisher = $this->createMock(EventPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with('product.clicked', self::isType('array'));
        $controller = new ProductInteractionController($this->tracker($publisher), new SessionContext());

        $result = $controller->event(['product_id' => 7, 'interaction' => 'click']);

        self::assertSame('product.clicked', $result['data']['event']);
    }

    public function testRejectsInvalidCartQuantity(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('quantity must be a positive integer');
        (new ProductInteractionController($this->tracker($this->createMock(EventPublisherInterface::class)), new SessionContext()))->addCartItem(['product_id' => 7, 'quantity' => 0]);
    }

    public function testRejectsInvalidUserId(): void
    {
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('user_id must be a non-empty string');
        (new ProductInteractionController($this->tracker($this->createMock(EventPublisherInterface::class)), new SessionContext()))->event(['product_id' => 7, 'user_id' => true]);
    }

    private function tracker(EventPublisherInterface $publisher): TrackProductInteraction
    {
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturn($this->createMock(Product::class));
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
