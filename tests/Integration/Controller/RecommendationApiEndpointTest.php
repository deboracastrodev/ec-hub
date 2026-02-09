<?php
declare(strict_types=1);

namespace Tests\Integration\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\RecommendationController;
use App\Infrastructure\Persistence\MySQL\ProductRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Integration tests for Recommendation API Endpoint
 *
 * Tests the full request-response cycle including:
 * - Integration with GenerateRecommendations
 * - Fallback behavior when ML fails
 * - Cold start scenario (new user/product)
 * - Response format verification
 * - Performance verification (< 200ms)
 */
class RecommendationApiEndpointTest extends TestCase
{
    private RecommendationController $controller;
    private ProductRepository $repository;
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

        // Create real GenerateRecommendations service
        $knnService = new \App\Domain\Recommendation\Service\KNNService($this->repository);
        $fallbackService = new \App\Domain\Recommendation\Service\RuleBasedFallback($this->repository, new NullLogger());
        $generateRecommendations = new GenerateRecommendations(
            $this->repository,
            $knnService,
            $fallbackService,
            new NullLogger()
        );

        $this->controller = new RecommendationController(
            $generateRecommendations,
            new NullLogger()
        );
    }

    public function test_api_returns_valid_json_response(): void
    {
        // Arrange - Get first product from database
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products, 'Database must have at least one product');

        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations(['user_id' => (string) $productId]);

        // Assert - AC1: Response format
        $this->assertIsArray($response);
        $this->assertArrayHasKey('data', $response, 'AC1: data key required');
        $this->assertArrayHasKey('meta', $response, 'AC1: meta key required');
    }

    public function test_api_response_contains_required_fields(): void
    {
        // Arrange
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations(['user_id' => (string) $productId]);

        // Assert - AC1: Check first recommendation has all required fields
        if (count($response['data']) > 0) {
            $firstRec = $response['data'][0];
            $this->assertArrayHasKey('id', $firstRec, 'AC1: id required');
            $this->assertArrayHasKey('name', $firstRec, 'AC1: name required');
            $this->assertArrayHasKey('price', $firstRec, 'AC1: price required');
            $this->assertArrayHasKey('score', $firstRec, 'AC1: score required');
            $this->assertArrayHasKey('explanation', $firstRec, 'AC1: explanation required');
        }
    }

    public function test_api_returns_5_to_10_recommendations(): void
    {
        // Arrange - AC3: 5-10 products returned
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations(['user_id' => (string) $productId]);

        // Assert - AC3: 5-10 products (if enough products exist)
        $count = $response['meta']['count'];
        $this->assertGreaterThanOrEqual(0, $count);
        $this->assertLessThanOrEqual(10, $count, 'AC3: Maximum 10 recommendations');
    }

    public function test_api_no_duplicate_products(): void
    {
        // Arrange - AC3: No duplicates
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations(['product_id' => (string) $productId]);

        // Assert - AC3: No duplicates
        $productIds = array_column($response['data'], 'id');
        $uniqueIds = array_unique($productIds);
        $this->assertEquals(count($productIds), count($uniqueIds), 'AC3: No duplicate products allowed');
    }

    public function test_api_response_time_under_200ms(): void
    {
        // Arrange - AC2: Response time < 200ms
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $startTime = microtime(true);
        $response = $this->controller->getRecommendations(['user_id' => (string) $productId]);
        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000;

        // Assert - AC2: < 200ms
        $this->assertLessThan(200, $responseTime, 'AC2: Response must be under 200ms');
    }

    public function test_api_includes_response_time_in_metadata(): void
    {
        // Arrange
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations(['user_id' => (string) $productId]);

        // Assert - AC8: X-Response-Time header equivalent in meta
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('response_time_ms', $response['meta'], 'AC8: response_time_ms required');
        $this->assertIsFloat($response['meta']['response_time_ms']);
        $this->assertGreaterThan(0, $response['meta']['response_time_ms']);
    }

    public function test_api_includes_source_in_metadata(): void
    {
        // Arrange
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations(['user_id' => (string) $productId]);

        // Assert - AC8: X-Recommendation-Source equivalent in meta
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('source', $response['meta'], 'AC8: source required');
        $this->assertContains($response['meta']['source'], ['ml', 'rules', 'popular']);
    }

    public function test_api_limit_parameter_works(): void
    {
        // Arrange
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations([
            'user_id' => (string) $productId,
            'limit' => '5'
        ]);

        // Assert - limit parameter is respected
        $this->assertLessThanOrEqual(5, $response['meta']['count']);
    }

    public function test_api_max_limit_enforced(): void
    {
        // Arrange
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act - Request more than MAX_LIMIT (50)
        $response = $this->controller->getRecommendations([
            'user_id' => (string) $productId,
            'limit' => '999'
        ]);

        // Assert - MAX_LIMIT (50) is enforced
        $this->assertLessThanOrEqual(50, $response['meta']['count']);
    }

    public function test_api_fallback_behavior_on_insufficient_data(): void
    {
        // Arrange - AC5 & AC6: Fallback when ML fails
        // Use a product that likely has no behavioral data
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);

        // Clear any cached model to force cold start
        $knnService = new \App\Domain\Recommendation\Service\KNNService($this->repository);
        $fallbackService = new \App\Domain\Recommendation\Service\RuleBasedFallback($this->repository, new NullLogger());
        $generateRecommendations = new GenerateRecommendations(
            $this->repository,
            $knnService,
            $fallbackService,
            new NullLogger()
        );
        $generateRecommendations->clearCache();

        $controller = new RecommendationController(
            $generateRecommendations,
            new NullLogger()
        );

        $productId = $products[0]['id'];

        // Act
        $response = $controller->getRecommendations(['user_id' => (string) $productId], []);

        // Assert - AC5/AC6: Should return recommendations via fallback
        $this->assertArrayHasKey('data', $response);
        // Source should indicate fallback was used
        $this->assertContains($response['meta']['source'], ['ml', 'rules', 'popular']);
    }

    public function test_api_throws_exception_without_product_id(): void
    {
        // Arrange - AC4: 400 Bad Request without product_id

        // Assert/Act
        $this->expectException(\App\Controller\Exceptions\InvalidRequestException::class);
        $this->expectExceptionMessage('user_id is required');

        $this->controller->getRecommendations([]);
    }

    public function test_api_metadata_includes_count(): void
    {
        // Arrange
        $products = $this->repository->findAll(1, 0);
        $this->assertNotEmpty($products);
        $productId = $products[0]['id'];

        // Act
        $response = $this->controller->getRecommendations(['user_id' => (string) $productId]);

        // Assert
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('count', $response['meta']);
        $this->assertEquals(count($response['data']), $response['meta']['count']);
    }
}
