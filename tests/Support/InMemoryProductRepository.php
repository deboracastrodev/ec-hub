<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;

/**
 * In-memory fake for view/template tests that need a repository but not a
 * real database (see R2.5 — tests/Integration/View never touches MySQL).
 */
class InMemoryProductRepository implements ProductRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $products;

    private int $nextId;

    /**
     * @param array<int, array<string, mixed>> $products
     */
    public function __construct(array $products = [])
    {
        $this->products = $products !== [] ? $products : self::defaultFixtures();
        $this->nextId = 1 + array_reduce(
            $this->products,
            static fn (int $max, array $p) => max($max, (int) $p['id']),
            0
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function defaultFixtures(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Mouse Gamer RGB',
                'slug' => 'mouse-gamer-rgb',
                'description' => 'Mouse gamer com iluminação RGB e 6 botões programáveis.',
                'price' => 149.90,
                'category' => 'Periféricos',
                'image_url' => '/assets/images/mouse.jpg',
                'created_at' => '2026-01-01 10:00:00',
            ],
            [
                'id' => 2,
                'name' => 'Teclado Mecânico',
                'slug' => 'teclado-mecanico',
                'description' => 'Teclado mecânico switch azul, ABNT2.',
                'price' => 299.90,
                'category' => 'Periféricos',
                'image_url' => '/assets/images/teclado.jpg',
                'created_at' => '2026-01-02 10:00:00',
            ],
        ];
    }

    public function findById(int $id): ?Product
    {
        foreach ($this->products as $product) {
            if ((int) $product['id'] === $id) {
                return Product::fromArray($product);
            }
        }

        return null;
    }

    public function findBySlug(string $slug): ?Product
    {
        foreach ($this->products as $product) {
            if (($product['slug'] ?? null) === $slug) {
                return Product::fromArray($product);
            }
        }

        return null;
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        return array_map(
            static fn (array $p): Product => Product::fromArray($p),
            array_slice($this->products, $offset, $limit)
        );
    }

    public function findByCategory(string $category, int $limit = 50): array
    {
        return $this->findByCategoryPaginated($category, $limit, 0);
    }

    public function findByCategoryPaginated(string $category, int $limit, int $offset): array
    {
        $filtered = array_values(array_filter(
            $this->products,
            static fn (array $p) => $p['category'] === $category
        ));

        return array_map(
            static fn (array $p): Product => Product::fromArray($p),
            array_slice($filtered, $offset, $limit)
        );
    }

    public function countByCategory(string $category): int
    {
        return count(array_filter(
            $this->products,
            static fn (array $p) => $p['category'] === $category
        ));
    }

    public function findCategories(): array
    {
        $categories = array_unique(array_column($this->products, 'category'));
        sort($categories);

        return array_values($categories);
    }

    public function count(): int
    {
        return count($this->products);
    }

    public function create(array $data): int
    {
        $id = $this->nextId++;
        $this->products[] = $data + ['id' => $id];

        return $id;
    }

    public function update(int $id, array $data): bool
    {
        foreach ($this->products as $index => $product) {
            if ((int) $product['id'] === $id) {
                $this->products[$index] = $data + ['id' => $id];

                return true;
            }
        }

        return false;
    }

    public function delete(int $id): bool
    {
        foreach ($this->products as $index => $product) {
            if ((int) $product['id'] === $id) {
                unset($this->products[$index]);
                $this->products = array_values($this->products);

                return true;
            }
        }

        return false;
    }
}
