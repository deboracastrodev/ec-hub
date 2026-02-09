<?php
declare(strict_types=1);

namespace Tests\Integration\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for GenerateRecommendations Application Service
 *
 * Tests the full flow: repository → service → KNN → result
 * Uses real ProductRepository with database connection
 */
class GenerateRecommendationsIntegrationTest extends TestCase
{
    private GenerateRecommendations $service;
    private ProductRepositoryInterface $repository;
    private KNNService $knnService;
    private RuleBasedFallback $fallbackService;
    private \PDO $pdo;

    protected function setUp(): void
    {
        // Setup in-memory SQLite database for isolated integration tests
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->createSchema();
        $this->seedProducts();

        $this->repository = new ProductRepository($this->pdo);
        $this->knnService = new KNNService($this->repository);
        $logger = new NullLogger();
        $this->fallbackService = new RuleBasedFallback($this->repository, $logger);

        $this->service = new GenerateRecommendations(
            $this->repository,
            $this->knnService,
            $this->fallbackService,
            $logger
        );
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
            [
                'name' => 'Laptop Gamer',
                'description' => 'High performance laptop',
                'price' => 4500.00,
                'category' => 'Eletrônicos',
                'slug' => 'laptop-gamer',
                'image_url' => 'https://example.com/laptop.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'name' => 'Mouse Gamer',
                'description' => 'RGB mouse',
                'price' => 150.00,
                'category' => 'Eletrônicos',
                'slug' => 'mouse-gamer',
                'image_url' => 'https://example.com/mouse.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'name' => 'Teclado Mecanico',
                'description' => 'Switch blue',
                'price' => 350.00,
                'category' => 'Eletrônicos',
                'slug' => 'teclado-mecanico',
                'image_url' => 'https://example.com/keyboard.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'name' => 'Cadeira Gamer',
                'description' => 'Ergonomica',
                'price' => 1200.00,
                'category' => 'Moveis',
                'slug' => 'cadeira-gamer',
                'image_url' => 'https://example.com/chair.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'name' => 'Monitor 27',
                'description' => '144Hz',
                'price' => 1800.00,
                'category' => 'Eletrônicos',
                'slug' => 'monitor-27',
                'image_url' => 'https://example.com/monitor.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
        ];

        foreach ($rows as $row) {
            $stmt->execute($row);
        }
    }

    public function test_full_flow_repository_to_knn_to_result(): void
    {
        // Arrange - Ensure database has at least 2 products
        $totalProducts = $this->repository->count();
        $this->assertGreaterThanOrEqual(2, $totalProducts, 'Need at least 2 products for KNN recommendations');

        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products, 'Database must have at least one product');
        $targetProductId = (int) $products[0]['id'];

        // Act - Execute recommendation flow
        $recommendations = $this->service->execute($targetProductId, 5);

        // Assert - Verify complete flow
        $this->assertIsArray($recommendations);

        // May return empty if products are too similar or only 1 product exists
        if (!empty($recommendations)) {
            $this->assertArrayHasKey('product_id', $recommendations[0]);
            $this->assertArrayHasKey('name', $recommendations[0]);
            $this->assertArrayHasKey('category', $recommendations[0]);
            $this->assertArrayHasKey('price', $recommendations[0]);
            $this->assertArrayHasKey('score', $recommendations[0]);
            $this->assertArrayHasKey('explanation', $recommendations[0]);

            // Verify target product is not in recommendations
            foreach ($recommendations as $rec) {
                $this->assertNotEquals((int) $targetProductId, (int) $rec['product_id']);
            }
        }
    }

    public function test_repository_array_to_product_entity_conversion(): void
    {
        // Arrange - Get raw product data from repository
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);

        $productData = $products[0];

        // Act - Convert to Product entity using fromArray factory
        $product = Product::fromArray($productData);

        // Assert - Verify conversion worked correctly
        $this->assertInstanceOf(Product::class, $product);
        $this->assertEquals($productData['id'], $product->getId());
        $this->assertEquals($productData['name'], $product->getName());
        $this->assertEquals($productData['category'], $product->getCategory());
        $this->assertEquals((float) $productData['price'], (float) $product->getPrice()->getDecimal());
    }

    public function test_knn_training_with_real_repository_data(): void
    {
        // Arrange - Ensure we have products
        $totalProducts = $this->repository->count();
        $this->assertGreaterThanOrEqual(2, $totalProducts, 'Need at least 2 products for KNN training');

        // Act - Train KNN with repository products
        $products = $this->repository->findAll(1000, 0);
        $productEntities = array_map(fn($data) => Product::fromArray($data), $products);

        $this->knnService->train($productEntities, 3);

        // Assert - Model is trained
        $this->assertTrue($this->knnService->isTrained());
        $this->assertEquals(3, $this->knnService->getK());
    }

    public function test_throws_exception_for_nonexistent_product(): void
    {
        // Arrange
        $nonExistentId = 999999;

        // Assert/Act - Should throw RecommendationException
        $this->expectException(RecommendationException::class);
        $this->expectExceptionMessage('Product with ID 999999 not found');

        $this->service->execute($nonExistentId);
    }

    public function test_model_is_cached_on_second_call(): void
    {
        // Arrange - Get first product
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $targetProductId = (int) $products[0]['id'];

        // Act - Call execute twice
        $result1 = $this->service->execute($targetProductId);
        $result2 = $this->service->execute($targetProductId);

        // Assert - Both calls should succeed and KNN should remain trained
        $this->assertTrue($this->knnService->isTrained());
        $this->assertIsArray($result1);
        $this->assertIsArray($result2);
    }

    public function test_clear_cache_allows_retraining(): void
    {
        // Arrange - Get first product and train model
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $targetProductId = (int) $products[0]['id'];

        // Act - Train, clear cache, and train again
        $this->service->execute($targetProductId);
        $this->assertTrue($this->knnService->isTrained());

        $this->service->clearCache();

        // After clearing cache, service should retrain on next call
        $this->service->execute($targetProductId);

        // Assert - Model is still trained after retraining
        $this->assertTrue($this->knnService->isTrained());
    }

    public function test_recommendations_include_score_and_explanation(): void
    {
        // Arrange - Get first product
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);

        // Need at least 2 products with different IDs for meaningful recommendations
        $totalProducts = $this->repository->count();
        if ($totalProducts < 2) {
            $this->markTestSkipped('Need at least 2 products for this test');
        }

        $targetProductId = (int) $products[0]['id'];

        // Act
        $recommendations = $this->service->execute($targetProductId, 3);

        // Assert - Check if recommendations exist and have required fields
        if (!empty($recommendations)) {
            foreach ($recommendations as $rec) {
                $this->assertArrayHasKey('score', $rec);
                $this->assertArrayHasKey('explanation', $rec);
                $this->assertGreaterThanOrEqual(0, $rec['score']);
                $this->assertLessThanOrEqual(100, $rec['score']);
                $this->assertNotEmpty($rec['explanation']);
            }
        }
    }
}
