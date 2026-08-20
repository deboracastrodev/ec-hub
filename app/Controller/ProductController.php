<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Application\SEO\Service\MetaTagsService;

/**
 * Product Controller
 *
 * Handles HTTP requests for product listing and details.
 * Follows Clean Architecture principles.
 */
class ProductController
{
    private GetProductList $getProductList;
    private GetProductDetail $getProductDetail;
    private MetaTagsService $metaTagsService;
    private \Twig\Environment $twig;

    public function __construct(
        GetProductList $getProductList,
        GetProductDetail $getProductDetail,
        \Twig\Environment $twig,
        ?MetaTagsService $metaTagsService = null
    ) {
        $this->getProductList = $getProductList;
        $this->getProductDetail = $getProductDetail;
        $this->twig = $twig;
        $this->metaTagsService = $metaTagsService ?? new MetaTagsService();
    }

    /**
     * Display product listing page with pagination and category filtering
     *
     * @param array $queryParams Query parameters (page, limit, category)
     * @return string Rendered HTML
     */
    public function index(array $queryParams = []): string
    {
        $startTime = microtime(true);
        $listResult = $this->getProductList->execute($queryParams);
        $renderTime = (microtime(true) - $startTime) * 1000; // ms

        $meta = $this->metaTagsService->generateForPage('product.listing', [
            'category' => $listResult['currentCategoryLabel'] ?? null,
            'category_slug' => $listResult['currentCategory'] ?? null,
            'product_count' => $listResult['totalProducts'] ?? 0,
            'products' => $listResult['products'] ?? [],
        ]);

        return $this->twig->render('product/listing.html.twig', array_merge(
            $listResult,
            [
                'renderTime' => $renderTime,
                'meta' => $meta,
            ]
        ));
    }

    /**
     * Display single product details
     *
     * @param string $productIdentifier Product slug or numeric ID
     * @return string Rendered HTML
     */
    public function show(string $productIdentifier): string
    {
        $product = $this->getProductDetail->executeByIdentifier($productIdentifier);

        if (! $product) {
            error_log(sprintf('[ProductController] Produto não encontrado: %s', $productIdentifier));
            http_response_code(404);

            return $this->twig->render('error/404.html.twig', [
                'message' => 'Produto não encontrado',
            ]);
        }

        $meta = $this->metaTagsService->generateForPage('product.detail', [
            'product_name' => $product['name'] ?? '',
            'description' => $product['description'] ?? '',
            'category' => $product['category'] ?? '',
            'price' => $product['price'] ?? '',
            'image_url' => $product['image_url'] ?? '',
            'product_slug' => $product['slug'] ?? '',
            'product_id' => $product['id'] ?? '',
        ]);

        return $this->twig->render('product/detail.html.twig', [
            'product' => $product,
            'meta' => $meta,
        ]);
    }
}
