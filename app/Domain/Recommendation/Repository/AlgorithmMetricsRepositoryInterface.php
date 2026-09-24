<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Repository;

use App\Domain\Recommendation\Model\AlgorithmMetrics;
use App\Domain\Recommendation\Model\AlgorithmRequestSample;

/**
 * Story 8.2: port for the per-algorithm aggregate used by the A/B test.
 */
interface AlgorithmMetricsRepositoryInterface
{
    public function record(string $algorithm, AlgorithmRequestSample $sample): void;

    /** Never null: an algorithm with no samples yields zeroed counters. */
    public function get(string $algorithm): AlgorithmMetrics;
}
