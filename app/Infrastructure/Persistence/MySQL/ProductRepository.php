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

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function findAll(int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products ORDER BY name LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByCategory(string $category, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM products WHERE category = :category ORDER BY name LIMIT :limit");
        $stmt->bindValue(':category', $category, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) as count FROM products");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) $result['count'];
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO products (name, description, price, category, image_url)
            VALUES (:name, :description, :price, :category, :image_url)
        ");

        // Convert price to decimal if Money object was passed
        $price = $data['price'];
        if (is_object($price) && method_exists($price, 'getDecimal')) {
            $price = $price->getDecimal();
        }

        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'price' => $price,
            'category' => $data['category'],
            'image_url' => $data['image_url'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = ['id' => $id];

        foreach (['name', 'description', 'price', 'category', 'image_url'] as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);

        // Convert price to decimal if Money object was passed
        if (isset($params['price']) && is_object($params['price']) && method_exists($params['price'], 'getDecimal')) {
            $params['price'] = $params['price']->getDecimal();
        }

        return $stmt->execute($params) && $stmt->rowCount() > 0;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM products WHERE id = :id");

        return $stmt->execute(['id' => $id]) && $stmt->rowCount() > 0;
    }
}
