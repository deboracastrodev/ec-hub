<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\ExportMetrics;
use App\Application\Monitoring\HttpRequestSample;
use App\Application\Monitoring\MemorySnapshot;
use App\Application\Monitoring\PrometheusFormatter;
use App\Domain\Event\EventBusStatus;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryHttpMetricsRepository;

final class PrometheusFormatterTest extends TestCase
{
    private const SAMPLE_LINE = '/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{[a-zA-Z_][a-zA-Z0-9_]*="(?:[^"\\\\\n]|\\\\["\\\\n])*"(?:,[a-zA-Z_][a-zA-Z0-9_]*="(?:[^"\\\\\n]|\\\\["\\\\n])*")*\})? -?[0-9.eE+-]+$/';

    private const RECOMMENDATIONS = [
        'enabled' => true,
        'variants' => ['A' => 'knn', 'B' => 'collaborative'],
        'algorithms' => [
            [
                'algorithm' => 'knn',
                'variant' => 'A',
                'requests' => 4,
                'unique_subjects' => 2,
                'total_items' => 20,
                'ml_items' => 15,
                'ml_item_rate' => 75.0,
                'avg_response_time_ms' => 12.5,
                'avg_score' => 80.0,
            ],
            [
                'algorithm' => 'collaborative',
                'variant' => 'B',
                'requests' => 0,
                'unique_subjects' => 0,
                'total_items' => 0,
                'ml_items' => 0,
                'ml_item_rate' => null,
                'avg_response_time_ms' => null,
                'avg_score' => null,
            ],
        ],
    ];

    public function testGoldenText(): void
    {
        $repository = new InMemoryHttpMetricsRepository();
        $repository->record(new HttpRequestSample('GET', '/products', 200, 0.004));
        $repository->record(new HttpRequestSample('GET', '/products', 200, 0.03));
        $repository->record(new HttpRequestSample('GET', '/products', 500, 7.0));

        $text = (new PrometheusFormatter())->format($this->collect($repository));

        $expected = <<<'PROM'
            # HELP ec_hub_http_requests_total HTTP requests by method, route and status code.
            # TYPE ec_hub_http_requests_total counter
            ec_hub_http_requests_total{method="GET",route="/products",status="200"} 2
            ec_hub_http_requests_total{method="GET",route="/products",status="500"} 1
            # HELP ec_hub_http_errors_total HTTP 5xx responses by method and route.
            # TYPE ec_hub_http_errors_total counter
            ec_hub_http_errors_total{method="GET",route="/products"} 1
            # HELP ec_hub_http_request_duration_seconds HTTP request duration in seconds.
            # TYPE ec_hub_http_request_duration_seconds histogram
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.005"} 1
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.01"} 1
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.025"} 1
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.05"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.1"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.2"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.3"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="0.5"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="1"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="2.5"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="5"} 2
            ec_hub_http_request_duration_seconds_bucket{method="GET",route="/products",le="+Inf"} 3
            ec_hub_http_request_duration_seconds_sum{method="GET",route="/products"} 7.034
            ec_hub_http_request_duration_seconds_count{method="GET",route="/products"} 3
            # HELP ec_hub_memory_usage_bytes Memory in use by the PHP process serving the scrape.
            # TYPE ec_hub_memory_usage_bytes gauge
            ec_hub_memory_usage_bytes 1048576
            # HELP ec_hub_memory_peak_usage_bytes Peak memory of the PHP process serving the scrape.
            # TYPE ec_hub_memory_peak_usage_bytes gauge
            ec_hub_memory_peak_usage_bytes 2097152
            # HELP ec_hub_memory_growth_percent Memory growth over the request baseline, in percent.
            # TYPE ec_hub_memory_growth_percent gauge
            ec_hub_memory_growth_percent 3.25
            # HELP ec_hub_recommendation_requests_total Recommendation requests served, by algorithm.
            # TYPE ec_hub_recommendation_requests_total counter
            ec_hub_recommendation_requests_total{algorithm="knn"} 4
            ec_hub_recommendation_requests_total{algorithm="collaborative"} 0
            # HELP ec_hub_recommendation_items_total Recommended items served, by algorithm.
            # TYPE ec_hub_recommendation_items_total counter
            ec_hub_recommendation_items_total{algorithm="knn"} 20
            ec_hub_recommendation_items_total{algorithm="collaborative"} 0
            # HELP ec_hub_recommendation_ml_items_total Recommended items that came from ML, by algorithm.
            # TYPE ec_hub_recommendation_ml_items_total counter
            ec_hub_recommendation_ml_items_total{algorithm="knn"} 15
            ec_hub_recommendation_ml_items_total{algorithm="collaborative"} 0
            # HELP ec_hub_recommendation_response_time_seconds Recommendation response time, by algorithm (no quantiles). _sum is derived from the rounded average and may dip slightly; use _sum/_count, not rate(_sum).
            # TYPE ec_hub_recommendation_response_time_seconds summary
            ec_hub_recommendation_response_time_seconds_sum{algorithm="knn"} 0.05
            ec_hub_recommendation_response_time_seconds_count{algorithm="knn"} 4
            ec_hub_recommendation_response_time_seconds_sum{algorithm="collaborative"} 0
            ec_hub_recommendation_response_time_seconds_count{algorithm="collaborative"} 0
            # HELP ec_hub_event_bus_connected Whether the event bus is connected (1) or not (0).
            # TYPE ec_hub_event_bus_connected gauge
            ec_hub_event_bus_connected 1
            # HELP ec_hub_events_published_total Events published on the event bus.
            # TYPE ec_hub_events_published_total counter
            ec_hub_events_published_total 42
            # HELP ec_hub_metrics_source_up Whether each metrics source could be read (1) or not (0).
            # TYPE ec_hub_metrics_source_up gauge
            ec_hub_metrics_source_up{source="http"} 1
            ec_hub_metrics_source_up{source="memory"} 1
            ec_hub_metrics_source_up{source="recommendations"} 1
            ec_hub_metrics_source_up{source="event_bus"} 1

