<?php

declare(strict_types=1);

namespace App\Domain\Product\Repository;

use App\Domain\Product\Model\Product;

/**
 * Product Repository Interface
 *
 * Defines contract for Product data access following DDD Repository pattern.
 * Read methods return Product entities, not raw arrays (R3.4) -- the shape
 * of the products table is an Infrastructure concern, not a Domain one.
 */
interface ProductRepositoryInterface
{
    /**
     * Find product by ID
     */
    public function findById(int $id): ?Product;

    /**
     * Find product by slug
     */
    public function findBySlug(string $slug): ?Product;

    /**
     * Find all products
     *
     * @return list<Product>
     */
    public function findAll(int $limit = 50, int $offset = 0): array;

    /**
     * Find products by category
     *
     * @return list<Product>
     */
    public function findByCategory(string $category, int $limit = 50): array;

    /**
     * Find products by category with pagination
     *
     * @return list<Product>
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
