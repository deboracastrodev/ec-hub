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

    public function executeBySlug(string $slug): ?array
    {
        return $this->productRepository->findBySlug($slug);
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
