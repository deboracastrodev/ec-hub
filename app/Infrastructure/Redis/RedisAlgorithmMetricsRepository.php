<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Recommendation\Model\AlgorithmMetrics;
use App\Domain\Recommendation\Model\AlgorithmRequestSample;
use App\Domain\Recommendation\Repository\AlgorithmMetricsRepositoryInterface;
use Predis\Client;
use Predis\Transaction\MultiExec;

/**
 * Story 8.2: per-algorithm A/B aggregates in Redis.
 *
 * - hash `ec-hub:ab-metrics:{algorithm}`: counters (HINCRBY / HINCRBYFLOAT)
 * - HyperLogLog `ec-hub:ab-metrics:{algorithm}:subjects`: unique subjects
 *
 * No TTL: the experiment accumulates until the keys are deleted by hand.
 */
final class RedisAlgorithmMetricsRepository implements AlgorithmMetricsRepositoryInterface
{
    private const KEY_PREFIX = 'ec-hub:ab-metrics:';
    private const SUBJECTS_SUFFIX = ':subjects';

    public function __construct(private readonly Client $client)
    {
    }

    public function record(string $algorithm, AlgorithmRequestSample $sample): void
    {
        $key = $this->key($algorithm);

        // MULTI/EXEC: the request and all its counters land together.
        $this->client->transaction(function (MultiExec $tx) use ($key, $sample): void {
            $tx->hincrby($key, 'requests', 1);
            $tx->hincrby($key, 'total_items', $sample->totalItems);
            $tx->hincrby($key, 'ml_items', $sample->mlItems);
            $tx->hincrbyfloat($key, 'score_sum', $sample->scoreSum);
            $tx->hincrby($key, 'scored_items', $sample->scoredItems);
            $tx->hincrbyfloat($key, 'response_time_sum_ms', $sample->responseTimeMs);
            if ($sample->subjectId !== null) {
                $tx->pfadd($key . self::SUBJECTS_SUFFIX, [$sample->subjectId]);
            }
        });
    }

    public function get(string $algorithm): AlgorithmMetrics
    {
        $key = $this->key($algorithm);
        /** @var array<string, string> $hash */
        $hash = $this->client->hgetall($key);
        $uniqueSubjects = (int) $this->client->pfcount($key . self::SUBJECTS_SUFFIX);

        return new AlgorithmMetrics(
            $algorithm,
            (int) ($hash['requests'] ?? 0),
            $uniqueSubjects,
            (int) ($hash['total_items'] ?? 0),
            (int) ($hash['ml_items'] ?? 0),
            (float) ($hash['score_sum'] ?? 0),
            (int) ($hash['scored_items'] ?? 0),
            (float) ($hash['response_time_sum_ms'] ?? 0),
        );
    }

    private function key(string $algorithm): string
    {
        if (trim($algorithm) === '') {
            throw new \InvalidArgumentException('Algorithm name must not be empty.');
        }

        return self::KEY_PREFIX . $algorithm;
    }
}
