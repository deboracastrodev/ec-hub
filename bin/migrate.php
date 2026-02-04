<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Database configuration from Docker environment variables
$config = [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_DATABASE') ?: 'ec_hub',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

// Create PDO connection
$dsn = sprintf(
    '%s:host=%s;port=%d;dbname=%s;charset=%s',
    $config['driver'],
    $config['host'],
    $config['port'],
    $config['database'],
    $config['charset']
);

try {
    $pdo = new PDO(
        $dsn,
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    echo "✅ Conectado ao banco de dados\n";

    // Create products table
    $sql = "CREATE TABLE IF NOT EXISTS products (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        price DECIMAL(10, 2) NOT NULL,
        category VARCHAR(100) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        image_url VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY idx_products_slug (slug),
        INDEX idx_products_name (name),
        INDEX idx_products_category (category)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sql);
    echo "✅ Tabela 'products' criada com sucesso\n";

    // Ensure slug column exists for older installations
    $columnStmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'slug'");
    $hasSlugColumn = (bool) $columnStmt->fetch();

    if (!$hasSlugColumn) {
        echo "ℹ️  Adicionando coluna 'slug' em tabela existente...\n";
        $pdo->exec("ALTER TABLE products ADD COLUMN slug VARCHAR(255) NOT NULL AFTER category");
        $pdo->exec("CREATE UNIQUE INDEX idx_products_slug ON products(slug)");
    }

    // Backfill slug data when necessary
    $missingSlugStmt = $pdo->query("SELECT id, name FROM products WHERE slug IS NULL OR slug = ''");
    $updateStmt = $pdo->prepare("UPDATE products SET slug = :slug WHERE id = :id");

    while ($row = $missingSlugStmt->fetch(PDO::FETCH_ASSOC)) {
        $generatedSlug = generateUniqueSlug($pdo, slugify($row['name']), (int) $row['id']);
        $updateStmt->execute([
            'slug' => $generatedSlug,
            'id' => (int) $row['id'],
        ]);
    }

    // Show indexes
    $stmt = $pdo->query("SHOW INDEX FROM products");
    $indexes = $stmt->fetchAll();
    echo "\n📋 Índices criados:\n";
    foreach ($indexes as $index) {
        echo "  - {$index['Key_name']} ({$index['Column_name']})\n";
    }

} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✨ Migration concluída com sucesso!\n";

function slugify(string $value): string
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

function generateUniqueSlug(PDO $pdo, string $baseSlug, int $ignoreId = 0): string
{
    $slug = $baseSlug;
    $suffix = 1;

    while (slugExists($pdo, $slug, $ignoreId)) {
        $slug = $baseSlug . '-' . $suffix;
        ++$suffix;
    }

    return $slug;
}

function slugExists(PDO $pdo, string $slug, int $ignoreId): bool
{
    $sql = "SELECT id FROM products WHERE slug = :slug";
    $params = ['slug' => $slug];

    if ($ignoreId > 0) {
        $sql .= " AND id != :id";
        $params['id'] = $ignoreId;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
}
