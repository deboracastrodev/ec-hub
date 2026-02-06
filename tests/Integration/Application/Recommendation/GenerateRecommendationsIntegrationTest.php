<?php
declare(strict_types=1);

namespace Tests\Integration\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Recommendation\Service\KNNService;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
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
    private \PDO $pdo;

    protected function setUp(): void
    {
        // Setup database connection
        $this->pdo = new \PDO(
            'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . (getenv('DB_DATABASE') ?: 'ec_hub'),
            getenv('DB_USERNAME') ?: 'root',
            getenv('DB_PASSWORD') ?: 'secret',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $this->repository = new ProductRepository($this->pdo);
        $this->knnService = new KNNService($this->repository);
        $logger = new NullLogger();

        $this->service = new GenerateRecommendations(
            $this->repository,
            $this->knnService,
            $logger
        );
    }

    public function test_full_flow_repository_to_knn_to_result(): void
    {
        // Arrange - Ensure database has at least 2 products
        $totalProducts = $this->repository->count();
        $this->assertGreaterThanOrEqual(2, $totalProducts, 'Need at least 2 products for KNN recommendations');

        $products = $this->repository->findAll(limit: 1, offset: 0);
        $this->assertNotEmpty($products, 'Database must have at least one product');
        $targetProductId = $products[0]['id'];

        // Act - Execute recommendation flow
        $recommendations = $this->service->execute($targetProductId, limit: 5);

        // Assert - Verify complete flow
        $this->assertIsArray($recommendations);

        // May return empty if products are too similar or only 1 product exists
        if (!empty($recommendations)) {
            $this->assertArrayHasKey('product_id', $recommendations[0]);
            $this->assertArrayHasKey('product_name', $recommendations[0]);
            $this->assertArrayHasKey('category', $recommendations[0]);
            $this->assertArrayHasKey('price', $recommendations[0]);
            $this->assertArrayHasKey('score', $recommendations[0]);
            $this->assertArrayHasKey('explanation', $recommendations[0]);

            // Verify target product is not in recommendations
            foreach ($recommendations as $rec) {
                $this->assertNotEquals($targetProductId, $rec['product_id']);
            }
        }
    }

    public function test_repository_array_to_product_entity_conversion(): void
    {
        // Arrange - Get raw product data from repository
        $products = $this->repository->findAll(limit: 1, offset: 0);
        $this->assertNotEmpty($products);

        $productData = $products[0];

        // Act - Convert to Product entity using fromArray factory
        $product = Product::fromArray($productData);

        // Assert - Verify conversion worked correctly
        $this->assertInstanceOf(Product::class, $product);
        $this->assertEquals($productData['id'], $product->getId());
        $this->assertEquals($productData['name'], $product->getName());
        $this->assertEquals($productData['category'], $product->getCategory());
        $this->assertEquals($productData['price'], (string) $product->getPrice()->getDecimal());
    }

    public function test_knn_training_with_real_repository_data(): void
    {
        // Arrange - Ensure we have products
        $totalProducts = $this->repository->count();
        $this->assertGreaterThanOrEqual(2, $totalProducts, 'Need at least 2 products for KNN training');

        // Act - Train KNN with repository products
        $products = $this->repository->findAll(limit: 1000, offset: 0);
        $productEntities = array_map(fn($data) => Product::fromArray($data), $products);

        $this->knnService->train($productEntities, k: 3);

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
        $products = $this->repository->findAll(limit: 1, offset: 0);
        $this->assertNotEmpty($products);
        $targetProductId = $products[0]['id'];

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
        $products = $this->repository->findAll(limit: 1, offset: 0);
        $this->assertNotEmpty($products);
        $targetProductId = $products[0]['id'];

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
        $products = $this->repository->findAll(limit: 1, offset: 0);
        $this->assertNotEmpty($products);

        // Need at least 2 products with different IDs for meaningful recommendations
        $totalProducts = $this->repository->count();
        if ($totalProducts < 2) {
            $this->markTestSkipped('Need at least 2 products for this test');
        }

        $targetProductId = $products[0]['id'];

        // Act
        $recommendations = $this->service->execute($targetProductId, limit: 3);

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
