<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

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

    echo "🧼 Limpando schema existente...\n";
    $pdo->exec('DROP TABLE IF EXISTS products');
    echo "✅ Tabela products removida\n\n";

    require __DIR__ . '/migrate.php';
} catch (PDOException $e) {
    echo "❌ Erro: " . $e->getMessage() . "\n";
    exit(1);
}
