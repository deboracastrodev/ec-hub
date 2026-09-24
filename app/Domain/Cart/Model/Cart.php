<?php

declare(strict_types=1);

namespace App\Domain\Cart\Model;

use InvalidArgumentException;

/**
 * Shopping cart (Story 8.6): an immutable map of product id => quantity, in
 * insertion order. It lives in the visitor session under SESSION_FIELD, as
 * the JSON object {"<product_id>": <quantity>} -- the format the tracking
 * endpoint (POST /api/cart/items) already wrote before the cart had a model.
 */
final class Cart
{
    public const SESSION_FIELD = 'cart.items';
    public const MAX_QUANTITY = 99;

    /** @param array<int, int> $items product id => quantity (1..MAX_QUANTITY) */
    private function __construct(private readonly array $items)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Rebuilds the cart from whatever the session holds. Entries that are not
     * a positive integer id with a positive integer quantity are dropped;
     * quantities above MAX_QUANTITY are clamped. Never throws.
     */
    public static function fromSession(mixed $raw): self
    {
        if (! is_array($raw)) {
            return self::empty();
        }

        $items = [];
        foreach ($raw as $key => $quantity) {
            $id = self::productId($key);
            $qty = self::quantity($quantity);
            if ($id === null || $qty === null) {
                continue;
            }
            $items[$id] = min(self::MAX_QUANTITY, ($items[$id] ?? 0) + $qty);
        }

        return new self($items);
    }

    /** Adds $quantity units, clamped at MAX_QUANTITY. */
    public function add(int $productId, int $quantity): self
    {
        self::assertProductId($productId);
        if ($quantity < 1) {
            throw new InvalidArgumentException('Cart quantity to add must be at least 1.');
        }

        $items = $this->items;
        $items[$productId] = min(self::MAX_QUANTITY, ($items[$productId] ?? 0) + min(self::MAX_QUANTITY, $quantity));

        return new self($items);
    }

    /** Sets the quantity of a product; 0 removes it. */
    public function withQuantity(int $productId, int $quantity): self
    {
        self::assertProductId($productId);
        if ($quantity < 0 || $quantity > self::MAX_QUANTITY) {
            throw new InvalidArgumentException(sprintf('Cart quantity must be between 0 and %d.', self::MAX_QUANTITY));
        }
        if ($quantity === 0) {
            return $this->without($productId);
        }

        $items = $this->items;
        $items[$productId] = $quantity;

        return new self($items);
    }

    public function without(int $productId): self
    {
        $items = $this->items;
        unset($items[$productId]);

        return new self($items);
    }

    public function has(int $productId): bool
    {
        return isset($this->items[$productId]);
    }

    /** @return array<int, int> product id => quantity, in insertion order */
    public function quantities(): array
    {
        return $this->items;
    }

    /** Sum of the quantities. */
    public function itemCount(): int
    {
        return array_sum($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * The session value: {"<product_id>": <quantity>} once JSON-encoded. The
     * ids are PHP int keys (PHP casts numeric string keys anyway); since they
     * start at 1, a non-empty map encodes as a JSON object. An empty cart is
     * [] and encodes as the JSON list [] (fromSession() reads both as empty).
     *
     * @return array<int, int>
     */
    public function toSession(): array
    {
        return $this->items;
    }

    private static function assertProductId(int $productId): void
    {
        if ($productId < 1) {
            throw new InvalidArgumentException('Cart product id must be a positive integer.');
        }
    }

    private static function productId(int|string $key): ?int
    {
        if (is_int($key)) {
            return $key >= 1 ? $key : null;
        }
        if (! ctype_digit($key)) {
            return null;
        }

        $id = filter_var(ltrim($key, '0'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : $id;
    }

    private static function quantity(mixed $quantity): ?int
    {
        if (is_string($quantity)) {
            $quantity = is_numeric($quantity) ? (float) $quantity : null;
        }
        if (is_int($quantity)) {
            return $quantity >= 1 ? min(self::MAX_QUANTITY, $quantity) : null;
        }
        if (is_float($quantity) && is_finite($quantity) && floor($quantity) === $quantity && $quantity >= 1) {
            return $quantity >= self::MAX_QUANTITY ? self::MAX_QUANTITY : (int) $quantity;
        }

        return null;
    }
}
