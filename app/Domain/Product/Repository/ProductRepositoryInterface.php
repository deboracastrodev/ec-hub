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
 *
 * Soft delete (Story 8.3, FR106): every read method only sees active
 * products; a deleted product behaves as if it did not exist, except that
 * its slug stays reserved.
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
     * Search candidates (Story 8.5, FR109): active products whose name or
     * description contains ANY of the terms (case- and accent-insensitive),
     * optionally only within a category. No relevance order here -- that is
     * ProductSearchRanker's job; implementations return them by id. An empty
     * term list returns an empty list.
     *
     * @param list<string> $terms Already normalized (SearchQuery::terms())
     * @return list<Product>
     */
    public function searchCandidates(array $terms, ?string $category = null, int $limit = 500): array;

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
     * Update an active product (a soft-deleted one is never touched)
     *
     * Only the keys present in $data are written. A present null/'' clears
     * the optional fields: image_url becomes null and description ''.
     *
     * @param int $id Product ID
     * @param array $data Product data
     * @return bool Whether a row changed (implementations may return false
     *              when the product exists but the values are identical)
     */
    public function update(int $id, array $data): bool;

    /**
     * Soft delete an active product: it is marked as deleted (never removed)
     * and disappears from every read method.
     *
     * @param int $id Product ID
     * @return bool False when the product does not exist or is already deleted
     */
    public function delete(int $id): bool;
}
