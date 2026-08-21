<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Controller\MetricsController;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Shared\Container\Container;
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
                MetricsController::class,
            ], array_keys($this->instancesOf($container)));
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
