<?php

declare(strict_types=1);

namespace App\Domain\Order\Model;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A placed order (Story 8.7, simulated checkout). Immutable; money in cents.
 * Only completed orders exist: the checkout has no payment step to wait on.
 */
final class Order
{
    public const STATUS_COMPLETED = 'completed';

    public const NUMBER_PATTERN = '/^EC-[0-9A-F]{10}\z/'; // \z: $ would accept a trailing "\n"

    /** @var list<OrderItem> */
    private readonly array $items;

    /** @param list<OrderItem> $items */
    public function __construct(
        private readonly ?int $id,
        private readonly string $orderNumber,
        private readonly string $customerName,
        private readonly string $customerEmail,
        private readonly string $shippingAddress,
        private readonly string $status,
        array $items,
        private readonly DateTimeImmutable $createdAt
    ) {
        if ($items === []) {
            throw new InvalidArgumentException('An order needs at least one item.');
        }
        if (preg_match(self::NUMBER_PATTERN, $orderNumber) !== 1) {
            throw new InvalidArgumentException('Invalid order number.');
        }

        $this->items = $items;
    }

    public static function isValidNumber(string $orderNumber): bool
    {
        return preg_match(self::NUMBER_PATTERN, $orderNumber) === 1;
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->orderNumber,
            $this->customerName,
            $this->customerEmail,
            $this->shippingAddress,
            $this->status,
            $this->items,
            $this->createdAt
        );
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function orderNumber(): string
    {
        return $this->orderNumber;
    }

    public function customerName(): string
    {
        return $this->customerName;
    }

    public function customerEmail(): string
    {
        return $this->customerEmail;
    }

    public function shippingAddress(): string
    {
        return $this->shippingAddress;
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return list<OrderItem> */
    public function items(): array
    {
        return $this->items;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function totalCents(): int
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += $item->subtotalCents();
        }

        return $total;
    }

    /** Sum of the quantities. */
    public function itemCount(): int
    {
        $count = 0;
        foreach ($this->items as $item) {
            $count += $item->quantity();
        }

        return $count;
    }
}
