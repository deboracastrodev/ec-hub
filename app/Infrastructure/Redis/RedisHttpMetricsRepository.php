<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Application\Monitoring\HttpMetricsRepositoryInterface;
use App\Application\Monitoring\HttpRequestSample;
use App\Application\Monitoring\HttpRouteMetrics;
use Predis\Client;
use Predis\Transaction\MultiExec;

/**
 * Story 8.4: global HTTP metrics in a single Redis hash (no TTL).
 *
 * Fields:
 * - `requests|{METHOD}|{route}|{status}`       HINCRBY
 * - `duration_sum|{METHOD}|{route}`            HINCRBYFLOAT (seconds)
 * - `duration_bucket|{METHOD}|{route}|{le}`    HINCRBY, non-cumulative
 *
 * One hash keeps the read to a single HGETALL (no KEYS/SCAN). Reset with
 * `DEL ec-hub:http-metrics`.
 */
final class RedisHttpMetricsRepository implements HttpMetricsRepositoryInterface
{
    public const KEY = 'ec-hub:http-metrics';

    private const SEPARATOR = '|';

    public function __construct(
        private readonly Client $client,
        private readonly string $key = self::KEY,
    ) {
    }

    public function record(HttpRequestSample $sample): void
    {
        $prefix = $sample->method . self::SEPARATOR . $sample->route;
        $bucket = HttpRouteMetrics::bucketFor($sample->durationSeconds);

        // MULTI/EXEC: the count, the sum and the bucket land together.
        $this->client->transaction(function (MultiExec $tx) use ($prefix, $sample, $bucket): void {
            $tx->hincrby($this->key, 'requests|' . $prefix . self::SEPARATOR . $sample->status, 1);
            $tx->hincrbyfloat($this->key, 'duration_sum|' . $prefix, sprintf('%.9F', $sample->durationSeconds));
            $tx->hincrby($this->key, 'duration_bucket|' . $prefix . self::SEPARATOR . $bucket, 1);
        });
    }

    public function routes(): array
    {
        /** @var array<string, string> $hash */
        $hash = $this->client->hgetall($this->key);

        /** @var array<string, array{method: string, route: string, statuses: array<int, int>, sum: float, buckets: array<int|string, int>}> $routes */
        $routes = [];
        foreach ($hash as $field => $value) {
            $this->accumulate($routes, (string) $field, (string) $value);
        }

        uasort($routes, static fn (array $left, array $right): int => [$left['route'], $left['method']] <=> [$right['route'], $right['method']]);

        $result = [];
        foreach ($routes as $route) {
            ksort($route['statuses']);
            $result[] = new HttpRouteMetrics($route['method'], $route['route'], $route['statuses'], $route['sum'], $route['buckets']);
        }

        return $result;
    }

    /**
     * Folds one hash field into $routes. Malformed fields are ignored.
     *
     * @param array<string, array{method: string, route: string, statuses: array<int, int>, sum: float, buckets: array<int|string, int>}> $routes
     */
    private function accumulate(array &$routes, string $field, string $value): void
    {
        $parts = explode(self::SEPARATOR, $field);
        $kind = array_shift($parts);
        $method = array_shift($parts);
        if ($method === null || ! $this->isKnownMethod($method) || ! is_numeric($value)) {
            return;
        }
        // A route that is not valid UTF-8 would make the JSON export fail as a whole.
        if (! mb_check_encoding(implode(self::SEPARATOR, $parts), 'UTF-8')) {
            return;
        }

        switch ($kind) {
            case 'requests':
                $status = array_pop($parts);
                if (count($parts) === 0 || $status === null || preg_match('/^[1-5]\d\d$/', $status) !== 1 || ! $this->isCount($value)) {
                    return;
                }
                $entry = &$this->entry($routes, $method, implode(self::SEPARATOR, $parts));
                $entry['statuses'][(int) $status] = ($entry['statuses'][(int) $status] ?? 0) + (int) $value;

                return;
            case 'duration_sum':
                $sum = (float) $value;
                if (count($parts) === 0 || ! is_finite($sum) || $sum < 0) {
                    return;
                }
                $entry = &$this->entry($routes, $method, implode(self::SEPARATOR, $parts));
                $entry['sum'] += $sum;

                return;
            case 'duration_bucket':
                $le = array_pop($parts);
                if (count($parts) === 0 || ! in_array($le, HttpRouteMetrics::BUCKETS, true) || ! $this->isCount($value)) {
                    return;
                }
                $entry = &$this->entry($routes, $method, implode(self::SEPARATOR, $parts));
                $entry['buckets'][$le] = ($entry['buckets'][$le] ?? 0) + (int) $value;

                return;
        }
    }

    /**
     * @param array<string, array{method: string, route: string, statuses: array<int, int>, sum: float, buckets: array<int|string, int>}> $routes
     * @return array{method: string, route: string, statuses: array<int, int>, sum: float, buckets: array<int|string, int>}
     */
    private function &entry(array &$routes, string $method, string $route): array
    {
        $id = $method . self::SEPARATOR . $route;
        $routes[$id] ??= ['method' => $method, 'route' => $route, 'statuses' => [], 'sum' => 0.0, 'buckets' => []];

        return $routes[$id];
    }

    private function isKnownMethod(string $method): bool
    {
        return in_array($method, [...HttpRequestSample::METHODS, HttpRequestSample::OTHER_METHOD], true);
    }

    private function isCount(string $value): bool
    {
        return preg_match('/^\d+$/', $value) === 1;
    }
}
