<?php

declare(strict_types=1);

namespace App\Domain\Order\Repository;

use App\Domain\Order\Model\Order;

interface OrderRepositoryInterface
{
    /**
     * Persists the order and its items in one transaction.
     *
     * @return Order the same order, with its id
     */
    public function save(Order $order): Order;

    public function findByNumber(string $orderNumber): ?Order;
}
