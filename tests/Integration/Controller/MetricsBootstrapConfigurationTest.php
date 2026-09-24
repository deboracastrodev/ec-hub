<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\Evaluation\PublishedQualityMetrics;
use App\Controller\MetricsController;
use App\Domain\Event\EventBusStatusInterface;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Shared\Container\Container;
use Closure;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ReflectionProperty;
use Twig\Environment;

final class MetricsBootstrapConfigurationTest extends TestCase
{
    public function testBootstrapResolvesMetricsControllerWithoutConnectingToRedis(): void
    {
        $previousHost = getenv('REDIS_HOST');
        $previousPort = getenv('REDIS_PORT');
        putenv('REDIS_HOST=redis.invalid.test');
        putenv('REDIS_PORT=1');

        try {
            /** @var Container $container */
            $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

            self::assertSame([], $this->instancesOf($container));
            self::assertInstanceOf(MetricsController::class, $container->get(MetricsController::class));
            self::assertSame([
                Client::class,
                EventHistoryRepositoryInterface::class,
                Environment::class,
                SessionRepositoryInterface::class,
                EventPublisherInterface::class,
                EventBusStatusInterface::class,
                MetricsController::class,
            ], array_keys($this->instancesOf($container)));
            self::assertArrayNotHasKey(PDO::class, $this->instancesOf($container));

            // Story 10.5: the quality source reads the committed make eval report, without MySQL/Redis.
            $quality = (new ReflectionProperty(MetricsController::class, 'qualityMetrics'))
                ->getValue($container->get(MetricsController::class));
            self::assertInstanceOf(Closure::class, $quality);
            $metrics = $quality();
            $report = json_decode(
                (string) file_get_contents(dirname(__DIR__, 3) . '/docs/evaluation/offline-evaluation.json'),
                true,
                64,
                JSON_THROW_ON_ERROR
            );
            self::assertInstanceOf(PublishedQualityMetrics::class, $metrics);
            self::assertSame((float) $report['metrics']['precision_at_k']['5'], $metrics->precisionAt5);
            self::assertSame($report['measured_at'], $metrics->measuredAt);
            self::assertArrayNotHasKey(PDO::class, $this->instancesOf($container));
        } finally {
            putenv($previousHost === false ? 'REDIS_HOST' : "REDIS_HOST={$previousHost}");
            putenv($previousPort === false ? 'REDIS_PORT' : "REDIS_PORT={$previousPort}");
        }
    }

    /** @return array<string, mixed> */
    private function instancesOf(Container $container): array
    {
        return (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
    }
}
