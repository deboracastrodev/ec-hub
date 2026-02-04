<?php

declare(strict_types=1);

namespace App\Application\Product;

use App\Domain\Product\Repository\ProductRepositoryInterface;

/**
 * GetProductDetail Use Case
 */
class GetProductDetail
{
    private ProductRepositoryInterface $productRepository;

    public function __construct(ProductRepositoryInterface $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function execute(int $productId): ?array
    {
        return $this->productRepository->findById($productId);
    }
}
