<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Product\Repository\ProductRepositoryInterface;

/**
 * Category Service
 *
 * Application service for category-related operations.
 * Provides business logic for category management and retrieval.
 */
class CategoryService
{
    private ProductRepositoryInterface $productRepository;
    private ?array $categoriesCache = null;
    /**
     * @var array<string, string>
     */
    private array $normalizedCategoryMap = [];

    public function __construct(ProductRepositoryInterface $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    /**
     * Get all unique categories
     *
     * @return array List of category names sorted alphabetically
     */
    public function getAllCategories(): array
    {
        $this->ensureCategoriesCache();

        return $this->categoriesCache ?? [];
    }

    /**
     * Check if a category exists
     *
     * @param string $category Category name
     * @return bool True if category exists and has products
     */
    public function categoryExists(string $category): bool
    {
        return $this->resolveCategory($category) !== null;
    }

    /**
     * Get product count for a category
     *
     * @param string $category Category name
     * @return int Number of products in category
     */
    public function getProductCount(string $category): int
    {
        return $this->productRepository->countByCategory($category);
    }

    /**
     * Get all categories with product counts
     *
     * @return array Associative array with category as key and count as value
     */
    public function getCategoriesWithCounts(): array
    {
        $categories = $this->getAllCategories();
        $result = [];

        foreach ($categories as $category) {
            $result[$category] = $this->getProductCount($category);
        }

        return $result;
    }

    public function resolveCategory(string $category): ?string
    {
        $this->ensureCategoriesCache();

        if ($category === '') {
            return null;
        }

        $normalized = $this->normalizeCategory($category);

        return $this->normalizedCategoryMap[$normalized] ?? null;
    }

    private function ensureCategoriesCache(): void
    {
        if ($this->categoriesCache !== null) {
            return;
        }

        $this->categoriesCache = $this->productRepository->findCategories();
        $this->normalizedCategoryMap = [];

        foreach ($this->categoriesCache as $category) {
            $normalized = $this->normalizeCategory($category);
            $this->normalizedCategoryMap[$normalized] = $category;
        }
    }

    private function normalizeCategory(string $category): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $category);
        if ($transliterated === false) {
            $transliterated = $category;
        }

        $normalized = strtolower((string) $transliterated);
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized ?? '');

        return trim((string) $normalized, '-');
    }
}
