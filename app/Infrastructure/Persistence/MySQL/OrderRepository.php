<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MySQL;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Model\OrderItem;
use App\Domain\Order\Repository\OrderRepositoryInterface;
use DateTimeImmutable;
use PDO;

/**
 * MySQL orders (Story 8.7): the order and its items are written in one
 * transaction. Prices are DECIMAL(10,2) in the tables and cents in the
 * entity; the conversion happens only here.
 */
final class OrderRepository implements OrderRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(Order $order): Order
    {
        $this->pdo->beginTransaction();

        try {
            $insertOrder = $this->pdo->prepare(
                'INSERT INTO orders (order_number, customer_name, customer_email, shipping_address, status, total, created_at)
                 VALUES (:order_number, :customer_name, :customer_email, :shipping_address, :status, :total, :created_at)'
            );
            $insertOrder->execute([
                'order_number' => $order->orderNumber(),
                'customer_name' => $order->customerName(),
                'customer_email' => $order->customerEmail(),
                'shipping_address' => $order->shippingAddress(),
                'status' => $order->status(),
                'total' => self::decimal($order->totalCents()),
                'created_at' => $order->createdAt()->format('Y-m-d H:i:s'),
            ]);
            $orderId = (int) $this->pdo->lastInsertId();

            $insertItem = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, unit_price, quantity)
                 VALUES (:order_id, :product_id, :product_name, :unit_price, :quantity)'
            );
            foreach ($order->items() as $item) {
                $insertItem->execute([
                    'order_id' => $orderId,
                    'product_id' => $item->productId(),
                    'product_name' => $item->productName(),
                    'unit_price' => self::decimal($item->unitPriceCents()),
                    'quantity' => $item->quantity(),
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }

        return $order->withId($orderId);
    }

    public function findByNumber(string $orderNumber): ?Order
    {
        if (! Order::isValidNumber($orderNumber)) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE order_number = :order_number LIMIT 1');
        $stmt->execute(['order_number' => $orderNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row)) {
            return null;
        }

        $itemsStmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id');
        $itemsStmt->execute(['order_id' => (int) $row['id']]);
        $items = [];
        foreach ($itemsStmt->fetchAll(PDO::FETCH_ASSOC) as $itemRow) {
            $items[] = new OrderItem(
                (int) $itemRow['product_id'],
                (string) $itemRow['product_name'],
                self::cents($itemRow['unit_price']),
                (int) $itemRow['quantity']
            );
        }
        if ($items === []) {
            return null;
        }

        return new Order(
            (int) $row['id'],
            (string) $row['order_number'],
            (string) $row['customer_name'],
            (string) $row['customer_email'],
            (string) $row['shipping_address'],
            (string) $row['status'],
            $items,
            new DateTimeImmutable((string) $row['created_at'])
        );
    }

    private static function decimal(int $cents): string
    {
        // Exact: no float round trip on the way into the DECIMAL column.
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private static function cents(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
