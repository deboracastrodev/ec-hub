<?php

declare(strict_types=1);

namespace App\Application\Product;

use App\Application\Recommendation\TrainedModelCacheInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Admin product CRUD use cases (Story 8.3), only through the repository port.
 *
 * Delete is a soft delete (FR106): the repository stamps deleted_at and the
 * product disappears from every public read, recommendations included.
 *
 * Story 10.1: a successful create/update/delete invalidates the cached KNN
 * model. This is hygiene, not correctness -- the cache is validated against a
 * fingerprint of the catalog, which also covers changes made outside this
 * class (seed, migrations, manual SQL). A failing invalidation is logged and
 * never breaks the CRUD.
 */
final class ManageProducts
{
    public const PER_PAGE = 20;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly ?TrainedModelCacheInterface $modelCache = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
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
        $id = $this->products->create($input->toData());
        $this->invalidateModelCache();

        return $id;
    }

    /**
     * False only when the product does not exist (or was deleted); true when
     * it exists, even if the submitted values were identical. The slug is
     * never regenerated, so public URLs survive a rename.
     */
    public function update(int $id, ProductInput $input): bool
    {
        // The repository reports changed rows, so false is ambiguous: an
        // identical resubmission (still true here) or a product that is
        // missing or was deleted concurrently.
        $updated = $this->products->update($id, $input->toData())
            || $this->products->findById($id) !== null;

        if ($updated) {
            $this->invalidateModelCache();
        }

        return $updated;
    }

    public function delete(int $id): bool
    {
        $deleted = $this->products->delete($id);

        if ($deleted) {
            $this->invalidateModelCache();
        }

        return $deleted;
    }

    private function invalidateModelCache(): void
    {
        if ($this->modelCache === null) {
            return;
        }

        try {
            $this->modelCache->invalidate();
        } catch (\Throwable $exception) {
            $this->logger->warning('Falha ao invalidar o cache do modelo KNN', ['error' => $exception->getMessage()]);
        }
    }
}
