<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Recommendation\Model\AlgorithmMetrics;
use App\Domain\Recommendation\Model\AlgorithmRequestSample;
use App\Domain\Recommendation\Repository\AlgorithmMetricsRepositoryInterface;

/**
 * In-memory AlgorithmMetricsRepositoryInterface fake (Story 8.2). Can be told
 * to fail on writes and/or reads to simulate Redis being unavailable.
 */
final class InMemoryAlgorithmMetricsRepository implements AlgorithmMetricsRepositoryInterface
{
    /** @var list<array{algorithm: string, sample: AlgorithmRequestSample}> */
    public array $recorded = [];

    /** @var array<string, array<string, true>> */
    private array $subjects = [];

    public function __construct(
        private readonly bool $failOnRecord = false,
        private readonly bool $failOnGet = false,
    ) {
    }

    public function record(string $algorithm, AlgorithmRequestSample $sample): void
    {
        if ($this->failOnRecord) {
            throw new \RuntimeException('Redis indisponível.');
        }

        $this->recorded[] = ['algorithm' => $algorithm, 'sample' => $sample];
        if ($sample->subjectId !== null) {
            $this->subjects[$algorithm][$sample->subjectId] = true;
        }
    }

    public function get(string $algorithm): AlgorithmMetrics
    {
        if ($this->failOnGet) {
            throw new \RuntimeException('Redis indisponível.');
        }

        $requests = 0;
        $totalItems = 0;
        $mlItems = 0;
        $scoreSum = 0.0;
        $scoredItems = 0;
        $responseTime = 0.0;
        foreach ($this->recorded as $entry) {
            if ($entry['algorithm'] !== $algorithm) {
                continue;
            }
            $sample = $entry['sample'];
            ++$requests;
            $totalItems += $sample->totalItems;
            $mlItems += $sample->mlItems;
            $scoreSum += $sample->scoreSum;
            $scoredItems += $sample->scoredItems;
            $responseTime += $sample->responseTimeMs;
        }

        return new AlgorithmMetrics(
            $algorithm,
            $requests,
            count($this->subjects[$algorithm] ?? []),
            $totalItems,
            $mlItems,
            $scoreSum,
            $scoredItems,
            $responseTime,
        );
    }
}
