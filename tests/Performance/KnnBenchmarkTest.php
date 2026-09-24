<?php

declare(strict_types=1);

namespace Tests\Performance;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Performance\Support\KnnBenchmark;
use Tests\Performance\Support\LiveHttpClient;

/**
 * Benchmark do KNN: 1.000 produtos sintéticos, índice treinado uma única
 * vez e 100 recommend() com p95 < 200 ms.
 */
#[Group('performance')]
final class KnnBenchmarkTest extends TestCase
{
    private const PRODUCTS = 1000;
    private const RECOMMEND_CALLS = 100;
    private const LIMIT = 5;

    public function test_knn_trains_once_and_recommends_within_200ms_p95_on_1000_products(): void
    {
        $catalog = KnnBenchmark::syntheticCatalog(self::PRODUCTS);
        self::assertGreaterThanOrEqual(5, count(array_unique(array_column($catalog, 'category'))));
        self::assertGreaterThan(100, count(array_unique(array_column($catalog, 'price'))));

        $result = KnnBenchmark::run(self::PRODUCTS, self::RECOMMEND_CALLS, self::LIMIT);

        self::assertSame(1, $result['train_calls'], 'O índice deveria ser treinado uma única vez para 100 recommend()');
        self::assertCount(self::RECOMMEND_CALLS, $result['result_counts']);
        foreach ($result['result_counts'] as $i => $count) {
            self::assertSame(self::LIMIT, $count, "recommend() #{$i} deveria devolver " . self::LIMIT . ' itens');
        }

        self::assertLessThan(
            200.0,
            LiveHttpClient::percentile($result['recommend_ms'], 95),
            'p95 de KNNService::recommend acima de 200 ms: ' . LiveHttpClient::summary($result['recommend_ms'])
        );
    }
}
