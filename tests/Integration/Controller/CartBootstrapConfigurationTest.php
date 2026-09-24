<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Cart\CartSummary;
use App\Controller\CartController;
use App\Controller\ProductController;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Twig\Environment;

/** Story 8.6: the real config/bootstrap.php wires the cart, without MySQL or Redis. */
final class CartBootstrapConfigurationTest extends TestCase
{
    private const VARS = ['SESSION_COOKIE_SECRET', 'REDIS_HOST', 'REDIS_PORT'];

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            $this->previous[$var] = getenv($var);
        }
        putenv('SESSION_COOKIE_SECRET=cart-bootstrap-test-secret-with-32-chars');
        putenv('REDIS_HOST=redis.invalid.test');
        putenv('REDIS_PORT=1');
        unset($_COOKIE[SessionContext::COOKIE_NAME], $_COOKIE[SessionContext::SIGNATURE_COOKIE_NAME]);
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $var => $value) {
            putenv($value === false ? $var : "{$var}={$value}");
        }
    }

    public function testCartControllerResolves(): void
    {
        self::assertInstanceOf(CartController::class, $this->bootContainer()->get(CartController::class));
    }

    public function testEnvironmentHasTheCartSummaryGlobal(): void
    {
        $globals = $this->bootContainer()->get(Environment::class)->getGlobals();

        self::assertArrayHasKey('cart_summary', $globals);
        self::assertInstanceOf(CartSummary::class, $globals['cart_summary']);
        // No session cookie: 0, without touching the (unreachable) Redis.
        self::assertSame(0, $globals['cart_summary']->itemCount());
    }

    public function testProductControllerReceivesTheSessionCsrf(): void
    {
        $container = $this->bootContainer();
        $controller = $container->get(ProductController::class);

        $csrf = (new ReflectionProperty(ProductController::class, 'csrf'))->getValue($controller);
        self::assertInstanceOf(SessionCsrf::class, $csrf);
        self::assertSame($container->get(SessionCsrf::class), $csrf);
    }

    private function bootContainer(): Container
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        // ProductRepository only stores the PDO; an in-memory one keeps the
        // test independent of a running MySQL (as AdminBootstrapConfigurationTest).
        $instances = new ReflectionProperty(Container::class, 'instances');
        $instances->setValue($container, [PDO::class => new PDO('sqlite::memory:')] + $instances->getValue($container));

        return $container;
    }
}
