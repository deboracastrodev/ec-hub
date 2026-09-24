<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Order\PlaceOrder;
use App\Controller\CheckoutController;
use App\Domain\Order\Repository\OrderRepositoryInterface;
use App\Domain\Order\Service\OrderConfirmationMailerInterface;
use App\Infrastructure\Mail\FileOrderConfirmationMailer;
use App\Infrastructure\Persistence\MySQL\OrderRepository;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/** Story 8.7: the real config/bootstrap.php wires the checkout, without MySQL or Redis. */
final class CheckoutBootstrapConfigurationTest extends TestCase
{
    private const VARS = ['SESSION_COOKIE_SECRET', 'REDIS_HOST', 'REDIS_PORT'];

    /** @var array<string, string|false> */
    private array $previous = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $var) {
            $this->previous[$var] = getenv($var);
        }
        putenv('SESSION_COOKIE_SECRET=checkout-bootstrap-test-secret-32-chars');
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

    public function testCheckoutEntriesResolve(): void
    {
        $container = $this->bootContainer();

        self::assertInstanceOf(CheckoutController::class, $container->get(CheckoutController::class));
        self::assertInstanceOf(PlaceOrder::class, $container->get(PlaceOrder::class));
        self::assertInstanceOf(OrderRepository::class, $container->get(OrderRepositoryInterface::class));
        self::assertInstanceOf(FileOrderConfirmationMailer::class, $container->get(OrderConfirmationMailerInterface::class));
    }

    public function testTheMailerWritesToVarMail(): void
    {
        $mailer = $this->bootContainer()->get(OrderConfirmationMailerInterface::class);

        $directory = (new ReflectionProperty(FileOrderConfirmationMailer::class, 'directory'))->getValue($mailer);
        self::assertSame(dirname(__DIR__, 3) . '/var/mail', $directory);
    }

    private function bootContainer(): Container
    {
        /** @var Container $container */
        $container = require dirname(__DIR__, 3) . '/config/bootstrap.php';

        // The repositories only store the PDO; an in-memory one keeps the
        // test independent of a running MySQL (as CartBootstrapConfigurationTest).
        $instances = new ReflectionProperty(Container::class, 'instances');
        $instances->setValue($container, [PDO::class => new PDO('sqlite::memory:')] + $instances->getValue($container));

        return $container;
    }
}
