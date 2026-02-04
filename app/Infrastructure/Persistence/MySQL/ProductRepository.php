<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\MySQL;

use App\Domain\Product\Repository\ProductRepositoryInterface;
use PDO;

/**
 * MySQL Product Repository Implementation
 *
 * Implements the repository interface using PDO.
 * Returns arrays as defined in the interface contract.
 */
class ProductRepository implements ProductRepositoryInterface
{
    private PDO $pdo;
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $listCache = [];
    /** @var array<string, array<int, array<string, mixed>>> */
    private array $categoryPageCache = [];
    /** @var array<string, int> */
    private array $categoryCountCache = [];
    /** @var array<string, array<string, mixed>> */
    private array $slugCache = [];
    private ?int $totalCountCache = null;
    private ?array $categoriesCache = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row && isset($row['slug'])) {
            $this->slugCache[$row['slug']] = $row;
        }

        return $row ?: null;
    }

    public function findBySlug(string $slug): ?array
    {
        if (isset($this->slugCache[$slug])) {
            return $this->slugCache[$slug];
        }

        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE slug = :slug LIMIT 1");
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $this->slugCache[$slug] = $row;
        }

        return $row ?: null;
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        $cacheKey = $this->buildCacheKey([$limit, $offset]);
        if (isset($this->listCache[$cacheKey])) {
            return $this->listCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare("SELECT * FROM products ORDER BY name LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->listCache[$cacheKey] = $results;

        return $results;
    }

    public function findByCategory(string $category, int $limit = 50): array
    {
        return $this->findByCategoryPaginated($category, $limit, 0);
    }

    public function findByCategoryPaginated(string $category, int $limit, int $offset): array
    {
        $cacheKey = $this->buildCacheKey([$category, $limit, $offset]);
        if (isset($this->categoryPageCache[$cacheKey])) {
            return $this->categoryPageCache[$cacheKey];
        }

        $stmt = $this->pdo->prepare("
            SELECT * FROM products
            WHERE category = :category
            ORDER BY name
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':category', $category, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->categoryPageCache[$cacheKey] = $results;

        return $results;
    }

    public function countByCategory(string $category): int
    {
        if (isset($this->categoryCountCache[$category])) {
            return $this->categoryCountCache[$category];
        }

        $stmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM products WHERE category = :category");
        $stmt->execute(['category' => $category]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $count = (int) $result['count'];
        $this->categoryCountCache[$category] = $count;

        return $count;
    }

    public function findCategories(): array
    {
        if ($this->categoriesCache !== null) {
            return $this->categoriesCache;
        }

        $stmt = $this->pdo->query("
            SELECT DISTINCT category
            FROM products
            WHERE category IS NOT NULL AND category != ''
            ORDER BY category ASC
        ");

        $this->categoriesCache = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $this->categoriesCache;
    }

    public function count(): int
    {
        if ($this->totalCountCache !== null) {
            return $this->totalCountCache;
        }

        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM products");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->totalCountCache = (int) $result['count'];

        return $this->totalCountCache;
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO products (name, description, price, category, slug, image_url)
            VALUES (:name, :description, :price, :category, :slug, :image_url)
        ");

        // Convert price to decimal if Money object was passed
        $price = $data['price'];
        if (is_object($price) && method_exists($price, 'getDecimal')) {
            $price = $price->getDecimal();
        }

        $slugFromPayload = isset($data['slug']) && is_string($data['slug']) ? $data['slug'] : null;
        $baseSlug = $slugFromPayload ?: $this->slugify((string) $data['name']);
        $slug = $this->ensureUniqueSlug($baseSlug);

        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'price' => $price,
            'category' => $data['category'],
            'slug' => $slug,
            'image_url' => $data['image_url'] ?? null,
        ]);

        $this->resetCache();

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['name', 'description', 'price', 'category', 'slug', 'image_url'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        if (array_key_exists('slug', $params) && is_string($params['slug'])) {
            $params['slug'] = $this->ensureUniqueSlug($this->slugify($params['slug']), $id);
        }

        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);

        // Convert price to decimal if Money object was passed
        if (isset($params['price']) && is_object($params['price']) && method_exists($params['price'], 'getDecimal')) {
            $params['price'] = $params['price']->getDecimal();
        }

        $executed = $stmt->execute($params) && $stmt->rowCount() > 0;

        if ($executed) {
            $this->resetCache();
        }

        return $executed;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = :id");

        $executed = $stmt->execute(['id' => $id]) && $stmt->rowCount() > 0;

        if ($executed) {
            $this->resetCache();
        }

        return $executed;
    }

    /**
     * @param array<int, mixed> $parts
     */
    private function buildCacheKey(array $parts): string
    {
        return md5(json_encode($parts));
    }

    private function resetCache(): void
    {
        $this->listCache = [];
        $this->categoryPageCache = [];
        $this->categoryCountCache = [];
        $this->totalCountCache = null;
        $this->categoriesCache = null;
        $this->slugCache = [];
    }

    private function slugify(string $value): string
    {
        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($transliterated === false) {
            $transliterated = $value;
        }
        $slug = strtolower((string) $transliterated);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug ?? '');
        $slug = trim((string) $slug, '-');

        return $slug !== '' ? $slug : 'produto';
    }

    private function ensureUniqueSlug(string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = $baseSlug;
        $suffix = 1;

        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $baseSlug . '-' . $suffix;
            ++$suffix;
        }

        return $slug;
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        $sql = "SELECT id FROM products WHERE slug = :slug";
        $params = ['slug' => $slug];

        if ($ignoreId !== null) {
            $sql .= " AND id != :id";
            $params['id'] = $ignoreId;
        }

        $stmt = $this->pdo->prepare($sql . " LIMIT 1");
        $stmt->execute($params);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
