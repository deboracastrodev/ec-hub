<?php

declare(strict_types=1);

namespace App\Domain\Product\Repository;

/**
 * Product Repository Interface
 *
 * Defines contract for Product data access following DDD Repository pattern
 */
interface ProductRepositoryInterface
{
    /**
     * Find product by ID
     *
     * @param int $id Product ID
     * @return array|null Product data or null if not found
     */
    public function findById(int $id): ?array;

    /**
     * Find all products
     *
     * @param int $limit Limit results
     * @param int $offset Offset for pagination
     * @return array List of products
     */
    public function findAll(int $limit = 50, int $offset = 0): array;

    /**
     * Find products by category
     *
     * @param string $category Category name
     * @param int $limit Limit results
     * @return array List of products
     */
    public function findByCategory(string $category, int $limit = 50): array;

    /**
     * Find products by category with pagination
     *
     * @param string $category Category name
     * @param int $limit Limit results
     * @param int $offset Offset for pagination
     * @return array List of products
     */
    public function findByCategoryPaginated(string $category, int $limit, int $offset): array;

    /**
     * Count products by category
     *
     * @param string $category Category name
     * @return int Total count for category
     */
    public function countByCategory(string $category): int;

    /**
     * Find all unique categories
     *
     * @return array List of category names
     */
    public function findCategories(): array;

    /**
     * Count total products
     *
     * @return int Total count
     */
    public function count(): int;

    /**
     * Create new product
     *
     * @param array $data Product data
     * @return int Created product ID
     */
    public function create(array $data): int;

    /**
     * Update product
     *
     * @param int $id Product ID
     * @param array $data Product data
     * @return bool Success status
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete product
     *
     * @param int $id Product ID
     * @return bool Success status
     */
    public function delete(int $id): bool;
}
