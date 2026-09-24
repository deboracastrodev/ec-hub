<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\ExportMetrics;
use App\Application\Monitoring\HealthCheck;
use App\Application\Monitoring\HttpRequestRecorder;
use App\Application\Monitoring\MemorySnapshot;
use App\Application\Monitoring\PrometheusFormatter;
use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Controller\AbTestResultsController;
use App\Controller\HealthCheckController;
use App\Controller\MetricsExportController;
use App\Domain\Event\EventBusStatus;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use App\Shared\Container\Container;
use PDO;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryAlgorithmMetricsRepository;
use Tests\Support\InMemoryHttpMetricsRepository;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Story 8.4: routed requests through public/index.php feed the HTTP metrics,
 * and GET /api/metrics (JSON and Prometheus) reflects them.
 */
final class MetricsExportHttpEndpointTest extends TestCase
{
    private InMemoryHttpMetricsRepository $repository;

    #[RunInSeparateProcess]
    public function testRoutedRequestsFeedTheJsonAndPrometheusExports(): void
    {
        $this->repository = new InMemoryHttpMetricsRepository();

        $this->dispatch('/health', []);
        self::assertSame(200, http_response_code());
        $this->dispatch('/health', []);
        $this->dispatch('/health', ['format' => 'ignored']);
        $this->dispatch('/api/ab-tests/results', []);
        self::assertSame(500, http_response_code());

        $body = $this->dispatch('/api/metrics', []);
        self::assertSame(200, http_response_code());
        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(4, $json['data']['requests']['total']);
        self::assertSame(['2xx' => 3, '3xx' => 0, '4xx' => 0, '5xx' => 1], $json['data']['requests']['by_status_class']);
        $routes = [];
        foreach ($json['data']['requests']['routes'] as $route) {
            $routes[$route['method'] . ' ' . $route['route']] = $route['statuses'];
        }
        self::assertSame([
            'GET /api/ab-tests/results' => ['500' => 1],
            'GET /health' => ['200' => 3],
        ], $routes);
        self::assertEquals(['total' => 1, 'client_total' => 0, 'error_rate_percent' => 25.0], $json['data']['errors']);
        self::assertSame(4, $json['data']['response_times']['count']);
        self::assertSame(['http' => true, 'memory' => true, 'recommendations' => true, 'event_bus' => true], $json['meta']['sources']);

        // The JSON export request itself was recorded once its response was sent.
        $text = $this->dispatch('/api/metrics', ['format' => 'prometheus']);
        self::assertSame(200, http_response_code());
        self::assertStringStartsWith('# HELP ec_hub_http_requests_total', $text);
        self::assertStringContainsString("ec_hub_http_requests_total{method=\"GET\",route=\"/health\",status=\"200\"} 3\n", $text);
        self::assertStringContainsString("ec_hub_http_requests_total{method=\"GET\",route=\"/api/metrics\",status=\"200\"} 1\n", $text);
        self::assertStringContainsString("ec_hub_http_errors_total{method=\"GET\",route=\"/api/ab-tests/results\"} 1\n", $text);
        self::assertStringContainsString("ec_hub_http_request_duration_seconds_count{method=\"GET\",route=\"/health\"} 3\n", $text);
        self::assertStringContainsString("ec_hub_memory_usage_bytes 1000\n", $text);

        self::assertCount(6, $this->repository->recorded);
    }

    /**
     * The unmatched 404 ends with `exit`, so it runs in its own PHP process;
     * a shutdown function prints what was recorded.
     */
    public function testAnUnmatchedRequestIsCountedAsUnmatched404(): void
    {
        $root = dirname(__DIR__, 3);
        $script = <<<'PHP'
            <?php
            require $argv[1] . '/vendor/autoload.php';
            $repository = new Tests\Support\InMemoryHttpMetricsRepository();
            $twig = new Twig\Environment(new Twig\Loader\FilesystemLoader($argv[1] . '/views'));
            $GLOBALS['EC_HUB_TEST_CONTAINER'] = new App\Shared\Container\Container([
                Twig\Environment::class => fn () => $twig,
                App\Application\Monitoring\HttpRequestRecorder::class => fn () => new App\Application\Monitoring\HttpRequestRecorder($repository, new Psr\Log\NullLogger()),
            ]);
            $_SERVER['REQUEST_METHOD'] = 'FOO';
            $_SERVER['REQUEST_URI'] = '/nao-existe?x=1';
            register_shutdown_function(static function () use ($repository): void {
                $samples = array_map(static fn ($s): array => [$s->method, $s->route, $s->status], $repository->recorded);
                echo "\n@@" . json_encode($samples);
            });
            require $argv[1] . '/public/index.php';
            PHP;
        $file = tempnam(sys_get_temp_dir(), 'ec-hub-404-');
        file_put_contents($file, $script);

        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1', $output, $exitCode);
        } finally {
            unlink($file);
        }

