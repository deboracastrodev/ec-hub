<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\NeighborFinderInterface;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;

/**
 * Deterministic KNNService behavior using a fake NeighborFinder, so the
 * output (scores, ranking, exclusion of the target) is asserted exactly
 * without depending on the Rubix ML index.
 */
final class KNNServiceDeterministicTest extends TestCase
{
    private FakeNeighborFinder $neighborFinder;
    private KNNService $service;

    protected function setUp(): void
    {
        $repository = $this->createStub(ProductRepositoryInterface::class);
        $this->neighborFinder = new FakeNeighborFinder();
        $this->service = new KNNService($repository, $this->neighborFinder);
    }

    public function testRecommendProducesDeterministicRankedScores(): void
    {
        $target = $this->product(1, 'Laptop Gamer', 'Eletrônicos', 4500.0);

        $this->neighborFinder->setNeighbors([
            ['product' => $this->product(2, 'Mouse Gamer', 'Eletrônicos', 150.0), 'distance' => 0.5],
            ['product' => $this->product(3, 'Teclado', 'Eletrônicos', 300.0), 'distance' => 1.0],
            ['product' => $this->product(4, 'Camiseta', 'Roupas', 79.9), 'distance' => 2.0],
        ]);

        $this->service->train([$target, $this->product(2, 'Mouse Gamer', 'Eletrônicos', 150.0)]);

        $results = $this->service->recommend($target, 3);

        // Score = 100 * (1 / (1 + distance)), floored into the domain formula.
        $this->assertCount(3, $results);
        $this->assertSame(2, $results[0]->getProductId());
        $this->assertSame(100 * (1 / (1 + 0.5)), $results[0]->getScore());
        $this->assertSame(1, $results[0]->getRank());
        $this->assertSame(3, $results[1]->getProductId());
        $this->assertSame(2, $results[1]->getRank());
        $this->assertSame(4, $results[2]->getProductId());
        $this->assertSame(3, $results[2]->getRank());
    }

    public function testRecommendExcludesTargetProductEvenWhenNearest(): void
    {
        $target = $this->product(1, 'Laptop Gamer', 'Eletrônicos', 4500.0);

        // The target itself is the nearest neighbor (distance 0) - must be dropped.
        $this->neighborFinder->setNeighbors([
            ['product' => $target, 'distance' => 0.0],
            ['product' => $this->product(2, 'Mouse Gamer', 'Eletrônicos', 150.0), 'distance' => 0.5],
        ]);

        $this->service->train([$target, $this->product(2, 'Mouse Gamer', 'Eletrônicos', 150.0)]);

        $results = $this->service->recommend($target, 5);

        $this->assertCount(1, $results);
        $this->assertSame(2, $results[0]->getProductId());
    }

    public function testRecommendScoreIsClampedToValidRange(): void
    {
        $target = $this->product(1, 'Laptop Gamer', 'Eletrônicos', 4500.0);

        // A distance of 0 would give score 100; a huge distance approaches 0.
        $this->neighborFinder->setNeighbors([
            ['product' => $this->product(2, 'A', 'Eletrônicos', 10.0), 'distance' => 0.0001],
            ['product' => $this->product(3, 'B', 'Eletrônicos', 10.0), 'distance' => 999999.0],
        ]);

        $this->service->train([$target, $this->product(2, 'A', 'Eletrônicos', 10.0)]);

        $results = $this->service->recommend($target, 5);

        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(0, $result->getScore());
            $this->assertLessThanOrEqual(100, $result->getScore());
        }
    }

    public function testRecommendLimitCapsNumberOfResults(): void
    {
        $target = $this->product(1, 'Laptop Gamer', 'Eletrônicos', 4500.0);

        $this->neighborFinder->setNeighbors([
            ['product' => $this->product(2, 'A', 'Eletrônicos', 10.0), 'distance' => 0.5],
            ['product' => $this->product(3, 'B', 'Eletrônicos', 10.0), 'distance' => 1.0],
            ['product' => $this->product(4, 'C', 'Eletrônicos', 10.0), 'distance' => 1.5],
        ]);

        $this->service->train([$target, $this->product(2, 'A', 'Eletrônicos', 10.0)]);

        $results = $this->service->recommend($target, 2);

        $this->assertCount(2, $results);
        $this->assertSame([2, 3], [$results[0]->getProductId(), $results[1]->getProductId()]);
    }

    public function testRecommendWithoutPriorTrainLoadsFromRepository(): void
    {
        $target = $this->product(1, 'Laptop Gamer', 'Eletrônicos', 4500.0);

        $this->neighborFinder->setNeighbors([
            ['product' => $this->product(2, 'Mouse Gamer', 'Eletrônicos', 150.0), 'distance' => 0.5],
        ]);

        $results = $this->service->recommend($target, 3);

        // The fake neighbor finder is untrained, so ensureModelIsTrained will
        // try to load from repository (empty) and then fall through. The fake
        // finder is still "trained" after the empty train call.
        $this->assertCount(1, $results);
        $this->assertSame(2, $results[0]->getProductId());
    }

    private function product(int $id, string $name, string $category, float $price): Product
    {
        $product = new Product($name, '', Money::fromDecimal($price), $category);
        $product->setId($id);

        return $product;
    }
}

/**
 * Minimal in-memory NeighborFinder fake that returns a predetermined,
 * fixed neighbor list.
 */
final class FakeNeighborFinder implements NeighborFinderInterface
{
    private bool $trained = false;

    /** @var list<array{product: Product, distance: float}> */
    private array $neighbors = [];

    public function train(array $products): void
    {
        $this->trained = true;
    }

    public function isTrained(): bool
    {
        return $this->trained;
    }

    /**
     * @param list<array{product: Product, distance: float}> $neighbors
     */
    public function setNeighbors(array $neighbors): void
    {
        $this->neighbors = $neighbors;
    }

    public function nearest(Product $target, int $k): array
    {
        return array_slice($this->neighbors, 0, $k);
    }
}
