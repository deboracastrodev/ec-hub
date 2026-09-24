<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Order;

use App\Application\Order\CheckoutInput;
use App\Application\Order\EmptyCartException;
use App\Application\Order\PlaceOrder;
use App\Domain\Cart\Model\Cart;
use App\Domain\Order\Model\Order;
use App\Domain\Order\Service\OrderConfirmationMailerInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Tests\Support\InMemoryOrderRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemorySessionRepository;

final class PlaceOrderTest extends TestCase
{
    private const SESSION = 'session-1';

    private InMemorySessionRepository $sessions;

    private InMemoryProductRepository $products;

    private InMemoryOrderRepository $orders;

    /** @var list<Order> */
    private array $mailed = [];

    private ?\Throwable $mailerFailure = null;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->sessions = new InMemorySessionRepository();
        $this->products = new InMemoryProductRepository([
            ['id' => 1, 'name' => 'Mouse Gamer RGB', 'slug' => 'mouse', 'description' => '', 'price' => 149.90, 'category' => 'P', 'image_url' => null],
            ['id' => 2, 'name' => 'Caneca', 'slug' => 'caneca', 'description' => '', 'price' => 19.90, 'category' => 'P', 'image_url' => null],
            ['id' => 3, 'name' => 'Adesivo', 'slug' => 'adesivo', 'description' => '', 'price' => 0.10, 'category' => 'P', 'image_url' => null],
        ]);
        $this->orders = new InMemoryOrderRepository();
    }

    public function testPlacesTheOrderAndEmptiesTheCart(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 2]);

        $order = $this->useCase()->place(self::SESSION, $this->input());

        self::assertSame(1, $order->id());
        self::assertSame('EC-00000000AA', $order->orderNumber());
        self::assertSame(Order::STATUS_COMPLETED, $order->status());
        self::assertSame('Ana Souza', $order->customerName());
        self::assertSame('ana@example.com', $order->customerEmail());
        self::assertSame("Rua das Flores, 123\nSão Paulo", $order->shippingAddress());
        self::assertSame(29980, $order->totalCents());
        self::assertCount(1, $order->items());
        self::assertSame('Mouse Gamer RGB', $order->items()[0]->productName());
        self::assertSame(14990, $order->items()[0]->unitPriceCents());
        self::assertSame(2, $order->items()[0]->quantity());

        self::assertSame([$order], $this->orders->all());
        self::assertSame([$order], $this->mailed);
        self::assertSame([], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD));
        self::assertSame(
            ['order_number' => 'EC-00000000AA', 'email_sent' => true],
            $this->sessions->get(self::SESSION, PlaceOrder::LAST_ORDER_FIELD)
        );
        self::assertSame(['order_number' => 'EC-00000000AA', 'email_sent' => true], $this->useCase()->lastOrder(self::SESSION));
    }

    public function testMoneyIsSummedInCents(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [2 => 3, 3 => 1]);

        $order = $this->useCase()->place(self::SESSION, $this->input());

        self::assertSame(5980, $order->totalCents());
    }

    public function testDeletedProductsAreLeftOut(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1, 2 => 1]);
        $this->products->delete(2);

        $order = $this->useCase()->place(self::SESSION, $this->input());

        self::assertSame([1], array_map(static fn ($item) => $item->productId(), $order->items()));
        self::assertSame(14990, $order->totalCents());
    }

    public function testEmptyCartThrowsWithoutWriting(): void
    {
        foreach ([null, [], [2 => 1], 'garbage'] as $cart) {
            $this->setUp();
            if ($cart !== null) {
                $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, $cart);
            }
            $this->products->delete(2);
            $writes = $this->sessions->writes;

            try {
                $this->useCase()->place(self::SESSION, $this->input());
                self::fail('EmptyCartException expected');
            } catch (EmptyCartException) {
            }

            self::assertSame([], $this->orders->all());
            self::assertSame([], $this->mailed);
            self::assertSame($writes, $this->sessions->writes);
            self::assertNull($this->sessions->get(self::SESSION, PlaceOrder::LAST_ORDER_FIELD));
        }
    }

    public function testASecondPlacementFindsTheCartEmpty(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1]);
        $this->useCase()->place(self::SESSION, $this->input());

        $this->expectException(EmptyCartException::class);

        try {
            $this->useCase()->place(self::SESSION, $this->input());
        } finally {
            self::assertCount(1, $this->orders->all());
        }
    }

    public function testRetriesTheClaimOnCasConflicts(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1]);
        $this->sessions->casConflicts = 4;

        $order = $this->useCase()->place(self::SESSION, $this->input());

        self::assertSame(5, $this->sessions->casCalls);
        self::assertSame(14990, $order->totalCents());
        self::assertSame([], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testGivesUpAfterFiveCasConflicts(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1]);
        $this->sessions->casConflicts = 5;

        try {
            $this->useCase()->place(self::SESSION, $this->input());
            self::fail('RuntimeException expected');
        } catch (RuntimeException $exception) {
            self::assertNotInstanceOf(EmptyCartException::class, $exception);
        }

        self::assertSame(5, $this->sessions->casCalls);
        self::assertSame([], $this->orders->all());
        self::assertSame(['1' => 1], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testASaveFailureRestoresTheCartAndRethrows(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 2, 2 => 1]);
        $failure = new \PDOException('MySQL fora');
        $this->orders->failWith = $failure;

        try {
            $this->useCase()->place(self::SESSION, $this->input());
            self::fail('PDOException expected');
        } catch (\PDOException $exception) {
            self::assertSame($failure, $exception);
        }

        self::assertSame(['1' => 2, '2' => 1], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD));
        self::assertSame([], $this->mailed);
        self::assertNull($this->sessions->get(self::SESSION, PlaceOrder::LAST_ORDER_FIELD));
    }

    public function testASaveFailureStillRethrowsWhenTheRestoreFails(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1]);
        $this->orders->failWith = new \PDOException('MySQL fora');
        // A concurrent add lands between the claim and the restore: the restore
        // CAS (expected []) misses and the newer cart is kept.
        $this->orders->beforeSave = function (): void {
            $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [2 => 1]);
        };

        try {
            $this->useCase()->place(self::SESSION, $this->input());
            self::fail('PDOException expected');
        } catch (\PDOException) {
        }

        self::assertContains('checkout_cart_not_restored', $this->logged);
        self::assertSame(['2' => 1], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD));
    }

    public function testANumberGeneratorFailureLeavesTheCartUnclaimed(): void
    {
        $generators = [
            'throws' => static fn (): string => throw new RuntimeException('sem entropia'),
            'invalid number' => static fn (): string => 'EC-invalid',
        ];
        foreach ($generators as $case => $generator) {
            $this->setUp();
            $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 2]);

            try {
                $this->useCase(null, $generator)->place(self::SESSION, $this->input());
                self::fail("Exception expected ({$case})");
            } catch (RuntimeException | InvalidArgumentException $exception) {
                self::assertNotInstanceOf(EmptyCartException::class, $exception, $case);
            }

            self::assertSame(0, $this->sessions->casCalls, $case);
            self::assertSame(['1' => 2], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD), $case);
            self::assertSame([], $this->orders->all(), $case);
            self::assertSame([], $this->mailed, $case);
        }
    }

    public function testAMailerFailureKeepsTheOrder(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1]);
        $this->mailerFailure = new RuntimeException('disco cheio');

        $order = $this->useCase()->place(self::SESSION, $this->input());

        self::assertSame([$order], $this->orders->all());
        self::assertSame([], $this->sessions->get(self::SESSION, Cart::SESSION_FIELD));
        self::assertSame(['order_number' => $order->orderNumber(), 'email_sent' => false], $this->useCase()->lastOrder(self::SESSION));
        self::assertContains('checkout_confirmation_email_failed', $this->logged);
    }

    public function testALastOrderWriteFailureIsLoggedNotThrown(): void
    {
        // Only save() fails (the claim is a compareAndSwap and still works).
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1]);
        $inner = $this->sessions;
        $failingSave = new class ($inner) implements SessionRepositoryInterface {
            public function __construct(private readonly InMemorySessionRepository $inner)
            {
            }

            public function save(string $sessionId, string $field, mixed $value): void
            {
                throw new RuntimeException('Redis fora');
            }

            public function get(string $sessionId, string $field): mixed
            {
                return $this->inner->get($sessionId, $field);
            }

            public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
            {
                return $this->inner->compareAndSwap($sessionId, $field, $expected, $value);
            }
        };

        $order = $this->useCase($failingSave)->place(self::SESSION, $this->input());

        self::assertSame([$order], $this->orders->all());
        self::assertContains('checkout_last_order_not_saved', $this->logged);
    }

    public function testRejectsAnInvalidInput(): void
    {
        $this->sessions->save(self::SESSION, Cart::SESSION_FIELD, [1 => 1]);

        $this->expectException(InvalidArgumentException::class);
        $this->useCase()->place(self::SESSION, CheckoutInput::fromForm([]));
    }

    public function testLastOrderIgnoresMalformedValues(): void
    {
        $useCase = $this->useCase();
        self::assertNull($useCase->lastOrder(self::SESSION));

        foreach (['EC-1', ['order_number' => 'ec-0123456789'], ['order_number' => 5], ['email_sent' => true]] as $value) {
            $this->sessions->save(self::SESSION, PlaceOrder::LAST_ORDER_FIELD, $value);
            self::assertNull($useCase->lastOrder(self::SESSION));
        }

        $this->sessions->save(self::SESSION, PlaceOrder::LAST_ORDER_FIELD, ['order_number' => 'EC-0123456789', 'email_sent' => 'yes']);
        self::assertSame(['order_number' => 'EC-0123456789', 'email_sent' => false], $useCase->lastOrder(self::SESSION));
    }

    public function testDefaultNumberGenerator(): void
    {
        for ($i = 0; $i < 20; $i++) {
            self::assertTrue(Order::isValidNumber(PlaceOrder::generateNumber()));
        }
    }

    private function input(): CheckoutInput
    {
        return CheckoutInput::fromForm([
            'name' => 'Ana Souza',
            'email' => 'ana@example.com',
            'address' => "Rua das Flores, 123\r\nSão Paulo",
        ]);
    }

    /** @param null|\Closure(): string $numberGenerator */
    private function useCase(?SessionRepositoryInterface $sessions = null, ?\Closure $numberGenerator = null): PlaceOrder
    {
        $mailer = new class (function (Order $order): void {
            if ($this->mailerFailure !== null) {
                throw $this->mailerFailure;
            }
            $this->mailed[] = $order;
        }) implements OrderConfirmationMailerInterface {

            public function __construct(private readonly \Closure $send)
            {
            }

            public function sendConfirmation(Order $order): void
            {
                ($this->send)($order);
            }
        };
        $logger = new class (function (string $message): void {
            $this->logged[] = $message;
        }) extends AbstractLogger {

            public function __construct(private readonly \Closure $record)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $message);
            }
        };

        return new PlaceOrder(
            $sessions ?? $this->sessions,
            $this->products,
            $this->orders,
            $mailer,
            $logger,
            $numberGenerator ?? static fn (): string => 'EC-00000000AA'
        );
    }
}
