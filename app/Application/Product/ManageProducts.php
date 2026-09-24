<?php

declare(strict_types=1);

namespace App\Application\Product;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;

/**
 * Admin product CRUD use cases (Story 8.3), only through the repository port.
 *
 * Delete is a soft delete (FR106): the repository stamps deleted_at and the
 * product disappears from every public read, recommendations included.
 *
 * Hook for Story 10.1: there is no model cache today, so nothing needs to be
 * invalidated here. When a serialized KNN model is introduced, create/update/
 * delete are the three places that must invalidate it.
 */
final class ManageProducts
{
    public const PER_PAGE = 20;

    public function __construct(private readonly ProductRepositoryInterface $products)
    {
    }

    /**
     * @return array{products: list<Product>, page: int, total_pages: int, total: int}
     */
    public function listPage(int $page): array
    {
        $total = $this->products->count();
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        // Below 1 becomes 1; past the end becomes the last page (this also
        // keeps the offset from overflowing on absurd page numbers).
        $page = min(max(1, $page), $totalPages);

        return [
            'products' => $this->products->findAll(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'total_pages' => $totalPages,
            'total' => $total,
        ];
    }

    public function find(int $id): ?Product
    {
        return $this->products->findById($id);
    }

    public function create(ProductInput $input): int
    {
        return $this->products->create($input->toData());
    }

    /**
     * False only when the product does not exist (or was deleted); true when
     * it exists, even if the submitted values were identical. The slug is
     * never regenerated, so public URLs survive a rename.
     */
    public function update(int $id, ProductInput $input): bool
    {
        if ($this->products->update($id, $input->toData())) {
            return true;
        }

        // The repository reports changed rows, so false is ambiguous: an
        // identical resubmission (still true here) or a product that is
        // missing or was deleted concurrently.
        return $this->products->findById($id) !== null;
    }

    public function delete(int $id): bool
    {
        return $this->products->delete($id);
    }
}
