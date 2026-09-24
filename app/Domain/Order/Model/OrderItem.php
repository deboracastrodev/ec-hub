<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

use InvalidArgumentException;

/**
 * One line of a placed order (Story 8.7): a snapshot of the product name and
 * unit price at checkout time, so later catalog edits never change the order.
 */
final class OrderItem
{
    public const MAX_QUANTITY = 99;

    public function __construct(
        private readonly int $productId,
        private readonly string $productName,
        private readonly int $unitPriceCents,
        private readonly int $quantity
    ) {
        if ($productId < 1) {
            throw new InvalidArgumentException('Order item product id must be a positive integer.');
        }
        if ($unitPriceCents < 0) {
            throw new InvalidArgumentException('Order item unit price cannot be negative.');
        }
        if ($quantity < 1 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf('Order item quantity must be between 1 and %d.', self::MAX_QUANTITY));
        }
    }

    public function productId(): int
    {
        return $this->productId;
    }

    public function productName(): string
    {
        return $this->productName;
    }

    public function unitPriceCents(): int
    {
        return $this->unitPriceCents;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function subtotalCents(): int
    {
        return $this->unitPriceCents * $this->quantity;
    }
}