            PROM;

        self::assertSame($expected, $text);
        $this->assertValidExposition($text);
    }

    public function testHistogramIsCumulativeAndInfEqualsCount(): void
    {
        $repository = new InMemoryHttpMetricsRepository();
        foreach ([0.001, 0.02, 0.02, 0.4, 3.0, 9.0] as $duration) {
            $repository->record(new HttpRequestSample('POST', '/api/events', 201, $duration));
        }

        $text = (new PrometheusFormatter())->format($this->collect($repository));

        preg_match_all('/^ec_hub_http_request_duration_seconds_bucket\{[^}]*le="([^"]+)"\} (\d+)$/m', $text, $matches);
        $counts = array_map('intval', $matches[2]);
        self::assertSame(['0.005', '0.01', '0.025', '0.05', '0.1', '0.2', '0.3', '0.5', '1', '2.5', '5', '+Inf'], $matches[1]);
        $sorted = $counts;
        sort($sorted);
        self::assertSame($sorted, $counts, 'buckets must be cumulative');
        self::assertMatchesRegularExpression('/^ec_hub_http_request_duration_seconds_count\{method="POST",route="\/api\/events"\} 6$/m', $text);
        self::assertSame(6, end($counts));
    }

    public function testLabelValuesAreEscaped(): void
    {
        $repository = new InMemoryHttpMetricsRepository();
        $repository->record(new HttpRequestSample('GET', "a\"b\\c\nd", 200, 0.001));

        $text = (new PrometheusFormatter())->format($this->collect($repository));

        self::assertStringContainsString('ec_hub_http_requests_total{method="GET",route="a\"b\\\\c\nd",status="200"} 1', $text);
        $this->assertValidExposition($text);
    }

    public function testAFailingSourceOnlyOmitsItsOwnFamilies(): void
    {
        $collected = (new ExportMetrics(
            static fn () => throw new \RuntimeException('Redis down'),
            fn () => new MemorySnapshot(1048576, 2097152, 3.25, false),
            static fn () => throw new \RuntimeException('invalid A/B'),
            fn () => new EventBusStatus(false, 0),
        ))->collect();

        $text = (new PrometheusFormatter())->format($collected);

        self::assertStringNotContainsString('ec_hub_http_', $text);
        self::assertStringNotContainsString('ec_hub_recommendation_', $text);
        self::assertStringContainsString("ec_hub_memory_usage_bytes 1048576\n", $text);
        self::assertStringContainsString("ec_hub_event_bus_connected 0\n", $text);
        self::assertStringContainsString("ec_hub_metrics_source_up{source=\"http\"} 0\n", $text);
        self::assertStringContainsString("ec_hub_metrics_source_up{source=\"recommendations\"} 0\n", $text);
        self::assertStringContainsString("ec_hub_metrics_source_up{source=\"memory\"} 1\n", $text);
        $this->assertValidExposition($text);
    }

    public function testNoDataOnlyEmitsTheHttpFamilyHeaders(): void
    {
        $text = (new PrometheusFormatter())->format($this->collect(new InMemoryHttpMetricsRepository()));

        self::assertStringContainsString("# TYPE ec_hub_http_requests_total counter\n# HELP ec_hub_http_errors_total", $text);
        self::assertStringContainsString("# TYPE ec_hub_http_errors_total counter\n# HELP ec_hub_http_request_duration_seconds", $text);
        self::assertStringContainsString("# TYPE ec_hub_http_request_duration_seconds histogram\n# HELP ec_hub_memory_usage_bytes", $text);
        self::assertDoesNotMatchRegularExpression('/^ec_hub_http_/m', $text);
        $this->assertValidExposition($text);
    }

    public function testNumbersNeverUseNanInfOrExponent(): void
    {
        self::assertSame('0', PrometheusFormatter::number(NAN));
        self::assertSame('0', PrometheusFormatter::number(INF));
        self::assertSame('0', PrometheusFormatter::number(-INF));
        self::assertSame('0.0000001', PrometheusFormatter::number(1.0E-7));
        self::assertSame('12', PrometheusFormatter::number(12.0));
        self::assertSame('-3.5', PrometheusFormatter::number(-3.5));
        self::assertSame('0', PrometheusFormatter::number(-0.0));
        self::assertSame('1', PrometheusFormatter::number(true));
    }

    /** @return array<string, mixed> */
    private function collect(InMemoryHttpMetricsRepository $repository): array
    {
        return (new ExportMetrics(
            fn (): array => $repository->routes(),
            fn () => new MemorySnapshot(1048576, 2097152, 3.25, false),
            fn (): array => self::RECOMMENDATIONS,
            fn () => new EventBusStatus(true, 42),
        ))->collect();
    }

    private function assertValidExposition(string $text): void
    {
        self::assertStringEndsWith("\n", $text);
        $families = [];
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if (str_starts_with($line, '# TYPE ')) {
                $name = explode(' ', $line)[2];
                self::assertArrayNotHasKey($name, $families, "TYPE repeated for {$name}");
                $families[$name] = true;

                continue;
            }
            if (str_starts_with($line, '# HELP ')) {
                continue;
            }
            self::assertMatchesRegularExpression(self::SAMPLE_LINE, $line);
            self::assertStringNotContainsStringIgnoringCase('nan', explode('} ', $line)[1] ?? $line);
        }
    }
}
