<?php

declare(strict_types=1);

/**
 * Application Bootstrap
 *
 * This file is OUTSIDE the web root and contains sensitive initialization logic.
 * public/index.php should only call this and delegate to the router.
 */

// Load .env for local (non-Docker) development. Docker Compose already
// injects environment variables directly, and the immutable repository
// never overwrites a variable that's already set, so this is a no-op
// there. safeLoad() doesn't throw when no .env file exists (R5.8).
//
// phpdotenv's default adapters (ServerConstAdapter, EnvConstAdapter) only
// populate $_ENV/$_SERVER, not getenv() -- and this codebase reads
// getenv() everywhere. PutenvAdapter has to be added explicitly.
$envRepository = \Dotenv\Repository\RepositoryBuilder::createWithDefaultAdapters()
    ->addAdapter(\Dotenv\Repository\Adapter\PutenvAdapter::class)
    ->immutable()
    ->make();

\Dotenv\Dotenv::create($envRepository, dirname(__DIR__))->safeLoad();

return [
    // Lazy + memoized: only opens a DB connection when a consumer actually
    // resolves it, so requests that never touch the database (static assets,
    // 404s) don't pay for one. Call as $container['pdo']().
    'pdo' => (function (): callable {
        $pdo = null;

        return function () use (&$pdo): PDO {
            if ($pdo !== null) {
                return $pdo;
            }

            $config = [
                'db_host' => getenv('DB_HOST') ?: 'mysql',
                'db_port' => (int) (getenv('DB_PORT') ?: 3306),
                'db_database' => getenv('DB_DATABASE') ?: 'ec_hub',
                'db_username' => getenv('DB_USERNAME') ?: 'root',
                'db_password' => getenv('DB_PASSWORD') ?: '',
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
        };
    })(),

    'twig' => require __DIR__ . '/twig.php',

    // Built once here -- the single place config/recommendation.php is read
    // from disk. GenerateRecommendations and RuleBasedFallback receive the
    // resulting value object; neither touches the filesystem itself (R3.5).
    'recommendation_settings' => App\Domain\Recommendation\ValueObject\RecommendationSettings::fromArray(
        require __DIR__ . '/recommendation.php'
    ),

    'repositories' => [
        'product' => function (PDO $pdo) {
            return new App\Infrastructure\Persistence\MySQL\ProductRepository($pdo);
        },
    ],

    'services' => [
        'category' => function ($container) {
            return new App\Domain\Product\Service\CategoryService($container['repositories']['product']($container['pdo']()));
        },
        'knn' => function ($container) {
            return new App\Domain\Recommendation\Service\KNNService(
                $container['repositories']['product']($container['pdo']()),
                new App\Infrastructure\ML\RubixNeighborFinder()
            );
        },
        'rule_based_fallback' => function ($container) {
            return new App\Domain\Recommendation\Service\RuleBasedFallback(
                $container['repositories']['product']($container['pdo']()),
                $container['services']['logger']($container),
                $container['recommendation_settings']
            );
        },
        'generate_recommendations' => function ($container) {
            return new App\Application\Recommendation\GenerateRecommendations(
                $container['repositories']['product']($container['pdo']()),
                $container['services']['knn']($container),
                $container['services']['rule_based_fallback']($container),
                $container['services']['logger']($container),
                $container['recommendation_settings']
            );
        },
        'logger' => function ($container) {
            return new \Psr\Log\NullLogger();
        },
    ],
];
