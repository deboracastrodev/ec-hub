<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Service\CollaborativeFilteringService;
use App\Domain\Recommendation\Service\ExplanationGenerator;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryEventStore;

final class CollaborativeFilteringServiceTest extends TestCase
{
    private InMemoryEventStore $store;
    private CollaborativeFilteringService $service;

    /** @var array<int, Product> */
    private array $catalog;

    protected function setUp(): void
    {
        $this->store = new InMemoryEventStore();
        $this->service = new CollaborativeFilteringService($this->store, new ExplanationGenerator());
        $this->catalog = [
            1 => $this->product(1, 'Fone', 'Eletrônicos', 200.0),
            2 => $this->product(2, 'Monitor', 'Eletrônicos', 1200.0),
            3 => $this->product(3, 'Bola', 'Esportes', 100.0),
            4 => $this->product(4, 'Tênis', 'Esportes', 400.0),
            5 => $this->product(5, 'Luminária', 'Casa', 250.0),
        ];
    }

    public function testBothAlgorithmsImplementTheStrategyPortWithCanonicalNames(): void
    {
        self::assertInstanceOf(RecommendationStrategy::class, $this->service);
        self::assertSame('collaborative', $this->service->getName());
        self::assertTrue(is_subclass_of(KNNService::class, RecommendationStrategy::class));
    }

    public function testRanksByCosineSimilarityOverSessionSets(): void
    {
        $this->seedMatrix();
        $this->service->train(array_values($this->catalog));

        $results = $this->service->recommend($this->catalog[1], 10);

        self::assertSame([3, 2], array_map(static fn ($r) => $r->getProductId(), $results));
        self::assertEqualsWithDelta(100 * 2 / sqrt(6), $results[0]->getScore(), 1e-9);
        self::assertEqualsWithDelta(100 * 2 / sqrt(9), $results[1]->getScore(), 1e-9);
        self::assertSame([1, 2], array_map(static fn ($r) => $r->getRank(), $results));
        foreach ($results as $result) {
            self::assertGreaterThan(0.0, $result->getScore());
            self::assertLessThanOrEqual(100.0, $result->getScore());
            self::assertNotSame(1, $result->getProductId());
        }
    }

    public function testRespectsLimit(): void
    {
        $this->seedMatrix();
        $this->service->train(array_values($this->catalog));

        $results = $this->service->recommend($this->catalog[1], 1);

        self::assertCount(1, $results);
        self::assertSame(3, $results[0]->getProductId());
    }

    public function testTiesAreBrokenByProductIdAscending(): void
    {
        $this->store->interaction('product.viewed', 's1', 1);
        $this->store->interaction('product.viewed', 's1', 4);
        $this->store->interaction('product.viewed', 's1', 2);
        $this->service->train(array_values($this->catalog));

        $results = $this->service->recommend($this->catalog[1], 10);

        self::assertSame([2, 4], array_map(static fn ($r) => $r->getProductId(), $results));
        self::assertSame(100.0, $results[0]->getScore());
    }

