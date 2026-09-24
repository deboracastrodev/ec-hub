<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

/**
 * Story 8.4: aggregated HTTP metrics of one method + route.
 *
 * $buckets holds the NON-cumulative count per duration bucket (keyed by the
 * BUCKETS label, "+Inf" included): each request increments only the smallest
 * bucket whose limit is >= its duration. cumulativeBuckets() gives the
 * Prometheus/JSON view. PHP turns the labels "1" and "5" into int array keys:
 * cast a key back with (string) before comparing it with BUCKETS.
 */
final readonly class HttpRouteMetrics
{
    /** Duration bucket limits, in seconds -- the single source for write and read. */
    public const BUCKETS = ['0.005', '0.01', '0.025', '0.05', '0.1', '0.2', '0.3', '0.5', '1', '2.5', '5', '+Inf'];
    public const INF_BUCKET = '+Inf';

    /**
     * @param array<int, int> $statuses status code => request count
     * @param array<int|string, int> $buckets bucket label => non-cumulative count
     */
    public function __construct(
        public string $method,
        public string $route,
        public array $statuses,
        public float $durationSumSeconds,
        public array $buckets,
    ) {
    }

    /** The label of the smallest bucket whose limit is >= $durationSeconds. */
    public static function bucketFor(float $durationSeconds): string
    {
        foreach (self::BUCKETS as $le) {
            if ($le !== self::INF_BUCKET && $durationSeconds <= (float) $le) {
                return $le;
            }
        }

        return self::INF_BUCKET;
    }

    public function requests(): int
    {
        return array_sum($this->statuses);
    }

    /** 5xx responses. */
    public function errors(): int
    {
        return $this->countStatuses(500, 599);
    }

    /** 4xx responses. */
    public function clientErrors(): int
    {
        return $this->countStatuses(400, 499);
    }

    /** @return array<int|string, int> bucket label => cumulative count, in BUCKETS order ("+Inf" last) */
    public function cumulativeBuckets(): array
    {
        $cumulative = [];
        $accumulated = 0;
        foreach (self::BUCKETS as $le) {
            $accumulated += $this->buckets[$le] ?? 0;
            $cumulative[$le] = $accumulated;
        }

        return $cumulative;
    }

    private function countStatuses(int $from, int $to): int
    {
        $count = 0;
        foreach ($this->statuses as $status => $requests) {
            if ($status >= $from && $status <= $to) {
                $count += $requests;
            }
        }

        return $count;
    }
}
