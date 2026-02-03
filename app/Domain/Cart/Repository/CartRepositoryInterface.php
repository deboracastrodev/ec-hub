<?php

declare(strict_types=1);

namespace App\Domain\Cart\Repository;

/**
 * Cart Repository Interface
 *
 * Defines contract for Cart data access following DDD Repository pattern
 */
interface CartRepositoryInterface
{
    /**
     * Find cart by session ID
     *
     * @param string $sessionId Session identifier
     * @return array|null Cart data or null if not found
     */
    public function findBySession(string $sessionId): ?array;

    /**
     * Find cart by user ID
     *
     * @param int $userId User ID
     * @return array|null Cart data or null if not found
     */
    public function findByUser(int $userId): ?array;

    /**
     * Create new cart
     *
     * @param array $data Cart data
     * @return int Created cart ID
     */
    public function create(array $data): int;

    /**
     * Add item to cart
     *
     * @param int $cartId Cart ID
     * @param int $productId Product ID
     * @param int $quantity Quantity
     * @return bool Success status
     */
    public function addItem(int $cartId, int $productId, int $quantity): bool;

    /**
     * Remove item from cart
     *
     * @param int $cartId Cart ID
     * @param int $productId Product ID
     * @return bool Success status
     */
    public function removeItem(int $cartId, int $productId): bool;

    /**
     * Update item quantity
     *
     * @param int $cartId Cart ID
     * @param int $productId Product ID
     * @param int $quantity New quantity
     * @return bool Success status
     */
    public function updateItemQuantity(int $cartId, int $productId, int $quantity): bool;

    /**
     * Clear cart
     *
     * @param int $cartId Cart ID
     * @return bool Success status
     */
    public function clear(int $cartId): bool;
}