    public function testMathematicallyEqualSimilaritiesFromDifferentCountsTieByProductId(): void
    {
        foreach (['s1', 's2', 's3'] as $session) {
            $this->store->interaction('product.viewed', $session, 1);
        }
        $this->store->interaction('product.viewed', 's1', 4);
        $this->store->interaction('product.viewed', 'x1', 4);
        foreach (['s1', 's2', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6'] as $session) {
            $this->store->interaction('product.viewed', $session, 2);
        }
        $this->service->train(array_values($this->catalog));

        $results = $this->service->recommend($this->catalog[1], 10);

        self::assertSame([2, 4], array_map(static fn ($r) => $r->getProductId(), $results));
    }

    public function testASessionCountsOncePerProductAcrossAllInteractionEvents(): void
    {
        $this->store->interaction('product.viewed', 's1', 1);
        $this->store->interaction('product.clicked', 's1', 1);
        $this->store->interaction('cart.item_added', 's1', 1);
        $this->store->interaction('product.viewed', 's1', 2);
        $this->store->interaction('product.viewed', 's1', 2);
        $this->store->interaction('product.viewed', 's2', 2);
        $this->service->train(array_values($this->catalog));

        $results = $this->service->recommend($this->catalog[1], 10);

        // S1 = {s1}, S2 = {s1, s2} -> 1 / sqrt(1 * 2)
        self::assertCount(1, $results);
        self::assertEqualsWithDelta(100 / sqrt(2), $results[0]->getScore(), 1e-9);
    }

    public function testReturnsEmptyWhenTargetHasNoCoOccurrence(): void
    {
        $this->seedMatrix();
        $this->store->interaction('product.viewed', 's9', 5);
        $this->service->train(array_values($this->catalog));

        self::assertSame([], $this->service->recommend($this->catalog[5], 10));
        self::assertSame([], $this->service->recommend($this->catalog[4], 10));
    }

    public function testIgnoresMalformedEventsAndProductsOutsideTheTrainedCatalog(): void
    {
        $this->store->interaction('product.viewed', 's1', 1);
        $this->store->interaction('product.viewed', 's1', 99);
        $this->store->interaction('product.viewed', '', 2);
        $this->store->interaction('product.viewed', 's1', 0);
        $this->store->append(['event' => 'product.viewed', 'data' => 'not-an-array', 'timestamp' => 't']);
        $this->store->append(['event' => 'product.viewed', 'data' => ['product_id' => 2], 'timestamp' => 't']);
        $this->store->append(['event' => 'product.viewed', 'data' => ['session_id' => 's1'], 'timestamp' => 't']);
        $this->store->append([
            'event' => 'product.viewed',
            'data' => ['session_id' => 's1', 'product_id' => 'abc'],
            'timestamp' => 't',
        ]);
        $this->store->append([
            'event' => 'product.clicked',
            'data' => ['session_id' => 's1', 'product_id' => '3'],
            'timestamp' => 't',
        ]);

        $this->service->train(array_values($this->catalog));
        $results = $this->service->recommend($this->catalog[1], 10);

        self::assertSame([3], array_map(static fn ($r) => $r->getProductId(), $results));
    }

    public function testExplanationAndReasonsComeFromTheCollaborativeTemplates(): void
    {
        $this->seedMatrix();
        $this->service->train(array_values($this->catalog));

        [$bola, $monitor] = $this->service->recommend($this->catalog[1], 10);

        self::assertSame(
            'Quem se interessou por Fone também se interessou por este produto (81% de afinidade)',
            $bola->getExplanation()
        );
        self::assertSame([
            ['type' => 'co_interaction', 'description' => '2 sessões se interessaram por este produto e por Fone'],
        ], $bola->getReasons());
        self::assertSame([
            ['type' => 'co_interaction', 'description' => '2 sessões se interessaram por este produto e por Fone'],
            ['type' => 'category', 'description' => 'Mesma categoria: Eletrônicos'],
        ], $monitor->getReasons());
    }

    public function testRecommendBeforeTrainThrows(): void
    {
        self::assertFalse($this->service->isTrained());

        $this->expectException(\RuntimeException::class);
        $this->service->recommend($this->catalog[1], 5);
    }

    public function testUnavailableEventStorePropagatesAndLeavesModelUntrained(): void
    {
        $service = new CollaborativeFilteringService(new InMemoryEventStore(true), new ExplanationGenerator());

        try {
            $service->train(array_values($this->catalog));
            self::fail('Expected the event store failure to propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Event store indisponível.', $exception->getMessage());
        }

        self::assertFalse($service->isTrained());
    }

    public function testRetrainingReplacesThePreviousModel(): void
    {
        $this->seedMatrix();
        $this->service->train(array_values($this->catalog));
        self::assertTrue($this->service->isTrained());

        // Catalog without product 3: its co-occurrences must disappear.
        $this->service->train([$this->catalog[1], $this->catalog[2]]);

        self::assertSame(
            [2],
            array_map(static fn ($r) => $r->getProductId(), $this->service->recommend($this->catalog[1], 10))
        );
    }

    public function testBotSessionOnlyCountsItsFirstDistinctProductsUpToTheCap(): void
    {
        $service = new CollaborativeFilteringService($this->store, new ExplanationGenerator(), 2);
        foreach ([1, 2, 3, 4] as $productId) {
            $this->store->interaction('product.viewed', 'bot', $productId);
        }
        foreach ([1, 3] as $productId) {
            $this->store->interaction('product.viewed', 's1', $productId);
        }

        $service->train(array_values($this->catalog));

        self::assertSame(
            [1],
            array_map(static fn ($r) => $r->getProductId(), $service->recommend($this->catalog[3], 10))
        );
        self::assertSame([], $service->recommend($this->catalog[4], 10));
        $fromOne = $service->recommend($this->catalog[1], 10);
        self::assertEqualsCanonicalizing([2, 3], array_map(static fn ($r) => $r->getProductId(), $fromOne));
        // |S_1| = 2 (bot, s1), |S_2| = 1 (bot), |S_3| = 1 (s1): o bot não conta em 3.
        foreach ($fromOne as $result) {
            self::assertEqualsWithDelta(100 / sqrt(2), $result->getScore(), 1e-9);
        }
    }

    public function testRepeatedProductInASessionCountsOnceTowardsTheCap(): void
    {
        $service = new CollaborativeFilteringService($this->store, new ExplanationGenerator(), 2);
        foreach ([1, 1, 2] as $productId) {
            $this->store->interaction('product.viewed', 's1', $productId);
        }

        $service->train(array_values($this->catalog));

        self::assertSame(
            [2],
            array_map(static fn ($r) => $r->getProductId(), $service->recommend($this->catalog[1], 10))
        );
        self::assertSame(
            [1],
            array_map(static fn ($r) => $r->getProductId(), $service->recommend($this->catalog[2], 10))
        );
    }

    public function testRejectsAProductsPerSessionCapBelowOne(): void
    {
        foreach ([0, -1] as $invalid) {
            try {
                new CollaborativeFilteringService($this->store, new ExplanationGenerator(), $invalid);
                self::fail("Teto {$invalid} deveria ser rejeitado.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDefaultCapStopsCountingASessionAfterFiftyDistinctProducts(): void
    {
        $catalog = [];
        foreach (range(1, CollaborativeFilteringService::DEFAULT_MAX_PRODUCTS_PER_SESSION + 1) as $id) {
            $catalog[$id] = $this->product($id, "Produto {$id}", 'Casa', 10.0 + $id);
            $this->store->interaction('product.viewed', 'bot', $id);
        }

        $this->service->train(array_values($catalog));

        self::assertSame(50, CollaborativeFilteringService::DEFAULT_MAX_PRODUCTS_PER_SESSION);
        self::assertSame([], $this->service->recommend($catalog[51], 10));
        self::assertCount(49, $this->service->recommend($catalog[1], 100));
    }

    public function testCapFollowsTheReadOrderAcrossInteractionEvents(): void
    {
        $service = new CollaborativeFilteringService($this->store, new ExplanationGenerator(), 2);
        $this->store->interaction('cart.item_added', 'bot', 3);
        $this->store->interaction('product.viewed', 'bot', 1);
        $this->store->interaction('product.viewed', 'bot', 2);

        $service->train(array_values($this->catalog));

        // product.viewed é lido antes de cart.item_added: o teto já foi ocupado por 1 e 2.
        self::assertSame(
            [2],
            array_map(static fn ($r) => $r->getProductId(), $service->recommend($this->catalog[1], 10))
        );
        self::assertSame([], $service->recommend($this->catalog[3], 10));
    }

    /** Spec matrix: s1 {1,2,3}, s2 {1,2}, s3 {1,3}, s4 {2}. */
    private function seedMatrix(): void
    {
        foreach (['s1' => [1, 2, 3], 's2' => [1, 2], 's3' => [1, 3], 's4' => [2]] as $session => $products) {
            foreach ($products as $productId) {
                $this->store->interaction('product.viewed', $session, $productId);
            }
        }
    }

    private function product(int $id, string $name, string $category, float $price): Product
    {
        $product = new Product($name, '', Money::fromDecimal($price), $category);
        $product->setId($id);

        return $product;
    }
}
