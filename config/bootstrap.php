<?php

declare(strict_types=1);

/**
 * Application Bootstrap
 *
 * This file is OUTSIDE the web root and contains sensitive initialization logic.
 * public/index.php should only call this and delegate to the router.
 */

return [
    'pdo' => (function (): PDO {
        // Database configuration from Docker environment variables
        $config = [
            'db_host' => getenv('DB_HOST') ?: 'mysql',
            'db_port' => (int) (getenv('DB_PORT') ?: 3306),
            'db_database' => getenv('DB_DATABASE') ?: 'ec_hub',
            'db_username' => getenv('DB_USERNAME') ?: 'root',
            'db_password' => getenv('DB_PASSWORD') ?: '',
            'app_debug' => getenv('APP_DEBUG') ?: 'false',
        ];

        $pdo = new PDO(
            "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_database']};charset=utf8mb4",
            $config['db_username'],
            $config['db_password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        return $pdo;
    })(),

    'twig' => require __DIR__ . '/twig.php',

    'repositories' => [
        'product' => function (PDO $pdo) {
            return new App\Infrastructure\Persistence\MySQL\ProductRepository($pdo);
        },
    ],
];
