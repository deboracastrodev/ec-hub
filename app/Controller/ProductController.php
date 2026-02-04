<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;

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
    private \Twig\Environment $twig;

    public function __construct(
        GetProductList $getProductList,
        GetProductDetail $getProductDetail,
        \Twig\Environment $twig
    ) {
        $this->getProductList = $getProductList;
        $this->getProductDetail = $getProductDetail;
        $this->twig = $twig;
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

        return $this->twig->render('product/listing.html.twig', array_merge(
            $listResult,
            [
                'renderTime' => $renderTime,
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

        if (!$product) {
            error_log(sprintf('[ProductController] Produto não encontrado: %s', $productIdentifier));
            http_response_code(404);
            return $this->twig->render('error/404.html.twig', [
                'message' => 'Produto não encontrado'
            ]);
        }

        return $this->twig->render('product/detail.html.twig', [
            'product' => $product,
        ]);
    }
}
