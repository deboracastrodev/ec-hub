<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\MemoryMonitor;
use App\Controller\MemoryMonitoringController;
use App\Shared\Container\Container;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ReflectionProperty;

final class MemoryMonitoringBootstrapConfigurationTest extends TestCase
{
    public function testBootstrapResolvesMemoryMonitoringWithoutConnectingToRedisOrMysql(): void
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        self::assertSame([], $this->instancesOf($container));
        self::assertInstanceOf(MemoryMonitoringController::class, $container->get(MemoryMonitoringController::class));
        self::assertSame([
            MemoryMonitor::class,
            MemoryMonitoringController::class,
        ], array_keys($this->instancesOf($container)));
        self::assertArrayNotHasKey(Client::class, $this->instancesOf($container));
        self::assertArrayNotHasKey(\PDO::class, $this->instancesOf($container));
    }

    /** @return array<string, mixed> */
    private function instancesOf(Container $container): array
    {
        return (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
    }
}
