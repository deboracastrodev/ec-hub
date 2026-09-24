<?php

declare(strict_types=1);

/**
 * Benchmark do KNN (KNNService + RubixNeighborFinder) sobre catálogos
 * sintéticos de 100, 1.000 e 5.000 produtos: tempo de treino e p50/p95/máx
 * de 100 chamadas a recommend(limit=5).
 *
 * Uso: php bin/benchmark-knn.php   (ou make benchmark-knn, dentro do container)
 */

use Tests\Performance\Support\KnnBenchmark;
use Tests\Performance\Support\LiveHttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';

$rowFormat = "| %8s | %10s | %13s | %13s | %13s |\n";
printf($rowFormat, 'produtos', 'treino ms', 'recommend p50', 'recommend p95', 'recommend máx');
printf("|%s|%s|%s|%s|%s|\n", str_repeat('-', 10), str_repeat('-', 12), str_repeat('-', 15), str_repeat('-', 15), str_repeat('-', 15));

foreach ([100, 1000, 5000] as $productCount) {
    $result = KnnBenchmark::run($productCount);
    $samples = $result['recommend_ms'];

    printf(
        $rowFormat,
        number_format($productCount, 0, ',', '.'),
        sprintf('%.2f', $result['train_ms']),
        sprintf('%.2f', LiveHttpClient::percentile($samples, 50)),
        sprintf('%.2f', LiveHttpClient::percentile($samples, 95)),
        sprintf('%.2f', max($samples))
    );
}

exit(0);
