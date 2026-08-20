<?php

declare(strict_types=1);

namespace Tests\Integration\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\RecommendationController;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Infrastructure\ML\RubixNeighborFinder;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for Story 3.5 (Confidence Scores & Explanations).
 *
 * Verifies the full flow -- API controller -> GenerateRecommendations ->
 * KNNService/RuleBasedFallback -- produces the AC8 response shape for both
 * the ML path and the rule-based fallback path.
 */
final class ConfidenceExplanationTest extends TestCase
{
    private \PDO $pdo;
    private ProductRepository $repository;
    private RecommendationController $controller;
    private GenerateRecommendations $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seedProducts();

        $this->repository = new ProductRepository($this->pdo);
        $logger = new NullLogger();
        $knnService = new KNNService($this->repository, new RubixNeighborFinder());
        $fallbackService = new RuleBasedFallback($this->repository, $logger);

        $this->service = new GenerateRecommendations(
            $this->repository,
            $knnService,
            $fallbackService,
            $logger
        );

        $this->controller = new RecommendationController($this->service, $logger);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('
            CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                description TEXT,
                price REAL NOT NULL,
                category TEXT NOT NULL,
                slug TEXT NOT NULL,
                image_url TEXT,
                created_at TEXT NOT NULL
            )
        ');
    }

    private function seedProducts(): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO products (name, description, price, category, slug, image_url, created_at)
            VALUES (:name, :description, :price, :category, :slug, :image_url, :created_at)
        ');

        $rows = [
            ['name' => 'Laptop Gamer', 'description' => '', 'price' => 4500.00, 'category' => 'Eletrônicos', 'slug' => 'laptop-gamer', 'image_url' => '', 'created_at' => '2024-01-01 00:00:00'],
            ['name' => 'Mouse Gamer', 'description' => '', 'price' => 150.00, 'category' => 'Eletrônicos', 'slug' => 'mouse-gamer', 'image_url' => '', 'created_at' => '2024-01-01 00:00:00'],
            ['name' => 'Teclado Mecanico', 'description' => '', 'price' => 350.00, 'category' => 'Eletrônicos', 'slug' => 'teclado-mecanico', 'image_url' => '', 'created_at' => '2024-01-01 00:00:00'],
            ['name' => 'Monitor 27', 'description' => '', 'price' => 1800.00, 'category' => 'Eletrônicos', 'slug' => 'monitor-27', 'image_url' => '', 'created_at' => '2024-01-01 00:00:00'],
            ['name' => 'Headset Gamer', 'description' => '', 'price' => 250.00, 'category' => 'Eletrônicos', 'slug' => 'headset-gamer', 'image_url' => '', 'created_at' => '2024-01-01 00:00:00'],
            ['name' => 'Webcam HD', 'description' => '', 'price' => 199.00, 'category' => 'Eletrônicos', 'slug' => 'webcam-hd', 'image_url' => '', 'created_at' => '2024-01-01 00:00:00'],
        ];

        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    public function testMlPathReturnsAc8Shape(): void
    {
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $targetProduct = $products[0];
        $targetProductId = (int) $targetProduct->getId();

        $response = $this->controller->getRecommendations(['product_id' => (string) $targetProductId]);

        $this->assertArrayHasKey('data', $response);
        $this->assertNotEmpty($response['data'], 'Enough catalog products exist for the ML path to fire');

        foreach ($response['data'] as $item) {
            $this->assertArrayHasKey('product_id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('price', $item);
            $this->assertArrayHasKey('score', $item);
            $this->assertArrayHasKey('score_label', $item);
            $this->assertArrayHasKey('explanation', $item);
            $this->assertArrayHasKey('reasons', $item);
            $this->assertArrayHasKey('source', $item);
            $this->assertArrayHasKey('confidence_level', $item);

            $this->assertContains($item['confidence_level'], ['high', 'medium', 'low']);
            $this->assertGreaterThanOrEqual(0, $item['score']);
            $this->assertLessThanOrEqual(100, $item['score']);
            $this->assertIsArray($item['reasons']);
            $this->assertLessThanOrEqual(3, count($item['reasons']), 'AC7: no more than 3 reasons');

            // AC3: explanation must follow the ExplanationGenerator template
            // ("Recomendado com base em [Produto] que você visualizou
            // ([Score]% de similaridade)"), not KNNService's own text --
            // this pins the override in GenerateRecommendations::formatRecommendations().
            $this->assertStringContainsString($targetProduct->getName(), $item['explanation']);
            $this->assertStringContainsString('% de similaridade', $item['explanation']);

            // AC7: ML recommendations always carry at least the similarity reason.
            $this->assertNotEmpty($item['reasons'], 'ML path must populate reasons via ExplanationGenerator::buildReasonsArray()');
            $this->assertSame('similarity', $item['reasons'][0]['type']);
        }
    }

    public function testFallbackPathReturnsAc8ShapeAndExplainsStrategy(): void
    {
        // Force fallback via the insufficientData flag (AC4).
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $targetProductId = (int) $products[0]->getId();

        $recommendations = $this->service->execute($targetProductId, 5, true);

        $this->assertNotEmpty($recommendations);

        foreach ($recommendations as $item) {
            $this->assertArrayHasKey('explanation', $item);
            $this->assertArrayHasKey('reasons', $item);
            $this->assertArrayHasKey('confidence_level', $item);
            $this->assertArrayHasKey('score_label', $item);
            $this->assertArrayHasKey('source', $item);
            $this->assertContains($item['source'], ['rules', 'popular']);
            $this->assertNotEmpty($item['explanation']);
        }
    }

    public function testColdStartFallbackForUnknownProductReturnsAc8Shape(): void
    {
        $recommendations = $this->service->execute(999999, 5);

        $this->assertNotEmpty($recommendations);
        $first = $recommendations[0];

        $this->assertSame('popular_product', $first['fallback_reason']);
        $this->assertSame('popular', $first['source']);
        $this->assertArrayHasKey('confidence_level', $first);
        $this->assertArrayHasKey('score_label', $first);
        $this->assertNotEmpty($first['explanation']);
    }
}
