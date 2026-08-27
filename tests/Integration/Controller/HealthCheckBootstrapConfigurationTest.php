<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Monitoring\HealthCheck;
use App\Controller\HealthCheckController;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ReflectionProperty;

final class HealthCheckBootstrapConfigurationTest extends TestCase
{
    public function testBootstrapResolvesHealthControllerLazilyWithoutSessionOrProductDependencies(): void
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        self::assertSame([], $this->instancesOf($container));
        self::assertInstanceOf(HealthCheckController::class, $container->get(HealthCheckController::class));
        self::assertSame([
            HealthCheck::class,
            HealthCheckController::class,
        ], array_keys($this->instancesOf($container)));
        self::assertArrayNotHasKey(SessionContext::class, $this->instancesOf($container));
        self::assertArrayNotHasKey(Client::class, $this->instancesOf($container));
        self::assertArrayNotHasKey(PDO::class, $this->instancesOf($container));
    }

    /** @return array<string, mixed> */
    private function instancesOf(Container $container): array
    {
        return (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
    }
}
