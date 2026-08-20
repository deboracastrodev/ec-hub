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
        // The repository returns a Product entity (R3.4); serialize to the
        // array shape the view expects here, at the Application/View boundary.
        return $this->productRepository->findById($productId)?->toArray();
    }

    public function executeBySlug(string $slug): ?array
    {
        return $this->productRepository->findBySlug($slug)?->toArray();
    }

    public function executeByIdentifier(string $identifier): ?array
    {
        if ($this->looksLikeNumericId($identifier)) {
            return $this->execute((int) $identifier);
        }

        return $this->executeBySlug($identifier);
    }

    private function looksLikeNumericId(string $identifier): bool
    {
        return ctype_digit($identifier);
    }
}
