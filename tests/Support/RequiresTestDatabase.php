<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOException;

trait RequiresTestDatabase
{
    protected function connectToTestDatabaseOrSkip(): PDO
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $username = getenv('DB_USERNAME') ?: 'root';
        $password = getenv('DB_PASSWORD') ?: 'secret';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION];

        try {
            $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $username, $password, $options);
        } catch (PDOException $exception) {
            $this->markTestSkipped('MySQL indisponível: ' . $exception->getMessage());
        }

        try {
            $server->exec('CREATE DATABASE IF NOT EXISTS ec_hub_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (PDOException $exception) {
            $this->markTestSkipped('Não foi possível criar ec_hub_test; conceda CREATE DATABASE ao usuário de testes: ' . $exception->getMessage());
        }

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port};dbname=ec_hub_test;charset=utf8mb4",
                $username,
                $password,
                $options + [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped('MySQL indisponível: ' . $exception->getMessage());
        }

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS products (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                price DECIMAL(10, 2) NOT NULL,
                category VARCHAR(100) NOT NULL,
                slug VARCHAR(255) NOT NULL,
                image_url VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY idx_products_slug (slug),
                INDEX idx_products_name (name),
                INDEX idx_products_category (category),
                INDEX idx_products_deleted_at (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // ec_hub_test databases created before Story 8.3 lack the soft-delete column.
        if ($pdo->query("SHOW COLUMNS FROM products LIKE 'deleted_at'")->fetch() === false) {
            $pdo->exec('ALTER TABLE products ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL');
        }

        // Story 8.7: orders (same DDL as bin/migrate.php).
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS orders (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_number VARCHAR(20) NOT NULL,
                customer_name VARCHAR(120) NOT NULL,
                customer_email VARCHAR(254) NOT NULL,
                shipping_address TEXT NOT NULL,
                status VARCHAR(20) NOT NULL,
                total DECIMAL(10, 2) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_orders_order_number (order_number),
                INDEX idx_orders_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS order_items (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                order_id BIGINT UNSIGNED NOT NULL,
                product_id BIGINT UNSIGNED NOT NULL,
                product_name VARCHAR(255) NOT NULL,
                unit_price DECIMAL(10, 2) NOT NULL,
                quantity INT UNSIGNED NOT NULL,
                INDEX idx_order_items_order_id (order_id),
                INDEX idx_order_items_product_id (product_id),
                CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);

        // TRUNCATE refuses a table referenced by a FK, even an empty one.
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        try {
            $pdo->exec('TRUNCATE TABLE order_items');
            $pdo->exec('TRUNCATE TABLE orders');
        } finally {
            // Never leave the connection with FK checks off, even on failure.
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $pdo->exec('TRUNCATE TABLE products');
        $seed = $pdo->prepare(
            'INSERT INTO products (name, description, price, category, slug, image_url)
             VALUES (:name, :description, :price, :category, :slug, :image_url)'
        );
        foreach ($this->testProducts() as $product) {
            $seed->execute($product);
        }

        return $pdo;
    }

    /** @return list<array{name: string, description: string, price: string, category: string, slug: string, image_url: string}> */
    private function testProducts(): array
    {
        return [
            ['name' => 'Camera Compacta', 'description' => 'Camera para fotos.', 'price' => '899.90', 'category' => 'Fotografia', 'slug' => 'camera-compacta', 'image_url' => '/camera.jpg'],
            ['name' => 'Monitor UltraWide', 'description' => 'Monitor para produtividade.', 'price' => '1599.90', 'category' => 'Informatica', 'slug' => 'monitor-ultrawide', 'image_url' => '/monitor.jpg'],
            ['name' => 'Notebook Pro', 'description' => 'Notebook para trabalho.', 'price' => '4299.90', 'category' => 'Informatica', 'slug' => 'notebook-pro', 'image_url' => '/notebook.jpg'],
            ['name' => 'Teclado Mecanico', 'description' => 'Teclado mecanico.', 'price' => '499.90', 'category' => 'Informatica', 'slug' => 'teclado-mecanico', 'image_url' => '/teclado.jpg'],
            ['name' => 'Webcam Full HD', 'description' => 'Webcam para videochamadas.', 'price' => '299.90', 'category' => 'Informatica', 'slug' => 'webcam-full-hd', 'image_url' => '/webcam.jpg'],
        ];
    }
}
