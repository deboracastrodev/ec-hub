<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Monitoring\ExportMetrics;
use App\Application\Monitoring\HttpRequestSample;
use App\Application\Monitoring\MemorySnapshot;
use App\Application\Monitoring\PrometheusFormatter;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\MetricsExportController;
use App\Domain\Event\EventBusStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryHttpMetricsRepository;

final class MetricsExportControllerTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function jsonQueries(): iterable
    {
        yield 'absent' => [[]];
        yield 'empty' => [['format' => '']];
        yield 'blank' => [['format' => '  ']];
        yield 'json' => [['format' => 'json']];
        yield 'trimmed upper case' => [['format' => 'JSON ']];
    }

    /** @param array<string, mixed> $query */
    #[DataProvider('jsonQueries')]
    public function testJsonIsTheDefault(array $query): void
    {
        $response = $this->controller()->export($query, [], null);

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertArrayNotHasKey('X-Frame-Options', $response->headers);

        $decoded = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['data', 'meta'], array_keys($decoded));
        self::assertSame(
            ['requests', 'errors', 'response_times', 'memory', 'recommendations', 'event_bus'],
            array_keys($decoded['data'])
        );
        self::assertSame(1, $decoded['data']['requests']['total']);
        self::assertSame(['200' => 1], $decoded['data']['requests']['routes'][0]['statuses']);
        self::assertStringContainsString('"route": "/api/metrics"', $response->body, 'slashes are not escaped');
    }

    public function testPrometheus(): void
    {
        $response = $this->controller()->export(['format' => ' Prometheus'], [], null);

        self::assertSame(200, $response->status);
        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $response->headers['Content-Type']);
        self::assertSame('no-store', $response->headers['Cache-Control']);
        self::assertStringContainsString("ec_hub_http_requests_total{method=\"GET\",route=\"/api/metrics\",status=\"200\"} 1\n", $response->body);
        self::assertStringEndsWith("\n", $response->body);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidFormats(): iterable
    {
        yield 'xml' => ['xml'];
        yield 'array' => [['json']];
        yield 'int' => [1];
    }

    #[DataProvider('invalidFormats')]
    public function testInvalidFormatIs400(mixed $format): void
    {
        try {
            $this->controller()->export(['format' => $format], [], null);
            self::fail('InvalidRequestException expected');
        } catch (InvalidRequestException $exception) {
            self::assertSame(400, $exception->getHttpCode());
        }
    }

    private function controller(): MetricsExportController
    {
        $repository = new InMemoryHttpMetricsRepository();
        $repository->record(new HttpRequestSample('GET', '/api/metrics', 200, 0.01));

        return new MetricsExportController(
            new ExportMetrics(
                fn (): array => $repository->routes(),
                fn () => new MemorySnapshot(1000, 2000, 1.0, false),
                fn (): array => ['enabled' => false, 'variants' => null, 'algorithms' => []],
                fn () => new EventBusStatus(true, 1),
            ),
            new PrometheusFormatter()
        );
    }
}
