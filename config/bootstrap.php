<?php

declare(strict_types=1);

/**
 * Application Bootstrap
 *
 * This file is OUTSIDE the web root and contains sensitive initialization logic.
 * public/index.php should only call this and delegate to the router.
 */

use App\Application\Event\TrackProductInteraction;
use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Application\Recommendation\GenerateRecommendations;
use App\Application\SEO\Service\MetaTagsService;
use App\Controller\ProductController;
use App\Controller\ProductInteractionController;
use App\Controller\MetricsController;
use App\Controller\RecommendationController;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Event\EventBusStatusInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Product\Service\CategoryService;
use App\Domain\Recommendation\Service\ExplanationGenerator;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\NeighborFinderInterface;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Recommendation\Utility\ConfidenceCalculator;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Infrastructure\ML\RubixNeighborFinder;
use App\Infrastructure\Messaging\RedisEventBus;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use App\Infrastructure\Redis\SessionRepository;
use App\Infrastructure\Redis\RedisEventHistoryRepository;
use App\Shared\Container\Container;
use App\Shared\Http\SessionContext;
use Predis\Client;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Twig\Environment;

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

$sessionConfig = require __DIR__ . '/session.php';

// A PSR-11 container (R5.7): every entry is a factory keyed by FQCN,
// resolved lazily and memoized on first use. This is what keeps a request
// that never needs the database (static assets, 404s) from opening a PDO
// connection (R2.4) -- PDO::class's factory only runs if something actually
// asks the container for it.
return new Container([
    PDO::class => function (): PDO {
        $config = [
            'db_host' => getenv('DB_HOST') ?: 'mysql',
            'db_port' => (int) (getenv('DB_PORT') ?: 3306),
            'db_database' => getenv('DB_DATABASE') ?: 'ec_hub',
            'db_username' => getenv('DB_USERNAME') ?: 'root',
            'db_password' => getenv('DB_PASSWORD') ?: '',
        ];

        return new PDO(
            "mysql:host={$config['db_host']};port={$config['db_port']};dbname={$config['db_database']};charset=utf8mb4",
            $config['db_username'],
            $config['db_password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    },

    Environment::class => fn () => require __DIR__ . '/twig.php',

    // The single place config/recommendation.php is read from disk (R3.5).
    RecommendationSettings::class => fn () => RecommendationSettings::fromArray(
        require __DIR__ . '/recommendation.php'
    ),

    LoggerInterface::class => fn () => new NullLogger(),

    Client::class => fn () => new Client([
        'scheme' => 'tcp',
        ...require __DIR__ . '/redis.php',
    ]),

    EventPublisherInterface::class => fn (ContainerInterface $c) => new RedisEventBus(
        $c->get(Client::class)
    ),

    // Story 5.4: mesmo RedisEventBus, consultado pelo MetricsController como status observável.
    EventBusStatusInterface::class => fn (ContainerInterface $c) => $c->get(EventPublisherInterface::class),

    SessionRepositoryInterface::class => fn (ContainerInterface $c) => new SessionRepository(
        $c->get(Client::class),
        $sessionConfig['ttl']
    ),

    EventHistoryRepositoryInterface::class => fn (ContainerInterface $c) => new RedisEventHistoryRepository(
        $c->get(Client::class),
        $sessionConfig['ttl']
    ),

    SessionContext::class => fn () => new SessionContext($sessionConfig['cookie_secret']),

    TrackProductInteraction::class => fn (ContainerInterface $c) => new TrackProductInteraction(
        $c->get(ProductRepositoryInterface::class),
        $c->get(SessionRepositoryInterface::class),
        $c->get(EventHistoryRepositoryInterface::class),
        $c->get(EventPublisherInterface::class),
        $c->get(LoggerInterface::class)
    ),

    ProductRepositoryInterface::class => fn (ContainerInterface $c) => new ProductRepository(
        $c->get(PDO::class)
    ),

    CategoryService::class => fn (ContainerInterface $c) => new CategoryService(
        $c->get(ProductRepositoryInterface::class)
    ),

    MetaTagsService::class => fn () => new MetaTagsService(),

    GetProductList::class => fn (ContainerInterface $c) => new GetProductList(
        $c->get(ProductRepositoryInterface::class),
        $c->get(CategoryService::class)
    ),

    GetProductDetail::class => fn (ContainerInterface $c) => new GetProductDetail(
        $c->get(ProductRepositoryInterface::class)
    ),

    ProductController::class => fn (ContainerInterface $c) => new ProductController(
        $c->get(GetProductList::class),
        $c->get(GetProductDetail::class),
        $c->get(Environment::class),
        $c->get(MetaTagsService::class),
        $c->get(TrackProductInteraction::class),
        $c->get(SessionContext::class)
    ),

    ProductInteractionController::class => fn (ContainerInterface $c) => new ProductInteractionController(
        $c->get(TrackProductInteraction::class),
        $c->get(SessionContext::class)
    ),

    MetricsController::class => fn (ContainerInterface $c) => new MetricsController(
        $c->get(EventHistoryRepositoryInterface::class),
        $c->get(Environment::class),
        $c->get(SessionRepositoryInterface::class),
        $c->get(EventBusStatusInterface::class)
    ),

    NeighborFinderInterface::class => fn () => new RubixNeighborFinder(),

    KNNService::class => fn (ContainerInterface $c) => new KNNService(
        $c->get(ProductRepositoryInterface::class),
        $c->get(NeighborFinderInterface::class)
    ),

    // Story 3.5: confidence scores and explanations for recommendations.
    ConfidenceCalculator::class => fn () => new ConfidenceCalculator(),

    ExplanationGenerator::class => fn () => new ExplanationGenerator(),

    RuleBasedFallback::class => fn (ContainerInterface $c) => new RuleBasedFallback(
        $c->get(ProductRepositoryInterface::class),
        $c->get(LoggerInterface::class),
        $c->get(RecommendationSettings::class),
        $c->get(ExplanationGenerator::class),
        $c->get(ConfidenceCalculator::class)
    ),

    GenerateRecommendations::class => fn (ContainerInterface $c) => new GenerateRecommendations(
        $c->get(ProductRepositoryInterface::class),
        $c->get(KNNService::class),
        $c->get(RuleBasedFallback::class),
        $c->get(LoggerInterface::class),
        $c->get(RecommendationSettings::class),
        $c->get(ExplanationGenerator::class),
        $c->get(EventHistoryRepositoryInterface::class)
    ),

    RecommendationController::class => fn (ContainerInterface $c) => new RecommendationController(
        $c->get(GenerateRecommendations::class),
        $c->get(LoggerInterface::class),
        $c->get(SessionRepositoryInterface::class)
    ),
]);
