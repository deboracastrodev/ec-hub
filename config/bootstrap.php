<?php

declare(strict_types=1);

/**
 * Application Bootstrap
 *
 * This file is OUTSIDE the web root and contains sensitive initialization logic.
 * public/index.php should only call this and delegate to the router.
 */

use App\Application\Cart\CartSummary;
use App\Application\Cart\ManageCart;
use App\Application\Event\TrackProductInteraction;
use App\Application\Monitoring\ExportMetrics;
use App\Application\Monitoring\HealthCheck;
use App\Application\Monitoring\HttpMetricsRepositoryInterface;
use App\Application\Monitoring\HttpRequestRecorder;
use App\Application\Monitoring\MemoryMonitor;
use App\Application\Monitoring\PrometheusFormatter;
use App\Application\Product\GetProductDetail;
use App\Application\Product\GetProductList;
use App\Application\Product\ManageProducts;
use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Application\SEO\Service\MetaTagsService;
use App\Controller\AbTestResultsController;
use App\Controller\Admin\AdminAuthController;
use App\Controller\Admin\AdminProductController;
use App\Controller\CartController;
use App\Controller\HealthCheckController;
use App\Controller\MemoryMonitoringController;
use App\Controller\MetricsController;
use App\Controller\MetricsExportController;
use App\Controller\ProductController;
use App\Controller\ProductInteractionController;
use App\Controller\RecommendationController;
use App\Domain\Event\EventBusStatusInterface;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Event\EventPublisherInterface;
use App\Domain\Event\EventStoreInterface;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Product\Service\CategoryService;
use App\Domain\Recommendation\Repository\AlgorithmMetricsRepositoryInterface;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\Service\CollaborativeFilteringService;
use App\Domain\Recommendation\Service\ExplanationGenerator;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\NeighborFinderInterface;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Recommendation\Utility\ConfidenceCalculator;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use App\Infrastructure\Messaging\RedisEventBus;
use App\Infrastructure\Messaging\RedisEventStore;
use App\Infrastructure\ML\RubixNeighborFinder;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use App\Infrastructure\Redis\RedisAlgorithmMetricsRepository;
use App\Infrastructure\Redis\RedisEventHistoryRepository;
use App\Infrastructure\Redis\RedisHttpMetricsRepository;
use App\Infrastructure\Redis\SessionRepository;
use App\Shared\Container\Container;
use App\Shared\Http\AdminAuth;
use App\Shared\Http\AdminCredentials;
use App\Shared\Http\SessionContext;
use App\Shared\Http\SessionCsrf;
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

