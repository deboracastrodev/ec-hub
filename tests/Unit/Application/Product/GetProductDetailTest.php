<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Product;

use App\Application\Product\GetProductDetail;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class GetProductDetailTest extends TestCase
{
    public function testExecuteByIdentifierResolvesSlug(): void
    {
        $repository = new InMemoryProductRepository([
            ['id' => 1, 'slug' => 'fone-bluetooth-sony', 'name' => 'Fone Bluetooth Sony'],
        ]);

        $useCase = new GetProductDetail($repository);

        $product = $useCase->executeByIdentifier('fone-bluetooth-sony');

        $this->assertNotNull($product);
        $this->assertSame('fone-bluetooth-sony', $product['slug']);
    }

    public function testExecuteByIdentifierFallsBackToNumericId(): void
    {
        $repository = new InMemoryProductRepository([
            ['id' => 42, 'slug' => 'notebook-gamer', 'name' => 'Notebook Gamer'],
        ]);

        $useCase = new GetProductDetail($repository);

        $product = $useCase->executeByIdentifier('42');

        $this->assertNotNull($product);
        $this->assertSame('Notebook Gamer', $product['name']);
    }
}

/**
 * @internal Helper repository used only for unit tests
 */
final class InMemoryProductRepository implements ProductRepositoryInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $products;

    /**
     * @param array<int, array<string, mixed>> $products
     */
    public function __construct(array $products)
    {
        $this->products = $products;
    }

    public function findById(int $id): ?array
    {
        foreach ($this->products as $product) {
            if ((int) $product['id'] === $id) {
                return $product;
            }
        }

        return null;
    }

    public function findBySlug(string $slug): ?array
    {
        foreach ($this->products as $product) {
            if (($product['slug'] ?? null) === $slug) {
                return $product;
            }
        }

        return null;
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        return array_slice($this->products, $offset, $limit);
    }

    public function findByCategory(string $category, int $limit = 50): array
    {
        return [];
    }

    public function findByCategoryPaginated(string $category, int $limit, int $offset): array
    {
        return [];
    }

    public function countByCategory(string $category): int
    {
        return 0;
    }

    public function findCategories(): array
    {
        return [];
    }

    public function count(): int
    {
        return count($this->products);
    }

    public function create(array $data): int
    {
        $this->products[] = $data;

        return (int) ($data['id'] ?? count($this->products));
    }

    public function update(int $id, array $data): bool
    {
        return false;
    }

    public function delete(int $id): bool
    {
        return false;
    }
}
