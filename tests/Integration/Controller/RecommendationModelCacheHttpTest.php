<?php

declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\RecommendationController;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Infrastructure\ML\CachedNeighborFinder;
use App\Infrastructure\ML\RubixNeighborFinder;
use App\Shared\Container\Container;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\InMemoryTrainedModelCache;
use Tests\Support\RecordingLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Story 10.1 (R4.4, FR126): two consecutive GET /api/recommendations through
 * public/index.php. Each request gets a brand-new container and object graph
 * -- exactly what a stateless PHP request does -- and they share only the
 * trained-model cache (and the catalog rows, standing in for the database).
 */
final class RecommendationModelCacheHttpTest extends TestCase
{
    private InMemoryTrainedModelCache $cache;
    private RecordingLogger $logger;
    private int $fits = 0;

    protected function setUp(): void
    {
        $this->cache = new InMemoryTrainedModelCache();
        $this->logger = new RecordingLogger();
        $this->fits = 0;
    }

    #[RunInSeparateProcess]
    public function testSecondRequestLoadsTheModelFromCacheInsteadOfTraining(): void
    {
        $rows = self::rows();

        $first = $this->request($rows);
        $second = $this->request($rows);

        self::assertSame(1, $this->fits, 'the index is fitted only by the first request');
        self::assertSame(
            ['Modelo KNN treinado', 'Modelo KNN carregado do cache'],
            $this->knnLogs()
        );
        self::assertSame('ml', $second['meta']['source']);
        self::assertSame('knn', $second['meta']['algorithm']);
        self::assertNotEmpty($second['data']);
        self::assertSame($first['data'], $second['data']);
        self::assertLessThan(200, $second['meta']['response_time_ms']);
    }

    #[RunInSeparateProcess]
    public function testChangedCatalogBetweenRequestsRetrains(): void
    {
        $rows = self::rows();
        $first = $this->request($rows);
        self::assertContains(2, array_column($first['data'], 'product_id'));

        // Product 2 removed straight in the "database" (no admin CRUD, no
        // explicit invalidation): the catalog fingerprint alone must catch it.
        $rows = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] !== 2));
        $second = $this->request($rows);

        self::assertSame(2, $this->fits);
        self::assertSame(['Modelo KNN treinado', 'Modelo KNN treinado'], $this->knnLogs());
        self::assertNotContains(2, array_column($second['data'], 'product_id'));
    }

    /**
     * One stateless request: new repository, finder, strategy, use case,
     * controller and container; only $this->cache survives between calls.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function request(array $rows): array
    {
        header_remove();
        http_response_code(200);

        $repository = new InMemoryProductRepository($rows);
        $finder = new CachedNeighborFinder($this->cache, $this->logger, function (array $products): RubixNeighborFinder {
            ++$this->fits;
            $finder = new RubixNeighborFinder();
            $finder->train($products);

            return $finder;
        });
        $useCase = new GenerateRecommendations(
            $repository,
            new KNNService($repository, $finder),
            new RuleBasedFallback($repository, $this->logger),
            $this->logger
        );
        $controller = new RecommendationController($useCase, $this->logger);
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/views'));
        $GLOBALS['EC_HUB_TEST_CONTAINER'] = new Container([
            Environment::class => fn () => $twig,
            RecommendationController::class => fn () => $controller,
        ]);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/recommendations?product_id=1&limit=4';
        $_GET = ['product_id' => '1', 'limit' => '4'];

        try {
            ob_start();
            require dirname(__DIR__, 3) . '/public/index.php';
            $decoded = json_decode((string) ob_get_clean(), true);
        } finally {
            unset($GLOBALS['EC_HUB_TEST_CONTAINER']);
        }

        self::assertSame(200, http_response_code());
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function knnLogs(): array
    {
        return array_values(array_filter(
            $this->logger->messages('info'),
            static fn (string $message): bool => str_starts_with($message, 'Modelo KNN')
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function rows(): array
    {
        $rows = [];
        foreach ([[1, 'Fone', 'Eletrônicos', 200.0], [2, 'Monitor', 'Eletrônicos', 250.0],
            [3, 'Bola', 'Esportes', 100.0], [4, 'Tênis', 'Esportes', 400.0],
            [5, 'Luminária', 'Casa', 250.0], [6, 'Mouse', 'Eletrônicos', 150.0],
            [7, 'Teclado', 'Eletrônicos', 300.0], [8, 'Cadeira', 'Casa', 700.0]] as [$id, $name, $category, $price]) {
            $rows[] = ['id' => $id, 'name' => $name, 'slug' => 'p-' . $id, 'description' => '',
                'price' => $price, 'category' => $category, 'image_url' => '', 'created_at' => '2026-01-01 10:00:00'];
        }

        return $rows;
    }
}
