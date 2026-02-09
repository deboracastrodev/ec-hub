<?php

declare(strict_types=1);

use Database\Seeders\ProductSeeder;

require_once __DIR__ . '/../vendor/autoload.php';
if (!class_exists(ProductSeeder::class)) {
    require_once __DIR__ . '/../database/seeders/ProductSeeder.php';
}

$config = [
    'driver' => 'mysql',
    'host' => getenv('DB_HOST') ?: 'mysql',
    'port' => (int) (getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_DATABASE') ?: 'ec_hub',
    'username' => getenv('DB_USERNAME') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'charset' => 'utf8mb4',
];

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

    $seeder = new ProductSeeder($pdo);
    $summary = $seeder->run();

    echo "📊 Resumo:\n";
    echo "  Total de produtos: {$summary['total']}\n";
    echo "\n  Por categoria:\n";
    foreach ($summary['categories'] as $cat) {
        echo "    - {$cat['category']}: {$cat['count']}\n";
    }
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✨ Seeder concluído com sucesso!\n";
