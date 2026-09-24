<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Persistence;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Infrastructure\Persistence\MySQL\OrderRepository;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\RequiresTestDatabase;

/** Story 8.7: orders against a real MySQL (ec_hub_test). */
#[Group('db')]
final class OrderRepositoryTest extends TestCase
{
    use RequiresTestDatabase;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->connectToTestDatabaseOrSkip();
    }

    public function testSavesAndFindsTheOrderWithItsItems(): void
    {
        $repository = new OrderRepository($this->pdo);
        $saved = $repository->save($this->order('EC-0123456789'));

        self::assertNotNull($saved->id());

        $row = $this->pdo->query("SELECT * FROM orders WHERE id = {$saved->id()}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame('completed', $row['status']);
        self::assertSame('59.80', $row['total']);
        self::assertSame('2', (string) $this->pdo->query("SELECT COUNT(*) FROM order_items WHERE order_id = {$saved->id()}")->fetchColumn());

        $found = (new OrderRepository($this->pdo))->findByNumber('EC-0123456789');
        self::assertNotNull($found);
        self::assertSame($saved->id(), $found->id());
        self::assertSame('Ana <b>Souza</b>', $found->customerName());
        self::assertSame('ana@example.com', $found->customerEmail());
        self::assertSame("Rua das Flores, 123\nSão Paulo", $found->shippingAddress());
        self::assertSame(Order::STATUS_COMPLETED, $found->status());
        self::assertSame(5980, $found->totalCents());
        self::assertSame(['Caneca', 'Adesivo'], array_map(static fn ($item) => $item->productName(), $found->items()));
        self::assertSame([1990, 10], array_map(static fn ($item) => $item->unitPriceCents(), $found->items()));
        self::assertSame([3, 1], array_map(static fn ($item) => $item->quantity(), $found->items()));

        self::assertNull($repository->findByNumber('EC-FFFFFFFFFF'));
        self::assertNull($repository->findByNumber('not-a-number'));
    }

    public function testAFailedItemInsertRollsTheOrderBack(): void
    {
        $repository = new OrderRepository($this->pdo);
        $repository->save($this->order('EC-0000000001'));

        // Same number again: the unique index fails the orders INSERT.
        try {
            $repository->save($this->order('EC-0000000001'));
            self::fail('PDOException expected');
        } catch (\PDOException) {
        }
        self::assertFalse($this->pdo->inTransaction());

        // An item that does not fit its column (product_name > 255) fails
        // after the orders row was inserted: nothing of it may remain.
        $order = new Order(null, 'EC-0000000002', 'Ana', 'ana@example.com', 'Rua das Flores, 123', Order::STATUS_COMPLETED, [
            new OrderItem(1, 'Ok', 100, 1),
            new OrderItem(2, str_repeat('x', 300), 100, 1),
        ], new DateTimeImmutable());
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");

        try {
            $repository->save($order);
            self::fail('PDOException expected');
        } catch (\PDOException) {
        }

        self::assertFalse($this->pdo->inTransaction());
        self::assertSame('0', (string) $this->pdo->query("SELECT COUNT(*) FROM orders WHERE order_number = 'EC-0000000002'")->fetchColumn());
        self::assertSame('1', (string) $this->pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn());
        self::assertSame('2', (string) $this->pdo->query('SELECT COUNT(*) FROM order_items')->fetchColumn());
    }

    private function order(string $number): Order
    {
        return new Order(null, $number, 'Ana <b>Souza</b>', 'ana@example.com', "Rua das Flores, 123\nSão Paulo", Order::STATUS_COMPLETED, [
            new OrderItem(5, 'Caneca', 1990, 3),
            new OrderItem(6, 'Adesivo', 10, 1),
        ], new DateTimeImmutable());
    }
}
