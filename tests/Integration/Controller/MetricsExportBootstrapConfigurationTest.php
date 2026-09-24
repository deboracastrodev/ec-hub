<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\ExportMetrics;
use App\Application\Monitoring\HttpMetricsRepositoryInterface;
use App\Application\Monitoring\HttpRequestRecorder;
use App\Controller\MetricsExportController;
use App\Infrastructure\Redis\RedisHttpMetricsRepository;
use App\Shared\Container\Container;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ReflectionProperty;

/** Story 8.4: the real container wires the HTTP metrics and GET /api/metrics. */
final class MetricsExportBootstrapConfigurationTest extends TestCase
{
    private string|false $previousHost;
    private string|false $previousPort;

    protected function setUp(): void
    {
        $this->previousHost = getenv('REDIS_HOST');
        $this->previousPort = getenv('REDIS_PORT');
        // Connection refused right away: nothing here may need a live Redis.
        putenv('REDIS_HOST=127.0.0.1');
        putenv('REDIS_PORT=1');
    }

    protected function tearDown(): void
    {
        putenv($this->previousHost === false ? 'REDIS_HOST' : "REDIS_HOST={$this->previousHost}");
        putenv($this->previousPort === false ? 'REDIS_PORT' : "REDIS_PORT={$this->previousPort}");
    }

    public function testBootstrapResolvesTheExportAndTheRecorderLazily(): void
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        self::assertInstanceOf(HttpRequestRecorder::class, $container->get(HttpRequestRecorder::class));
        self::assertInstanceOf(RedisHttpMetricsRepository::class, $container->get(HttpMetricsRepositoryInterface::class));
        self::assertInstanceOf(MetricsExportController::class, $container->get(MetricsExportController::class));
        self::assertArrayNotHasKey(PDO::class, $this->instancesOf($container));
        // The HTTP metrics repository has its own short-timeout client, not the shared one.
        self::assertArrayNotHasKey(Client::class, $this->instancesOf($container));

        // ...and that client keeps the 0.25 s bound on an unreachable Redis.
        $repository = $container->get(HttpMetricsRepositoryInterface::class);
        $client = (new ReflectionProperty(RedisHttpMetricsRepository::class, 'client'))->getValue($repository);
        $parameters = $client->getConnection()->getParameters();
        self::assertEqualsWithDelta(0.25, (float) $parameters->timeout, 0.0001);
        self::assertEqualsWithDelta(0.25, (float) $parameters->read_write_timeout, 0.0001);
    }

    public function testRedisDownDegradesOnlyTheRedisBackedSources(): void
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        $collected = $container->get(ExportMetrics::class)->collect();

        self::assertSame(
            ['http' => false, 'memory' => true, 'recommendations' => false, 'event_bus' => true],
            $collected['meta']['sources']
        );
        self::assertFalse($collected['data']['event_bus']['connected']);

        // The recorder swallows the same failure.
        $container->get(HttpRequestRecorder::class)->record('GET', '/health', 200, 0.01);
        self::assertArrayNotHasKey(PDO::class, $this->instancesOf($container));
    }

    /** @return array<string, mixed> */
    private function instancesOf(Container $container): array
    {
        return (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
    }
}
