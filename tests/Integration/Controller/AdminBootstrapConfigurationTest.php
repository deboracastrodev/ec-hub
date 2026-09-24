<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Controller\Admin\AdminAuthController;
use App\Controller\Admin\AdminProductController;
use App\Shared\Container\Container;
use App\Shared\Http\AdminAuth;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Story 8.3: the real config/bootstrap.php wires the admin panel from the environment. */
final class AdminBootstrapConfigurationTest extends TestCase
{
    private const VARS = ['ADMIN_USERNAME', 'ADMIN_PASSWORD_HASH', 'SESSION_COOKIE_SECRET'];

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            $this->previous[$var] = getenv($var);
        }
        putenv('SESSION_COOKIE_SECRET=admin-bootstrap-test-secret-with-32-chars');
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }
    }

    public function testAdminControllersResolveWithValidCredentials(): void
    {
        putenv('ADMIN_USERNAME=admin');
        putenv('ADMIN_PASSWORD_HASH=' . password_hash('s3nha-forte', PASSWORD_DEFAULT));

        $container = $this->bootContainer();

        self::assertInstanceOf(AdminAuthController::class, $container->get(AdminAuthController::class));
        self::assertInstanceOf(AdminProductController::class, $container->get(AdminProductController::class));
        self::assertTrue($container->get(AdminAuth::class)->isConfigured());
    }

    public function testEmptyHashLeavesTheAdminDisabledButResolvable(): void
    {
        putenv('ADMIN_USERNAME=admin');
        putenv('ADMIN_PASSWORD_HASH=');

        $container = $this->bootContainer();

        self::assertInstanceOf(AdminAuthController::class, $container->get(AdminAuthController::class));
        self::assertInstanceOf(AdminProductController::class, $container->get(AdminProductController::class));
        self::assertFalse($container->get(AdminAuth::class)->isConfigured());
    }

    private function bootContainer(): Container
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        // ProductRepository only stores the PDO; an in-memory one keeps the
        // test independent of a running MySQL.
        $instances = new ReflectionProperty(Container::class, 'instances');
        $instances->setValue($container, [PDO::class => new PDO('sqlite::memory:')] + $instances->getValue($container));

        return $container;
    }
}
