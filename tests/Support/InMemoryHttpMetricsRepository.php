<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Monitoring\HttpMetricsRepositoryInterface;
use App\Application\Monitoring\HttpRequestSample;
use App\Application\Monitoring\HttpRouteMetrics;

/**
 * In-memory HttpMetricsRepositoryInterface fake (Story 8.4). Can be told to
 * fail on writes and/or reads to simulate Redis being unavailable.
 */
final class InMemoryHttpMetricsRepository implements HttpMetricsRepositoryInterface
{
    /** @var list<HttpRequestSample> */
    public array $recorded = [];

    public function __construct(
        private readonly bool $failOnRecord = false,
        private readonly bool $failOnRead = false,
    ) {
    }

    public function record(HttpRequestSample $sample): void
    {
        if ($this->failOnRecord) {
            throw new \RuntimeException('Redis indisponível.');
        }

        $this->recorded[] = $sample;
    }

    public function routes(): array
    {
        if ($this->failOnRead) {
            throw new \RuntimeException('Redis indisponível.');
        }

        /** @var array<string, array{method: string, route: string, statuses: array<int, int>, sum: float, buckets: array<int|string, int>}> $routes */
        $routes = [];
        foreach ($this->recorded as $sample) {
            $id = $sample->route . "\0" . $sample->method;
            $routes[$id] ??= ['method' => $sample->method, 'route' => $sample->route, 'statuses' => [], 'sum' => 0.0, 'buckets' => []];
            $routes[$id]['statuses'][$sample->status] = ($routes[$id]['statuses'][$sample->status] ?? 0) + 1;
            $routes[$id]['sum'] += $sample->durationSeconds;
            $bucket = HttpRouteMetrics::bucketFor($sample->durationSeconds);
            $routes[$id]['buckets'][$bucket] = ($routes[$id]['buckets'][$bucket] ?? 0) + 1;
        }
        ksort($routes);

        $result = [];
        foreach ($routes as $route) {
            ksort($route['statuses']);
            $result[] = new HttpRouteMetrics($route['method'], $route['route'], $route['statuses'], $route['sum'], $route['buckets']);
        }

        return $result;
    }
}
