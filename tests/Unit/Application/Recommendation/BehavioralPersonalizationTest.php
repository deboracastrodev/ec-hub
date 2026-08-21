<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BehavioralPersonalizationTest extends TestCase
{
    public function testReordersBaselineFromSessionAndUserHistory(): void
    {
        $history = new class () implements EventHistoryRepositoryInterface {
            public function append(string $sessionId, ?string $userId, array $event): void
            {
            }

            public function getBySession(string $sessionId): array
            {
                return $sessionId === 'session-1' ? [['event' => 'product.viewed', 'product_id' => 9]] : [];
            }

            public function getByUserId(string $userId): array
            {
                return $userId === 'user-1' ? [['event' => 'product.clicked', 'product_id' => 8], ['event' => 'cart.item_added', 'product_id' => 9]] : [];
            }
        };
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findById')->willReturnCallback(static function (int $id): Product {
            return new Product('P' . $id, '', Money::fromDecimal(1), $id === 8 ? 'B' : 'A');
        });
        $service = new GenerateRecommendations($products, $this->createMock(KNNService::class), $this->createMock(RuleBasedFallback::class), new NullLogger(), null, null, $history);
        $method = new \ReflectionMethod($service, 'personalize');
        $baseline = [['product_id' => 1, 'category' => 'B'], ['product_id' => 2, 'category' => 'A']];

        self::assertSame($baseline, $method->invoke($service, $baseline, 'empty', null));
        self::assertSame([2, 1], array_column($method->invoke($service, $baseline, 'session-1', null), 'product_id'));
        self::assertSame([2, 1], array_column($method->invoke($service, $baseline, null, 'user-1'), 'product_id'));
    }
}
