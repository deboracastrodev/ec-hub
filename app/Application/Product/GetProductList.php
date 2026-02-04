<?php

declare(strict_types=1);

namespace App\Application\Product;

use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Service\CategoryService;

/**
 * GetProductList Use Case
 *
 * Encapsula a lógica de paginação, filtros e métricas exibidas na listagem.
 */
class GetProductList
{
    private ProductRepositoryInterface $productRepository;
    private CategoryService $categoryService;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        CategoryService $categoryService
    ) {
        $this->productRepository = $productRepository;
        $this->categoryService = $categoryService;
    }

    /**
     * @param array<string, mixed> $queryParams
     * @return array<string, mixed>
     */
    public function execute(array $queryParams = []): array
    {
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = max(1, min(100, (int) ($queryParams['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $requestedCategory = $queryParams['category'] ?? null;
        $categoryInput = is_string($requestedCategory) ? trim($requestedCategory) : null;
        $resolvedCategory = $categoryInput !== null && $categoryInput !== ''
            ? $this->categoryService->resolveCategory($categoryInput)
            : null;

        $totalAllProducts = $this->productRepository->count();

        if ($categoryInput !== null && $categoryInput !== '') {
            $categoryToQuery = $resolvedCategory ?? $categoryInput;
            $products = $this->productRepository->findByCategoryPaginated($categoryToQuery, $limit, $offset);
            $totalProducts = $this->productRepository->countByCategory($categoryToQuery);
        } else {
            $products = $this->productRepository->findAll($limit, $offset);
            $totalProducts = $totalAllProducts;
        }

        $totalPages = max(1, (int) ceil($totalProducts / $limit));
        $categoriesWithCounts = $this->categoryService->getCategoriesWithCounts();

        $baseParams = $queryParams;
        unset($baseParams['page']);
        $baseParams['limit'] = $limit;
        if ($resolvedCategory) {
            $baseParams['category'] = $resolvedCategory;
        } elseif ($categoryInput) {
            $baseParams['category'] = $categoryInput;
        } else {
            unset($baseParams['category']);
        }
        $paginationBaseQuery = http_build_query($baseParams);

        return [
            'products' => $products,
            'currentPage' => $page,
            'limit' => $limit,
            'offset' => $offset,
            'totalPages' => $totalPages,
            'totalProducts' => $totalProducts,
            'totalAllProducts' => $totalAllProducts,
            'queryParams' => $queryParams,
            'categories' => $categoriesWithCounts,
            'currentCategory' => $resolvedCategory,
            'currentCategoryLabel' => $resolvedCategory ?? $categoryInput,
            'categoryIsValid' => $resolvedCategory !== null,
            'requestedCategory' => $categoryInput,
            'hasNoProducts' => $totalProducts === 0,
            'paginationBaseQuery' => $paginationBaseQuery,
        ];
    }
}
