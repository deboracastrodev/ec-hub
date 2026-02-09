<?php
declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for GenerateRecommendations Application Service
 */
class GenerateRecommendationsTest extends TestCase
{
    private GenerateRecommendations $service;
    private ProductRepositoryInterface $mockRepository;
    private KNNService $mockKNNService;
    private RuleBasedFallback $mockFallback;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
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

    public function testExecuteReturnsRecommendations(): void
    {
        // Arrange
        $targetProductId = 1;

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->with(1000, 0)
            ->willReturn($this->getMockProductsData());

        $this->mockKNNService->expects($this->once())
            ->method('isTrained')
            ->willReturn(false);

        $this->mockRepository->expects($this->once())
            ->method('findById')
            ->with($targetProductId)
            ->willReturn($this->getMockProductsData()[0]);

        $this->mockKNNService->expects($this->once())
            ->method('train')
            ->with($this->callback(fn($products) => count($products) >= 5), 5);

        $this->mockKNNService->expects($this->once())
            ->method('recommend')
            ->willReturn($this->getMockKNNResults());

        // Act
        $result = $this->service->execute($targetProductId);

        // Assert
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
        $this->assertArrayHasKey('product_id', $result[0]);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayHasKey('score', $result[0]);
        $this->assertArrayHasKey('explanation', $result[0]);
    }

    public function testExecuteThrowsExceptionWhenProductNotFound(): void
    {
        // Arrange
        $nonExistentId = 999;

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->with(1000, 0)
            ->willReturn($this->getMockProductsData());

        // Note: isTrained is NOT called because exception is thrown earlier
        $this->mockKNNService->expects($this->never())
            ->method('isTrained');

        $this->mockRepository->expects($this->once())
            ->method('findById')
            ->with($nonExistentId)
            ->willReturn(null);

        // train should never be called
        $this->mockKNNService->expects($this->never())
            ->method('train');

        // Assert/Act
        $this->expectException(RecommendationException::class);
        $this->expectExceptionMessage('Product with ID 999 not found');

        $this->service->execute($nonExistentId);
    }

    public function testExecuteUsesCachedModelOnSecondCall(): void
    {
        // Arrange
        $targetProductId = 1;

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->with(1000, 0)
            ->willReturn($this->getMockProductsData());

        $this->mockRepository->expects($this->exactly(2))
            ->method('findById')
            ->willReturn($this->getMockProductsData()[0]);

        $this->mockKNNService->expects($this->exactly(2))
            ->method('isTrained')
            ->willReturnOnConsecutiveCalls(false, true);

        // train should only be called once
        $this->mockKNNService->expects($this->once())
            ->method('train');

        $this->mockKNNService->expects($this->exactly(2))
            ->method('recommend')
            ->willReturn($this->getMockKNNResults());

        // Act
        $this->service->execute($targetProductId);
        $this->service->execute($targetProductId);

        // Assert - train called once verified by expects
    }

    public function testExecuteReturnsEmptyArrayOnError(): void
    {
        // Arrange
        $targetProductId = 1;

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willThrowException(new \Exception('Database error'));

        $this->mockLogger->expects($this->once())
            ->method('error');

        // Act
        $result = $this->service->execute($targetProductId);

        // Assert - graceful degradation
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testClearCacheResetsTrainedState(): void
    {
        // Arrange
        $this->mockRepository->expects($this->exactly(2))
            ->method('findAll')
            ->with(1000, 0)
            ->willReturn($this->getMockProductsData());

        $this->mockKNNService->expects($this->exactly(2))
            ->method('isTrained')
            ->willReturn(false);

        $this->mockKNNService->expects($this->exactly(2))
            ->method('train');

        $this->mockRepository->expects($this->exactly(2))
            ->method('findById')
            ->willReturn($this->getMockProductsData()[0]);

        $this->mockKNNService->expects($this->exactly(2))
            ->method('recommend')
            ->willReturn([]);

        // Act
        $this->service->execute(1);
        $this->service->clearCache();
        $this->service->execute(1);

        // Assert - trainFromRepository called twice verified by expects
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMockProductsData(): array
    {
        return [
            [
                'id' => 1,
                'name' => 'Laptop Gamer',
                'description' => 'High performance laptop',
                'price' => '4500.00',
                'category' => 'Eletrônicos',
                'image_url' => 'https://example.com/laptop.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 2,
                'name' => 'Mouse Gamer',
                'description' => 'RGB mouse',
                'price' => '150.00',
                'category' => 'Eletrônicos',
                'image_url' => 'https://example.com/mouse.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 3,
                'name' => 'Teclado Mecânico',
                'description' => 'Mechanical keyboard',
                'price' => '300.00',
                'category' => 'Eletrônicos',
                'image_url' => 'https://example.com/keyboard.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 4,
                'name' => 'Monitor 24"',
                'description' => '24 inch monitor',
                'price' => '800.00',
                'category' => 'Eletrônicos',
                'image_url' => 'https://example.com/monitor.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
            [
                'id' => 5,
                'name' => 'Headset Gamer',
                'description' => 'Gaming headset',
                'price' => '250.00',
                'category' => 'Eletrônicos',
                'image_url' => 'https://example.com/headset.jpg',
                'created_at' => '2024-01-01 00:00:00',
            ],
        ];
    }

    /**
     * @return array<int, RecommendationResult>
     */
    private function getMockKNNResults(): array
    {
        return [
            new RecommendationResult(
                2,
                'Mouse Gamer',
                'Eletrônicos',
                'R$ 150,00',
                85.0,
                1,
                'Recomendado porque você visualizou "Laptop Gamer" que também é da categoria Eletrônicos'
            ),
        ];
    }
}
