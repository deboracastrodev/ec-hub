<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\ExportMetrics;
use App\Application\Monitoring\HttpRequestSample;
use App\Application\Monitoring\MemorySnapshot;
use App\Domain\Event\EventBusStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryHttpMetricsRepository;

final class ExportMetricsTest extends TestCase
{
    private const RECOMMENDATIONS = [
        'enabled' => false,
        'variants' => null,
        'algorithms' => [[
            'algorithm' => 'knn',
            'variant' => null,
            'requests' => 2,
            'unique_subjects' => 1,
            'total_items' => 10,
            'ml_items' => 8,
            'ml_item_rate' => 80.0,
            'avg_response_time_ms' => 12.5,
            'avg_score' => 70.0,
        ]],
    ];

    public function testAllSourcesUp(): void
    {
        $repository = new InMemoryHttpMetricsRepository();
        $repository->record(new HttpRequestSample('GET', '/products', 200, 0.004));
        $repository->record(new HttpRequestSample('GET', '/products', 200, 0.030));
        $repository->record(new HttpRequestSample('GET', '/products/{param}', 404, 0.020));
        $repository->record(new HttpRequestSample('GET', '/api/recommendations', 500, 1.200));

        $collected = $this->export($repository)->collect();
        $data = $collected['data'];

        self::assertSame(['http' => true, 'memory' => true, 'recommendations' => true, 'event_bus' => true], $collected['meta']['sources']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $collected['meta']['generated_at']));

        self::assertSame(4, $data['requests']['total']);
        self::assertSame(['2xx' => 2, '3xx' => 0, '4xx' => 1, '5xx' => 1], $data['requests']['by_status_class']);
        self::assertSame(['/api/recommendations', '/products', '/products/{param}'], array_column($data['requests']['routes'], 'route'));
        self::assertSame([
            'method' => 'GET',
            'route' => '/products',
            'requests' => 2,
            'errors' => 0,
            'client_errors' => 0,
            'statuses' => [200 => 2],
            'avg_response_time_ms' => 17.0,
        ], $data['requests']['routes'][1]);
        self::assertSame(1, $data['requests']['routes'][0]['errors']);
        self::assertSame(1, $data['requests']['routes'][2]['client_errors']);

        self::assertSame(['total' => 1, 'client_total' => 1, 'error_rate_percent' => 25.0], $data['errors']);

        self::assertSame(4, $data['response_times']['count']);
        self::assertSame(313.5, $data['response_times']['avg_ms']);
        self::assertSame(
            [5, 10, 25, 50, 100, 200, 300, 500, 1000, 2500, 5000, null],
            array_column($data['response_times']['buckets'], 'le_ms')
        );
        self::assertSame([1, 1, 2, 3, 3, 3, 3, 3, 3, 4, 4, 4], array_column($data['response_times']['buckets'], 'count'));

        self::assertSame(['current_usage_bytes' => 1000, 'peak_usage_bytes' => 2000, 'growth_percent' => 5.0, 'alert' => false], $data['memory']);
        self::assertSame(self::RECOMMENDATIONS, $data['recommendations']);
        self::assertSame(['connected' => true, 'published_count' => 42], $data['event_bus']);

        self::assertCount(3, $collected['histograms']);
        self::assertSame(2, $collected['histograms'][1]['count']);
        self::assertSame(2, $collected['histograms'][1]['buckets']['+Inf']);
    }

    public function testNoData(): void
    {
        $collected = $this->export(new InMemoryHttpMetricsRepository())->collect();
        $data = $collected['data'];

        self::assertSame(0, $data['requests']['total']);
        self::assertSame([], $data['requests']['routes']);
        self::assertSame(['total' => 0, 'client_total' => 0, 'error_rate_percent' => null], $data['errors']);
        self::assertSame(0, $data['response_times']['count']);
        self::assertNull($data['response_times']['avg_ms']);
        self::assertSame(array_fill(0, 12, 0), array_column($data['response_times']['buckets'], 'count'));
        self::assertTrue($collected['meta']['sources']['http']);
    }

    /** @return iterable<string, array{string}> */
    public static function sources(): iterable
    {
        foreach (ExportMetrics::SOURCES as $source) {
            yield $source => [$source];
        }
    }

    #[DataProvider('sources')]
    public function testAFailingSourceOnlyNullsItsOwnSection(string $failing): void
    {
        $fail = static fn () => throw new \RuntimeException('down');
        $export = new ExportMetrics(
            $failing === 'http' ? $fail : fn (): array => (new InMemoryHttpMetricsRepository())->routes(),
            $failing === 'memory' ? $fail : fn () => new MemorySnapshot(1000, 2000, 5.0, false),
            $failing === 'recommendations' ? $fail : fn (): array => self::RECOMMENDATIONS,
            $failing === 'event_bus' ? $fail : fn () => new EventBusStatus(true, 42),
        );

        $collected = $export->collect();

        $sections = [
            'http' => ['requests', 'errors', 'response_times'],
            'memory' => ['memory'],
            'recommendations' => ['recommendations'],
            'event_bus' => ['event_bus'],
        ];
        foreach ($sections as $source => $keys) {
            self::assertSame($source !== $failing, $collected['meta']['sources'][$source], $source);
            foreach ($keys as $key) {
                if ($source === $failing) {
                    self::assertNull($collected['data'][$key], $key);
                } else {
                    self::assertNotNull($collected['data'][$key], $key);
                }
            }
        }
        self::assertSame($failing === 'http', $collected['histograms'] === null);
    }

    public function testAnInvalidHttpSourceResultCountsAsFailure(): void
    {
        $export = new ExportMetrics(
            fn (): array => ['not a route'],
            fn () => new MemorySnapshot(1000, 2000, 5.0, false),
            fn (): array => self::RECOMMENDATIONS,
            fn () => new EventBusStatus(true, 42),
        );

        $collected = $export->collect();

        self::assertFalse($collected['meta']['sources']['http']);
        self::assertNull($collected['data']['requests']);
    }

    private function export(InMemoryHttpMetricsRepository $repository): ExportMetrics
    {
        return new ExportMetrics(
            fn (): array => $repository->routes(),
            fn () => new MemorySnapshot(1000, 2000, 5.0, false),
            fn (): array => self::RECOMMENDATIONS,
            fn () => new EventBusStatus(true, 42),
        );
    }
}
