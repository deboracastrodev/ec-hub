<?php

declare(strict_types=1);

namespace App\Application\Monitoring;

use App\Domain\Event\EventBusStatus;
use Closure;
use Throwable;

/**
 * Story 8.4: aggregates the existing metric sources for GET /api/metrics.
 *
 * Sources are lazy closures (same pattern as MetricsController): each one is
 * read inside its own try/catch, so a failing source only nulls its own
 * section and flips meta.sources.{source} to false.
 *
 * - http: HttpMetricsRepositoryInterface::routes()
 * - memory: MemoryMonitor::snapshot() -- memory of the export request itself
 * - recommendations: RecommendationExperiment::results() (contract unchanged)
 * - event_bus: EventBusStatusInterface::status()
 */
final readonly class ExportMetrics
{
    public const SOURCES = ['http', 'memory', 'recommendations', 'event_bus'];

    /**
     * @param Closure(): list<HttpRouteMetrics> $httpRoutes
     * @param Closure(): MemorySnapshot $memory
     * @param Closure(): array<string, mixed> $recommendations
     * @param Closure(): EventBusStatus $eventBus
     */
    public function __construct(
        private Closure $httpRoutes,
        private Closure $memory,
        private Closure $recommendations,
        private Closure $eventBus,
    ) {
    }

    /**
     * `data` and `meta` are the JSON document. `histograms` carries the
     * per-route duration histograms the Prometheus format needs (null when
     * the http source is down); it is not part of the JSON document.
     *
     * @return array{
     *     data: array{requests: array<string, mixed>|null, errors: array<string, mixed>|null, response_times: array<string, mixed>|null, memory: array<string, mixed>|null, recommendations: array<string, mixed>|null, event_bus: array{connected: bool, published_count: int}|null},
     *     meta: array{generated_at: string, sources: array{http: bool, memory: bool, recommendations: bool, event_bus: bool}},
     *     histograms: list<array{method: string, route: string, sum_seconds: float, count: int, buckets: array<int|string, int>}>|null
     * }
     */
    public function collect(): array
    {
        $routes = $this->read(fn (): array => $this->validRoutes(($this->httpRoutes)()));
        $memory = $this->read(fn (): array => $this->memorySnapshot(($this->memory)()));
        $recommendations = $this->read(fn (): array => ($this->recommendations)());
        $eventBus = $this->read(fn (): array => $this->eventBusStatus(($this->eventBus)()));

        return [
            'data' => [
                'requests' => $routes === null ? null : $this->requests($routes),
                'errors' => $routes === null ? null : $this->errors($routes),
                'response_times' => $routes === null ? null : $this->responseTimes($routes),
                'memory' => $memory,
                'recommendations' => $recommendations,
                'event_bus' => $eventBus,
            ],
            'meta' => [
                'generated_at' => date('c'),
                'sources' => [
                    'http' => $routes !== null,
                    'memory' => $memory !== null,
                    'recommendations' => $recommendations !== null,
                    'event_bus' => $eventBus !== null,
                ],
            ],
            'histograms' => $routes === null ? null : $this->histograms($routes),
        ];
    }

    /**
     * @param Closure(): array<mixed> $source
     * @return array<mixed>|null
     */
    private function read(Closure $source): ?array
    {
        try {
            return $source();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<mixed> $routes
     * @return list<HttpRouteMetrics>
     */
    private function validRoutes(array $routes): array
    {
        foreach ($routes as $route) {
            if (! $route instanceof HttpRouteMetrics) {
                throw new \UnexpectedValueException('HTTP metrics source returned an invalid route.');
            }
        }

        return array_values($routes);
    }

    /** @return array<string, mixed> */
    private function memorySnapshot(MemorySnapshot $snapshot): array
    {
        return $snapshot->toArray();
    }

    /** @return array{connected: bool, published_count: int} */
    private function eventBusStatus(EventBusStatus $status): array
    {
        return ['connected' => $status->connected, 'published_count' => $status->publishedCount];
    }

    /**
     * @param list<HttpRouteMetrics> $routes
     * @return array<string, mixed>
     */
    private function requests(array $routes): array
    {
        $byClass = ['2xx' => 0, '3xx' => 0, '4xx' => 0, '5xx' => 0];
        $rows = [];
        $total = 0;
        foreach ($routes as $route) {
            $statuses = [];
            foreach ($route->statuses as $status => $count) {
                $class = intdiv($status, 100) . 'xx';
                if (isset($byClass[$class])) {
                    $byClass[$class] += $count;
                }
                $statuses[(string) $status] = $count;
            }
            $requests = $route->requests();
            $total += $requests;
            $rows[] = [
                'method' => $route->method,
                'route' => $route->route,
                'requests' => $requests,
                'errors' => $route->errors(),
                'client_errors' => $route->clientErrors(),
                'statuses' => $statuses,
                'avg_response_time_ms' => $this->averageMs($route->durationSumSeconds, $requests),
            ];
        }

        return ['total' => $total, 'by_status_class' => $byClass, 'routes' => $rows];
    }

    /**
     * @param list<HttpRouteMetrics> $routes
     * @return array{total: int, client_total: int, error_rate_percent: float|null}
     */
    private function errors(array $routes): array
    {
        $total = 0;
        $errors = 0;
        $clientErrors = 0;
        foreach ($routes as $route) {
            $total += $route->requests();
            $errors += $route->errors();
            $clientErrors += $route->clientErrors();
        }

        return [
            'total' => $errors,
            'client_total' => $clientErrors,
            'error_rate_percent' => $total === 0 ? null : round($errors / $total * 100, 2),
        ];
    }

    /**
     * @param list<HttpRouteMetrics> $routes
     * @return array{count: int, avg_ms: float|null, buckets: list<array{le_ms: int|null, count: int}>}
     */
    private function responseTimes(array $routes): array
    {
        $count = 0;
        $sum = 0.0;
        $cumulative = array_fill_keys(HttpRouteMetrics::BUCKETS, 0);
        foreach ($routes as $route) {
            $count += $route->requests();
            $sum += $route->durationSumSeconds;
            foreach ($route->cumulativeBuckets() as $le => $bucketCount) {
                $cumulative[$le] += $bucketCount;
            }
        }

        $buckets = [];
        foreach ($cumulative as $le => $bucketCount) {
            $le = (string) $le;
            $buckets[] = [
                'le_ms' => $le === HttpRouteMetrics::INF_BUCKET ? null : (int) round((float) $le * 1000),
                'count' => $bucketCount,
            ];
        }

        return ['count' => $count, 'avg_ms' => $this->averageMs($sum, $count), 'buckets' => $buckets];
    }

    /**
     * @param list<HttpRouteMetrics> $routes
     * @return list<array{method: string, route: string, sum_seconds: float, count: int, buckets: array<int|string, int>}>
     */
    private function histograms(array $routes): array
    {
        $histograms = [];
        foreach ($routes as $route) {
            $buckets = $route->cumulativeBuckets();
            $histograms[] = [
                'method' => $route->method,
                'route' => $route->route,
                'sum_seconds' => $route->durationSumSeconds,
                'count' => $buckets[HttpRouteMetrics::INF_BUCKET],
                'buckets' => $buckets,
            ];
        }

        return $histograms;
    }

    private function averageMs(float $sumSeconds, int $count): ?float
    {
        return $count === 0 ? null : round($sumSeconds / $count * 1000, 2);
    }
}
