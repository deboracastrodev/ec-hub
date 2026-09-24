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
        foreach ($this->activeProducts() as $product) {
            if ((int) $product['id'] === $id) {
                return Product::fromArray($product);
            }
        }

        return null;
    }

    public function findBySlug(string $slug): ?Product
    {
        foreach ($this->activeProducts() as $product) {
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
            array_slice($this->activeProducts(), $offset, $limit)
        );
    }

    public function findByCategory(string $category, int $limit = 50): array
    {
        return $this->findByCategoryPaginated($category, $limit, 0);
    }

    public function findByCategoryPaginated(string $category, int $limit, int $offset): array
    {
        $filtered = array_values(array_filter(
            $this->activeProducts(),
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
            $this->activeProducts(),
            static fn (array $p) => $p['category'] === $category
        ));
    }

    public function findCategories(): array
    {
        $categories = array_unique(array_column($this->activeProducts(), 'category'));
        sort($categories);

        return array_values($categories);
    }

    public function count(): int
    {
        return count($this->activeProducts());
    }

    public function create(array $data): int
    {
        $id = $this->nextId++;
        $slug = isset($data['slug']) && is_string($data['slug']) && $data['slug'] !== ''
            ? $data['slug']
            : $this->uniqueSlug((string) ($data['name'] ?? ''));
        $this->products[] = ['slug' => $slug] + $data + ['id' => $id, 'deleted_at' => null];

        return $id;
    }

    /**
     * Mirrors ProductRepository::update(): only present keys are written, a
     * present null/'' clears image_url/description, deleted rows are ignored.
     * Like MySQL's rowCount() (affected rows), it returns false when the row
     * exists but no value actually changes.
     */
    public function update(int $id, array $data): bool
    {
        foreach ($this->products as $index => $product) {
            if ((int) $product['id'] !== $id || ($product['deleted_at'] ?? null) !== null) {
                continue;
            }

            foreach (['name', 'price', 'category', 'slug'] as $field) {
                if (array_key_exists($field, $data) && $data[$field] !== null && $data[$field] !== '') {
                    $product[$field] = $data[$field];
                }
            }
            if (array_key_exists('description', $data)) {
                $product['description'] = (string) ($data['description'] ?? '');
            }
            if (array_key_exists('image_url', $data)) {
                $product['image_url'] = $data['image_url'] === '' ? null : $data['image_url'];
            }
            $changed = false;
            foreach ($product as $field => $value) {
                $previous = $this->products[$index][$field] ?? null;
                $changed = $changed || ($field === 'price'
                    ? (float) $previous !== (float) $value
                    : $previous !== $value);
            }
            $this->products[$index] = $product;

            return $changed;
        }

        return false;
    }

    /** Soft delete, like ProductRepository: the row stays, flagged with deleted_at. */
    public function delete(int $id): bool
    {
        foreach ($this->products as $index => $product) {
            if ((int) $product['id'] === $id && ($product['deleted_at'] ?? null) === null) {
                $this->products[$index]['deleted_at'] = date('Y-m-d H:i:s');

                return true;
            }
        }

        return false;
    }

    /** Raw row, including soft-deleted ones -- for assertions only. */
    public function rawRow(int $id): ?array
    {
        foreach ($this->products as $product) {
            if ((int) $product['id'] === $id) {
                return $product;
            }
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function activeProducts(): array
    {
        return array_values(array_filter(
            $this->products,
            static fn (array $p): bool => ($p['deleted_at'] ?? null) === null
        ));
    }

    /** Slugs stay reserved by deleted rows, like the global unique index. */
    private function uniqueSlug(string $name): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $base = $base !== '' ? $base : 'produto';
        $taken = array_column($this->products, 'slug');
        $slug = $base;
        for ($suffix = 1; in_array($slug, $taken, true); ++$suffix) {
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
