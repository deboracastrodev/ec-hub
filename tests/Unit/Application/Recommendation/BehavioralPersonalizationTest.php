<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BehavioralPersonalizationTest extends TestCase
{
    public function testPublicPipelineChangesBaselineForSessionAndUserWithoutDuplicates(): void
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
                return $userId === 'user-1' ? [['event' => 'cart.item_added', 'product_id' => 9]] : [];
            }
        };
        $productsById = [
            1 => Product::fromArray(['id' => 1, 'name' => 'Target', 'category' => 'Target', 'price' => 10]),
            9 => Product::fromArray(['id' => 9, 'name' => 'Interest', 'category' => 'A', 'price' => 10]),
        ];
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('findAll')->willReturn([$productsById[1]]);
        $products->method('findById')->willReturnCallback(static fn (int $id): ?Product => $productsById[$id] ?? null);
        $fallback = $this->createMock(RuleBasedFallback::class);
        $fallback->method('getRecommendations')->willReturn([
            ['product_id' => 2, 'product_name' => 'B', 'category' => 'B', 'score' => 1],
            ['product_id' => 3, 'product_name' => 'A', 'category' => 'A', 'score' => 1],
        ]);
        $service = new GenerateRecommendations(
            $products,
            $this->createMock(KNNService::class),
            $fallback,
            new NullLogger(),
            RecommendationSettings::fromArray(['min_products_for_ml' => 5]),
            null,
            $history
        );

        $baseline = $service->execute(1, 2);
        $sessionResult = $service->execute(1, 2, false, null, 'session-1');
        $userResult = $service->execute(1, 2, false, null, null, 'user-1');

        self::assertSame([2, 3], array_column($baseline, 'product_id'));
        self::assertSame([3, 2], array_column($sessionResult, 'product_id'));
        self::assertSame([3, 2], array_column($userResult, 'product_id'));
        self::assertSame(array_unique(array_column($userResult, 'product_id')), array_column($userResult, 'product_id'));
    }
}
