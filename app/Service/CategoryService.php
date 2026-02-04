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
        return $this->productRepository->findCategories();
    }

    /**
     * Check if a category exists
     *
     * @param string $category Category name
     * @return bool True if category exists and has products
     */
    public function categoryExists(string $category): bool
    {
        $categories = $this->getAllCategories();
        return in_array($category, $categories, true);
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
}
