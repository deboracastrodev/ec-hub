<?php

declare(strict_types=1);

namespace App\Application\Product;

use App\Domain\Product\Model\SearchQuery;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Product\Service\CategoryService;
use App\Domain\Product\Service\ProductSearchRanker;

/**
 * GetProductList Use Case
 *
 * Encapsula a lógica de paginação, filtros e métricas exibidas na listagem.
 *
 * Modo busca (Story 8.5, FR109): com `q` string não vazia, os candidatos vêm
 * de searchCandidates() (termos em OU, até 500), são ranqueados pelo
 * ProductSearchRanker e paginados aqui, em PHP.
 */
class GetProductList
{
    private ProductRepositoryInterface $productRepository;
    private CategoryService $categoryService;
    private ProductSearchRanker $searchRanker;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        CategoryService $categoryService,
        ?ProductSearchRanker $searchRanker = null
    ) {
        $this->productRepository = $productRepository;
        $this->categoryService = $categoryService;
        $this->searchRanker = $searchRanker ?? new ProductSearchRanker();
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

        $rawQuery = $queryParams['q'] ?? null;
        $searchQuery = is_string($rawQuery) && trim($rawQuery) !== '' ? SearchQuery::fromRaw($rawQuery) : null;

        if ($searchQuery !== null) {
            $candidates = $searchQuery->hasTerms()
                ? $this->productRepository->searchCandidates(
                    $searchQuery->terms(),
                    $categoryInput !== null && $categoryInput !== '' ? ($resolvedCategory ?? $categoryInput) : null
                )
                : [];
            $ranked = $this->searchRanker->rank($candidates, $searchQuery);
            $totalProducts = count($ranked);
            $products = array_slice($ranked, $offset, $limit);
        } elseif ($categoryInput !== null && $categoryInput !== '') {
            $categoryToQuery = $resolvedCategory ?? $categoryInput;
            $products = $this->productRepository->findByCategoryPaginated($categoryToQuery, $limit, $offset);
            $totalProducts = $this->productRepository->countByCategory($categoryToQuery);
        } else {
            $products = $this->productRepository->findAll($limit, $offset);
            $totalProducts = $totalAllProducts;
        }

        // The repository returns Product entities (R3.4); serialize to the
        // array shape the view expects here, at the Application/View boundary.
        $products = array_map(static fn ($product) => $product->toArray(), $products);

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
        if ($searchQuery !== null) {
            $baseParams['q'] = $searchQuery->raw();
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
            'isSearch' => $searchQuery !== null,
            'searchQuery' => $searchQuery?->raw(),
        ];
    }
}
