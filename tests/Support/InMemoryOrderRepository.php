<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Order\Model\Order;
use App\Domain\Order\Repository\OrderRepositoryInterface;

/** Order repository fake (Story 8.7); $failWith makes save() throw, $findFailWith findByNumber(). */
final class InMemoryOrderRepository implements OrderRepositoryInterface
{
    /** @var array<string, Order> keyed by order number */
    private array $orders = [];

    private int $nextId = 1;

    public ?\Throwable $failWith = null;

    public ?\Throwable $findFailWith = null;

    /** Runs at the start of save(), e.g. to simulate a concurrent write. */
    public ?\Closure $beforeSave = null;

    public function save(Order $order): Order
    {
        if ($this->beforeSave !== null) {
            ($this->beforeSave)();
        }
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        if (isset($this->orders[$order->orderNumber()])) {
            throw new \RuntimeException('Duplicate order number.');
        }

        $saved = $order->withId($this->nextId++);
        $this->orders[$saved->orderNumber()] = $saved;

        return $saved;
    }

    public function findByNumber(string $orderNumber): ?Order
    {
        if ($this->findFailWith !== null) {
            throw $this->findFailWith;
        }

        return $this->orders[$orderNumber] ?? null;
    }

    /** @return list<Order> */
    public function all(): array
    {
        return array_values($this->orders);
    }
}
