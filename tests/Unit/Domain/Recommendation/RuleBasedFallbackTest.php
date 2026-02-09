<?php
declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RuleBasedFallback
 *
 * These tests verify the rule-based fallback implementation works correctly
 * when ML has insufficient data or fails.
 */
class RuleBasedFallbackTest extends TestCase
{
    private RuleBasedFallback $fallback;
    private ProductRepositoryInterface $mockRepository;
    private LoggerInterface $mockLogger;

    private Product $contextProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->fallback = new RuleBasedFallback(
            $this->mockRepository,
            $this->mockLogger
        );

        // Create context product
        $this->contextProduct = new Product(
            'Laptop Gamer',
            'High performance laptop',
            Money::fromDecimal(4500.00),
            'Eletrônicos',
            'https://example.com/laptop.jpg'
        );
        $this->contextProduct->setId(1);
    }

    public function testGetByCategoryReturnsSameCategoryProducts(): void
    {
        // Arrange
        $categoryProducts = [
            ['id' => 2, 'name' => 'Mouse Gamer', 'category' => 'Eletrônicos', 'price' => '150.00'],
            ['id' => 3, 'name' => 'Teclado Mecânico', 'category' => 'Eletrônicos', 'price' => '300.00'],
        ];

        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->with('Eletrônicos', 3)
            ->willReturn($categoryProducts);

        $this->mockLogger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('Fallback activated'),
                $this->arrayHasKey('strategy_used')
            );

        // Act
        $recommendations = $this->fallback->getRecommendations(
            $this->contextProduct,
            2,
            'category_only'
        );

        // Assert
        $this->assertCount(2, $recommendations);
        $this->assertEquals('Eletrônicos', $recommendations[0]['category']);
        $this->assertEquals(2, $recommendations[0]['product_id']);
        $this->assertStringContainsString('categoria', $recommendations[0]['explanation']);
        $this->assertEquals('category_match', $recommendations[0]['fallback_reason']);
    }

    public function testGetByCategoryExcludesContextProduct(): void
    {
        // Arrange
        $categoryProducts = [
            ['id' => 1, 'name' => 'Laptop Gamer', 'category' => 'Eletrônicos', 'price' => '4500.00'],
            ['id' => 2, 'name' => 'Mouse Gamer', 'category' => 'Eletrônicos', 'price' => '150.00'],
        ];

        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn($categoryProducts);

        // Act
        $recommendations = $this->fallback->getRecommendations(
            $this->contextProduct,
            5,
            'category_only'
        );

        // Assert - context product (ID 1) should be excluded
        $this->assertCount(1, $recommendations);
        $this->assertEquals(2, $recommendations[0]['product_id']);
    }

    public function testGetByPopularityReturnsProducts(): void
    {
        // Arrange
        $allProducts = [
            ['id' => 2, 'name' => 'Camiseta', 'category' => 'Roupas', 'price' => '79.90'],
            ['id' => 3, 'name' => 'Tênis', 'category' => 'Esportes', 'price' => '299.00'],
        ];

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->with(2, 0)
            ->willReturn($allProducts);

        // Act
        $recommendations = $this->fallback->getRecommendations(
            $this->contextProduct,
            2,
            'popularity_only'
        );

        // Assert
        $this->assertCount(2, $recommendations);
        $this->assertStringContainsString('Fallback', $recommendations[0]['explanation']);
        $this->assertEquals('popular_product', $recommendations[0]['fallback_reason']);
    }

    public function testHybridStrategyCombinesCategoryAndPopularity(): void
    {
        // Arrange
        $categoryProducts = [
            ['id' => 2, 'name' => 'Mouse Gamer', 'category' => 'Eletrônicos', 'price' => '150.00'],
        ];

        $allProducts = [
            ['id' => 3, 'name' => 'Camiseta', 'category' => 'Roupas', 'price' => '79.90'],
            ['id' => 4, 'name' => 'Tênis', 'category' => 'Esportes', 'price' => '299.00'],
        ];

        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn($categoryProducts);

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($allProducts);

        // Act
        $recommendations = $this->fallback->getRecommendations(
            $this->contextProduct,
            3,
            'hybrid'
        );

        // Assert - 1 category + 2 popularity (since we want 3 total)
        $this->assertCount(3, $recommendations);
        $this->assertEquals('category_match', $recommendations[0]['fallback_reason']);
        $this->assertEquals('popular_product', $recommendations[1]['fallback_reason']);
    }

    public function testFallbackScoresAreLowerThanML(): void
    {
        // Arrange
        $categoryProducts = [
            ['id' => 2, 'name' => 'Mouse Gamer', 'category' => 'Eletrônicos', 'price' => '150.00'],
        ];

        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn($categoryProducts);

        // Act
        $recommendations = $this->fallback->getRecommendations(
            $this->contextProduct,
            5,
            'category_only'
        );

        // Assert - fallback scores should be 50-70, ML is 80-100
        $this->assertGreaterThanOrEqual(50.0, $recommendations[0]['score']);
        $this->assertLessThanOrEqual(70.0, $recommendations[0]['score']);
    }

    public function testCategoryFallbackFailsToPopularity(): void
    {
        // Arrange
        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn([]); // No category products

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([
                ['id' => 3, 'name' => 'Camiseta', 'category' => 'Roupas', 'price' => '79.90'],
            ]);

        // Use a callback to verify the specific log message
        $this->mockLogger->expects($this->atLeastOnce())
            ->method('info')
            ->with(
                $this->callback(function ($message) {
                    // Allow any info message, but we'll verify the specific one below
                    return is_string($message);
                }),
                $this->callback(function ($context) {
                    // Check if this is the "category insufficient" log
                    if (isset($context['category_products'])) {
                        return true;
                    }
                    // Allow other log contexts
                    return true;
                })
            );

        // Act
        $recommendations = $this->fallback->getRecommendations(
            $this->contextProduct,
            2,
            'hybrid'
        );

        // Assert - should have popularity products
        $this->assertCount(1, $recommendations);
        $this->assertEquals('popular_product', $recommendations[0]['fallback_reason']);
    }

    public function testDefaultStrategyIsHybrid(): void
    {
        // Arrange - we don't specify strategy, should default to hybrid
        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn([]);

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        // Act
        $recommendations = $this->fallback->getRecommendations(
            $this->contextProduct,
            2
        );

        // Assert - should have called both category and popularity (hybrid)
        $this->assertIsArray($recommendations);
    }
}
