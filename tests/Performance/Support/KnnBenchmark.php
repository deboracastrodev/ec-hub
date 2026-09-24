<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\NeighborFinderInterface;
use App\Infrastructure\ML\RubixNeighborFinder;
use Tests\Support\InMemoryProductRepository;

/**
 * Benchmark do KNN sobre catálogo sintético: KNNService real + RubixNeighborFinder
 * real, envolvido por um contador de chamadas a train(). Usado pelo
 * KnnBenchmarkTest (asserções) e por bin/benchmark-knn.php (tabela).
 */
final class KnnBenchmark
{
    private const CATEGORIES = ['Eletrônicos', 'Informática', 'Casa', 'Esportes', 'Moda', 'Livros', 'Brinquedos', 'Beleza'];

    /**
     * @return array{
     *     products: int,
     *     train_calls: int,
     *     train_ms: float,
     *     recommend_ms: list<float>,
     *     result_counts: list<int>
     * }
     */
    public static function run(int $productCount, int $recommendCalls = 100, int $limit = 5): array
    {
        $repository = new InMemoryProductRepository(self::syntheticCatalog($productCount));
        // Contador de train(): prova que 100 recommend() não retreinam o índice.
        $finder = new class (new RubixNeighborFinder()) implements NeighborFinderInterface {
            private int $trainCalls = 0;

            public function __construct(private readonly NeighborFinderInterface $inner)
            {
            }

            public function train(array $products): void
            {
                $this->trainCalls++;
                $this->inner->train($products);
            }

            public function isTrained(): bool
            {
                return $this->inner->isTrained();
            }

            public function nearest(Product $target, int $k): array
            {
                return $this->inner->nearest($target, $k);
            }

            public function trainCalls(): int
            {
                return $this->trainCalls;
            }
        };
        $service = new KNNService($repository, $finder);

        $catalog = $repository->findAll($productCount, 0);

        $start = hrtime(true);
        $service->train($catalog);
        $trainMs = (hrtime(true) - $start) / 1e6;

        $recommendMs = [];
        $resultCounts = [];
        $step = max(1, intdiv($productCount, $recommendCalls));
        for ($i = 0; $i < $recommendCalls; $i++) {
            $target = $catalog[($i * $step) % $productCount];

            $start = hrtime(true);
            $results = $service->recommend($target, $limit);
            $recommendMs[] = (hrtime(true) - $start) / 1e6;
            $resultCounts[] = count($results);
        }

        return [
            'products' => $productCount,
            'train_calls' => $finder->trainCalls(),
            'train_ms' => $trainMs,
            'recommend_ms' => $recommendMs,
            'result_counts' => $resultCounts,
        ];
    }

    /**
     * Catálogo determinístico: categorias em rodízio e preços espalhados
     * de R$ 10 a ~R$ 5.000.
     *
     * @return list<array<string, mixed>>
     */
    public static function syntheticCatalog(int $productCount): array
    {
        $rows = [];
        for ($id = 1; $id <= $productCount; $id++) {
            $rows[] = [
                'id' => $id,
                'name' => "Produto sintético {$id}",
                'slug' => "produto-sintetico-{$id}",
                'description' => 'Produto gerado para benchmark do KNN.',
                'price' => round(10 + (($id * 7919) % 499_000) / 100, 2),
                'category' => self::CATEGORIES[$id % count(self::CATEGORIES)],
                'image_url' => '/assets/images/placeholder.jpg',
                'created_at' => '2026-01-01 00:00:00',
            ];
        }

        return $rows;
    }
}