        $out = implode("\n", $output);
        self::assertSame(0, $exitCode, $out);
        self::assertStringContainsString('Página não encontrada', $out);
        self::assertSame([['OTHER', 'unmatched', 404]], json_decode(substr($out, (int) strrpos($out, '@@') + 2), true), $out);
    }

    #[RunInSeparateProcess]
    public function testAnInvalidFormatIsA400Json(): void
    {
        $this->repository = new InMemoryHttpMetricsRepository();

        $body = $this->dispatch('/api/metrics', ['format' => 'xml']);

        self::assertSame(400, http_response_code());
        self::assertSame(400, json_decode($body, true, 512, JSON_THROW_ON_ERROR)['code']);
        self::assertSame(400, $this->repository->recorded[0]->status);
        self::assertSame('/api/metrics', $this->repository->recorded[0]->route);
    }

    #[RunInSeparateProcess]
    public function testAPatternRouteIsRecordedWithItsLabelAndDurationInSeconds(): void
    {
        $this->repository = new InMemoryHttpMetricsRepository();

        // ProductController is not in the test container: resolving it fails
        // inside the try, so the ErrorHandler answers 500 and it is still counted.
        $this->dispatch('/products/mouse-gamer', []);

        self::assertSame(500, http_response_code());
        self::assertCount(1, $this->repository->recorded);
        $sample = $this->repository->recorded[0];
        self::assertSame('/products/{param}', $sample->route);
        self::assertSame(500, $sample->status);
        self::assertGreaterThan(0.0, $sample->durationSeconds);
        self::assertLessThan(5.0, $sample->durationSeconds);
    }

    #[RunInSeparateProcess]
    public function testARecordingFailureDoesNotChangeTheResponse(): void
    {
        $this->repository = new InMemoryHttpMetricsRepository(failOnRecord: true);

        $body = $this->dispatch('/health', []);

        self::assertSame(200, http_response_code());
        self::assertSame('healthy', json_decode($body, true, 512, JSON_THROW_ON_ERROR)['status']);
    }

    #[RunInSeparateProcess]
    public function testTheHttpSourceDownOnlyNullsItsSections(): void
    {
        $this->repository = new InMemoryHttpMetricsRepository(failOnRead: true);

        $json = json_decode($this->dispatch('/api/metrics', []), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, http_response_code());
        self::assertNull($json['data']['requests']);
        self::assertNull($json['data']['errors']);
        self::assertNull($json['data']['response_times']);
        self::assertFalse($json['meta']['sources']['http']);
        self::assertTrue($json['meta']['sources']['memory']);
    }

    /** @param array<string, string> $query */
    private function dispatch(string $uri, array $query): string
    {
        $repository = $this->repository;
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'), ['strict_variables' => true]);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('query')->willReturn($this->createStub(\PDOStatement::class));
        $redis = new class () extends Client {
            public function __construct()
            {
            }

            public function ping(): string
            {
                return 'PONG';
            }
        };
        $health = new HealthCheck(fn (): PDO => $pdo, fn (): Client => $redis);
        // The A/B export fails (Redis down): a 500 raised by the action.
        $experiment = new RecommendationExperiment(
            RecommendationSettings::fromArray([]),
            fn (string $algorithm): GenerateRecommendations => throw new \LogicException('not used'),
            new AbTestAssigner(),
            new InMemoryAlgorithmMetricsRepository(failOnGet: true),
            new NullLogger(),
        );
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            HttpRequestRecorder::class => fn () => new HttpRequestRecorder($repository, new NullLogger()),
            HealthCheckController::class => fn () => new HealthCheckController($health),
            AbTestResultsController::class => fn () => new AbTestResultsController($experiment),
            MetricsExportController::class => fn () => new MetricsExportController(
                new ExportMetrics(
                    fn (): array => $repository->routes(),
                    fn () => new MemorySnapshot(1000, 2000, 1.0, false),
                    fn (): array => ['enabled' => false, 'variants' => null, 'algorithms' => []],
                    fn () => new EventBusStatus(true, 3),
                ),
                new PrometheusFormatter()
            ),
        ]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri . ($query === [] ? '' : '?' . http_build_query($query));
        $_GET = $query;
        header_remove();
        http_response_code(200);

        ob_start();
        require dirname(__DIR__, 3) . '/public/index.php';

        return (string) ob_get_clean();
    }
}