// Story 8.1/8.2: algorithm name -> RecommendationStrategy. Shared by the
// RecommendationStrategy entry (the .env default) and the A/B experiment's
// use-case factory (the assigned arm).
$strategyFor = static fn (ContainerInterface $c, string $algorithm): RecommendationStrategy => match ($algorithm) {
    KNNService::NAME => $c->get(KNNService::class),
    CollaborativeFilteringService::NAME => $c->get(CollaborativeFilteringService::class),
    // Reachable only if RecommendationSettings::ALGORITHMS grows without
    // a matching arm here -- never silently fall back to KNN.
    default => throw new \LogicException(sprintf(
        'Algoritmo de recomendação sem estratégia registrada: "%s".',
        $algorithm
    )),
};

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

    // Story 8.6: every page's header shows the cart item count. It reads the
    // session only when a valid session cookie already exists (currentId()
    // never opens one), through its own client with short timeouts, and
    // degrades to a plain "Carrinho" link on any failure (CartSummary).
    Environment::class => function (ContainerInterface $c) use ($sessionConfig): Environment {
        /** @var Environment $twig */
        $twig = require __DIR__ . '/twig.php';
        $twig->addGlobal('cart_summary', new CartSummary(
            fn (): ?string => $c->get(SessionContext::class)->currentId(),
            fn (): SessionRepositoryInterface => new SessionRepository(
                new Client([
                    'scheme' => 'tcp',
                    ...require __DIR__ . '/redis.php',
                    'timeout' => 0.25,
                    'read_write_timeout' => 0.25,
                ]),
                $sessionConfig['ttl']
            )
        ));

        return $twig;
    },

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

    EventStoreInterface::class => fn (ContainerInterface $c) => new RedisEventStore(
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

    // Story 8.6: CSRF token of the visitor forms (cart), bound to the session id.
    SessionCsrf::class => fn () => new SessionCsrf($sessionConfig['cookie_secret']),

    // Story 8.3: single-admin panel. Invalid/missing credentials only disable
    // /admin (fail-closed); config/admin.php never throws.
    AdminCredentials::class => fn () => AdminCredentials::fromArray(require __DIR__ . '/admin.php'),

    AdminAuth::class => fn (ContainerInterface $c) => new AdminAuth(
        $c->get(AdminCredentials::class),
        $sessionConfig['cookie_secret']
    ),

    ManageProducts::class => fn (ContainerInterface $c) => new ManageProducts(
        $c->get(ProductRepositoryInterface::class)
    ),

    AdminAuthController::class => fn (ContainerInterface $c) => new AdminAuthController(
        $c->get(AdminAuth::class),
        $c->get(SessionContext::class),
        $c->get(Environment::class)
    ),

    AdminProductController::class => fn (ContainerInterface $c) => new AdminProductController(
        $c->get(ManageProducts::class),
        $c->get(AdminAuth::class),
        $c->get(Environment::class)
    ),

    TrackProductInteraction::class => fn (ContainerInterface $c) => new TrackProductInteraction(
        $c->get(ProductRepositoryInterface::class),
        $c->get(SessionRepositoryInterface::class),
        $c->get(EventHistoryRepositoryInterface::class),
        $c->get(EventStoreInterface::class),
        $c->get(EventPublisherInterface::class),
        $c->get(LoggerInterface::class)
    ),

    // Story 8.6: cart page. Adding still goes through TrackProductInteraction.
    ManageCart::class => fn (ContainerInterface $c) => new ManageCart(
        $c->get(SessionRepositoryInterface::class),
        $c->get(ProductRepositoryInterface::class)
    ),

    CartController::class => fn (ContainerInterface $c) => new CartController(
        $c->get(ManageCart::class),
        $c->get(TrackProductInteraction::class),
        $c->get(SessionContext::class),
        $c->get(SessionCsrf::class),
        $c->get(Environment::class)
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
        $c->get(SessionContext::class),
        $c->get(SessionCsrf::class)
    ),

    ProductInteractionController::class => fn (ContainerInterface $c) => new ProductInteractionController(
        $c->get(TrackProductInteraction::class),
        $c->get(SessionContext::class)
    ),

    MetricsController::class => fn (ContainerInterface $c) => new MetricsController(
        $c->get(EventHistoryRepositoryInterface::class),
        $c->get(Environment::class),
        $c->get(SessionRepositoryInterface::class),
        $c->get(EventBusStatusInterface::class),
        // Story 8.2: lazy, so invalid A/B config or Redis down only degrades the panel.
        fn (): array => $c->get(RecommendationExperiment::class)->results()
    ),

    MemoryMonitor::class => fn () => new MemoryMonitor(
        (int) ($GLOBALS['EC_HUB_MEMORY_BASELINE'] ?? memory_get_usage())
    ),

    MemoryMonitoringController::class => fn (ContainerInterface $c) => new MemoryMonitoringController(
        $c->get(MemoryMonitor::class)
    ),

    // Story 8.4: global HTTP metrics (public/index.php records every routed
    // request) and GET /api/metrics, which aggregates them with the existing
    // sources. Every source is lazy, so a broken one only nulls its section.
    // Story 8.4: written on every routed request, so it gets its own client
    // with short timeouts -- an unreachable Redis costs at most ~0.25 s per
    // request instead of Predis' default 5 s connect timeout.
    HttpMetricsRepositoryInterface::class => fn () => new RedisHttpMetricsRepository(
        new Client([
            'scheme' => 'tcp',
            ...require __DIR__ . '/redis.php',
            'timeout' => 0.25,
            'read_write_timeout' => 0.25,
        ])
    ),

    HttpRequestRecorder::class => fn (ContainerInterface $c) => new HttpRequestRecorder(
        $c->get(HttpMetricsRepositoryInterface::class),
        $c->get(LoggerInterface::class)
    ),

    ExportMetrics::class => fn (ContainerInterface $c) => new ExportMetrics(
        fn (): array => $c->get(HttpMetricsRepositoryInterface::class)->routes(),
        fn () => $c->get(MemoryMonitor::class)->snapshot(),
        fn (): array => $c->get(RecommendationExperiment::class)->results(),
        fn () => $c->get(EventBusStatusInterface::class)->status(),
    ),

    PrometheusFormatter::class => fn () => new PrometheusFormatter(),

    MetricsExportController::class => fn (ContainerInterface $c) => new MetricsExportController(
        $c->get(ExportMetrics::class),
        $c->get(PrometheusFormatter::class)
    ),

    HealthCheck::class => fn (ContainerInterface $c) => new HealthCheck(
        fn (): PDO => $c->get(PDO::class),
        fn (): Client => $c->get(Client::class),
    ),

    HealthCheckController::class => fn (ContainerInterface $c) => new HealthCheckController(
        $c->get(HealthCheck::class)
    ),

    NeighborFinderInterface::class => fn () => new RubixNeighborFinder(),

    KNNService::class => fn (ContainerInterface $c) => new KNNService(
        $c->get(ProductRepositoryInterface::class),
        $c->get(NeighborFinderInterface::class)
    ),

    CollaborativeFilteringService::class => fn (ContainerInterface $c) => new CollaborativeFilteringService(
        $c->get(EventStoreInterface::class),
        $c->get(ExplanationGenerator::class)
    ),

    // Story 8.1: the active algorithm comes from RECOMMENDATION_ALGORITHM,
    // already validated by RecommendationSettings (unknown values fail fast).
    RecommendationStrategy::class => fn (ContainerInterface $c) => $strategyFor(
        $c,
        $c->get(RecommendationSettings::class)->getAlgorithm()
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
        $c->get(RecommendationStrategy::class),
        $c->get(RuleBasedFallback::class),
        $c->get(LoggerInterface::class),
        $c->get(RecommendationSettings::class),
        $c->get(ExplanationGenerator::class),
        $c->get(EventHistoryRepositoryInterface::class)
    ),

    // Story 8.2: A/B testing between algorithms (RECOMMENDATION_AB_TEST).
    AbTestAssigner::class => fn () => new AbTestAssigner(),

    AlgorithmMetricsRepositoryInterface::class => fn (ContainerInterface $c) => new RedisAlgorithmMetricsRepository(
        $c->get(Client::class)
    ),

    RecommendationExperiment::class => fn (ContainerInterface $c) => new RecommendationExperiment(
        $c->get(RecommendationSettings::class),
        // Lazy: only the assigned arm's use case is built. The .env default
        // reuses the GenerateRecommendations entry; the other arm gets the
        // same collaborators with only the strategy swapped.
        static function (string $algorithm) use ($c, $strategyFor): GenerateRecommendations {
            if ($algorithm === $c->get(RecommendationSettings::class)->getAlgorithm()) {
                return $c->get(GenerateRecommendations::class);
            }

            return new GenerateRecommendations(
                $c->get(ProductRepositoryInterface::class),
                $strategyFor($c, $algorithm),
                $c->get(RuleBasedFallback::class),
                $c->get(LoggerInterface::class),
                $c->get(RecommendationSettings::class),
                $c->get(ExplanationGenerator::class),
                $c->get(EventHistoryRepositoryInterface::class)
            );
        },
        $c->get(AbTestAssigner::class),
        $c->get(AlgorithmMetricsRepositoryInterface::class),
        $c->get(LoggerInterface::class)
    ),

    AbTestResultsController::class => fn (ContainerInterface $c) => new AbTestResultsController(
        $c->get(RecommendationExperiment::class)
    ),

    RecommendationController::class => fn (ContainerInterface $c) => new RecommendationController(
        $c->get(GenerateRecommendations::class),
        $c->get(LoggerInterface::class),
        $c->get(SessionRepositoryInterface::class),
        $c->get(RecommendationExperiment::class)
    ),
]);
