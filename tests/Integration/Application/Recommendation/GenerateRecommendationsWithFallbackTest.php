<?php
declare(strict_types=1);

namespace Tests\Integration\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Integration tests for GenerateRecommendations with Fallback
 *
 * These tests verify that GenerateRecommendations properly integrates
 * with RuleBasedFallback when ML has insufficient data.
 */
class GenerateRecommendationsWithFallbackTest extends TestCase
{
    private GenerateRecommendations $service;
    private ProductRepositoryInterface $mockRepository;
    private KNNService $mockKNNService;
    private RuleBasedFallback $mockFallback;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->mockKNNService = $this->createMock(KNNService::class);
        $this->mockFallback = $this->createMock(RuleBasedFallback::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->service = new GenerateRecommendations(
            $this->mockRepository,
            $this->mockKNNService,
            $this->mockFallback,
            $this->mockLogger
        );
    }

    public function testUsesFallbackWhenInsufficientProducts(): void
    {
        // Arrange - only 2 products (less than MIN_PRODUCTS_FOR_ML = 5)
        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([
                ['id' => 1, 'name' => 'Product 1', 'category' => 'Cat1', 'price' => '100.00', 'description' => '', 'image_url' => '', 'created_at' => '2024-01-01'],
                ['id' => 2, 'name' => 'Product 2', 'category' => 'Cat1', 'price' => '200.00', 'description' => '', 'image_url' => '', 'created_at' => '2024-01-01'],
            ]);

        $this->mockRepository->expects($this->once())
            ->method('findById')
            ->willReturn(['id' => 1, 'name' => 'Product 1', 'category' => 'Cat1', 'price' => '100.00', 'description' => '', 'image_url' => '', 'created_at' => '2024-01-01']);

        $fallbackRecs = [
            ['product_id' => 2, 'product_name' => 'Product 2', 'score' => 60.0, 'explanation' => 'Fallback (rule-based): Category match', 'fallback_reason' => 'category'],
        ];

        $this->mockFallback->expects($this->once())
            ->method('getRecommendations')
            ->willReturn($fallbackRecs);

        // KNN should NOT be trained
        $this->mockKNNService->expects($this->never())
            ->method('train');

        $this->mockKNNService->expects($this->never())
            ->method('isTrained');

        // Act
        $result = $this->service->execute(1, 1);

        // Assert
        $this->assertEquals($fallbackRecs[0]['product_id'], $result[0]['product_id']);
        $this->assertEquals('Product 2', $result[0]['name']);
        $this->assertArrayHasKey('fallback_reason', $result[0]);
    }

    public function testUsesMLWhenEnoughProducts(): void
    {
        // Arrange - enough products
        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($this->getEnoughProducts());

        $this->mockRepository->expects($this->once())
            ->method('findById')
            ->willReturn(['id' => 1, 'name' => 'Product 1', 'category' => 'Cat1', 'price' => '100.00', 'description' => '', 'image_url' => '', 'created_at' => '2024-01-01']);

        $this->mockKNNService->expects($this->once())
            ->method('isTrained')
            ->willReturn(false);

        $this->mockKNNService->expects($this->once())
            ->method('train');

        // Fallback should NOT be called
        $this->mockFallback->expects($this->never())
            ->method('getRecommendations');

        $this->mockKNNService->expects($this->once())
            ->method('recommend')
            ->willReturn([
                new \App\Domain\Recommendation\Model\RecommendationResult(
                    2,
                    'Product 2',
                    'Cat1',
                    'R$ 100,00',
                    85.0,
                    1,
                    'Recomendado porque você visualizou "Product 1"'
                ),
            ]);

        // Act
        $result = $this->service->execute(1, 1);

        // Assert
        $this->assertIsArray($result);
        $this->assertCount(1, $result);
    }

    public function testUsesFallbackWhenInsufficientDataFlagIsTrue(): void
    {
        // Arrange - enough products, but force fallback
        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($this->getEnoughProducts());

        $this->mockRepository->expects($this->once())
            ->method('findById')
            ->willReturn(['id' => 1, 'name' => 'Product 1', 'category' => 'Cat1', 'price' => '100.00', 'description' => '', 'image_url' => '', 'created_at' => '2024-01-01']);

        $fallbackRecs = [
            ['product_id' => 2, 'product_name' => 'Product 2', 'score' => 60.0, 'explanation' => 'Fallback (rule-based): Category match', 'fallback_reason' => 'category'],
        ];

        $this->mockFallback->expects($this->once())
            ->method('getRecommendations')
            ->willReturn($fallbackRecs);

        $this->mockKNNService->expects($this->never())
            ->method('train');

        $this->mockKNNService->expects($this->never())
            ->method('isTrained');

        // Act
        $result = $this->service->execute(1, 10, true);

        // Assert
        $this->assertEquals('Product 2', $result[0]['name']);
        $this->assertArrayHasKey('fallback_reason', $result[0]);
    }

    public function testUsesFallbackOnKNNFailure(): void
    {
        // Arrange
        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($this->getEnoughProducts());

        $this->mockRepository->expects($this->exactly(2))
            ->method('findById')
            ->willReturn(['id' => 1, 'name' => 'Product 1', 'category' => 'Cat1', 'price' => '100.00', 'description' => '', 'image_url' => '', 'created_at' => '2024-01-01']);

        $this->mockKNNService->expects($this->once())
            ->method('isTrained')
            ->willReturn(false);

        $this->mockKNNService->expects($this->once())
            ->method('train')
            ->willThrowException(new \Exception('KNN training failed'));

        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with($this->stringContains('ML failed, using fallback'));

        $fallbackRecs = [
            ['product_id' => 2, 'product_name' => 'Product 2', 'score' => 60.0, 'explanation' => 'Fallback (rule-based): Category match', 'fallback_reason' => 'category'],
        ];

        $this->mockFallback->expects($this->once())
            ->method('getRecommendations')
            ->willReturn($fallbackRecs);

        // Act
        $result = $this->service->execute(1);

        // Assert - fallback returned
        $this->assertEquals($fallbackRecs[0]['product_id'], $result[0]['product_id']);
        $this->assertEquals('Product 2', $result[0]['name']);
        $this->assertArrayHasKey('fallback_reason', $result[0]);
    }

    private function getEnoughProducts(): array
    {
        $products = [];
        for ($i = 1; $i <= 10; $i++) {
            $products[] = [
                'id' => $i,
                'name' => "Product $i",
                'category' => 'Cat1',
                'price' => '100.00',
                'description' => '',
                'image_url' => '',
                'created_at' => '2024-01-01'
            ];
        }
        return $products;
    }
}
