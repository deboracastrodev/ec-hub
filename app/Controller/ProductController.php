<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Product\Repository\ProductRepositoryInterface;

/**
 * Product Controller
 *
 * Handles HTTP requests for product listing and details.
 * Follows Clean Architecture principles.
 */
class ProductController
{
    private ProductRepositoryInterface $productRepository;
    private \Twig\Environment $twig;

    public function __construct(ProductRepositoryInterface $productRepository, \Twig\Environment $twig)
    {
        $this->productRepository = $productRepository;
        $this->twig = $twig;
    }

    /**
     * Display product listing page with pagination
     *
     * @param array $queryParams Query parameters (page, limit)
     * @return string Rendered HTML
     */
    public function index(array $queryParams = []): string
    {
        $startTime = microtime(true);

        // Paginação
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $limit = max(1, min(100, (int) ($queryParams['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // Buscar produtos (com paginação)
        $products = $this->productRepository->findAll($limit, $offset);
        $totalProducts = $this->productRepository->count();
        $totalPages = (int) ceil($totalProducts / $limit);

        // Performance tracking
        $renderTime = (microtime(true) - $startTime) * 1000; // ms

        // Renderizar template Twig
        return $this->twig->render('product/listing.html.twig', [
            'products' => $products,
            'currentPage' => $page,
            'totalPages' => $totalPages,
            'totalProducts' => $totalProducts,
            'limit' => $limit,
            'renderTime' => $renderTime,
        ]);
    }

    /**
     * Display single product details
     *
     * @param int $id Product ID
     * @return string Rendered HTML
     */
    public function show(int $id): string
    {
        $product = $this->productRepository->findById($id);

        if (!$product) {
            return $this->twig->render('error/404.html.twig', [
                'message' => 'Produto não encontrado'
            ]);
        }

        return $this->twig->render('product/detail.html.twig', [
            'product' => $product,
        ]);
    }
}
