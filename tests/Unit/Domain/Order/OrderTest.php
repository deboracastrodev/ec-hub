<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Order;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function testTotalIsTheSumOfTheSubtotalsInCents(): void
    {
        $order = $this->order([
            new OrderItem(1, 'Caneca', 1990, 3),
            new OrderItem(2, 'Adesivo', 10, 1),
        ]);

        self::assertSame(5970, $order->items()[0]->subtotalCents());
        self::assertSame(5980, $order->totalCents());
        self::assertSame(4, $order->itemCount());
        self::assertSame(Order::STATUS_COMPLETED, $order->status());
        self::assertNull($order->id());
    }

    public function testWithIdReturnsACopy(): void
    {
        $order = $this->order([new OrderItem(1, 'Caneca', 1990, 1)]);
        $saved = $order->withId(7);

        self::assertNull($order->id());
        self::assertSame(7, $saved->id());
        self::assertSame($order->orderNumber(), $saved->orderNumber());
        self::assertSame($order->items(), $saved->items());
        self::assertSame($order->createdAt(), $saved->createdAt());
    }

    public function testRejectsAnEmptyItemList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->order([]);
    }

    public function testRejectsAnInvalidOrderNumber(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Order(null, 'EC-abc', 'Ana', 'ana@example.com', 'Rua A, 123', Order::STATUS_COMPLETED, [new OrderItem(1, 'X', 1, 1)], new DateTimeImmutable());
    }

    public function testNumberFormat(): void
    {
        self::assertTrue(Order::isValidNumber('EC-0123456789'));
        self::assertTrue(Order::isValidNumber('EC-ABCDEF0123'));
        self::assertFalse(Order::isValidNumber('EC-abcdef0123'));
        self::assertFalse(Order::isValidNumber('EC-ABCDEF012'));
        self::assertFalse(Order::isValidNumber("EC-ABCDEF0123\n"));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function invalidItems(): iterable
    {
        yield 'quantity 0' => [1, 100, 0];
        yield 'quantity 100' => [1, 100, 100];
        yield 'negative price' => [1, -1, 1];
        yield 'product id 0' => [0, 100, 1];
    }

    #[DataProvider('invalidItems')]
    public function testItemValidation(int $productId, int $cents, int $quantity): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OrderItem($productId, 'X', $cents, $quantity);
    }

    public function testItemBounds(): void
    {
        self::assertSame(0, (new OrderItem(1, 'Brinde', 0, 1))->subtotalCents());
        self::assertSame(99 * 250, (new OrderItem(1, 'X', 250, 99))->subtotalCents());
    }

    /** @param list<OrderItem> $items */
    private function order(array $items): Order
    {
        return new Order(null, 'EC-0123456789', 'Ana', 'ana@example.com', 'Rua A, 123', Order::STATUS_COMPLETED, $items, new DateTimeImmutable());
    }
}
