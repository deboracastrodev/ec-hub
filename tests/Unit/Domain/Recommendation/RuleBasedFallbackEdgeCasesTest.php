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
 * Edge cases for RuleBasedFallback: empty catalog and no-match scenarios must
 * degrade gracefully without throwing.
 */
final class RuleBasedFallbackEdgeCasesTest extends TestCase
{
    private RuleBasedFallback $fallback;
    private ProductRepositoryInterface $mockRepository;
    private LoggerInterface $mockLogger;
    private Product $contextProduct;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->mockLogger = $this->createStub(LoggerInterface::class);

        $this->fallback = new RuleBasedFallback($this->mockRepository, $this->mockLogger);

        $this->contextProduct = new Product(
            'Laptop Gamer',
            'High performance laptop',
            Money::fromDecimal(4500.00),
            'Eletrônicos',
            'https://example.com/laptop.jpg'
        );
        $this->contextProduct->setId(1);
    }

    public function testEmptyCatalogWithCategoryStrategyReturnsEmptyNoException(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn([]);

        $recommendations = $this->fallback->getRecommendations($this->contextProduct, 5, 'category_only');

        $this->assertIsArray($recommendations);
        $this->assertSame([], $recommendations);
    }

    public function testEmptyCatalogWithPopularityStrategyReturnsEmptyNoException(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->with(5, 0)
            ->willReturn([]);

        $recommendations = $this->fallback->getRecommendations($this->contextProduct, 5, 'popularity_only');

        $this->assertIsArray($recommendations);
        $this->assertSame([], $recommendations);
    }

    public function testEmptyCatalogWithHybridStrategyReturnsEmptyNoException(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn([]);

        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $recommendations = $this->fallback->getRecommendations($this->contextProduct, 3, 'hybrid');

        $this->assertIsArray($recommendations);
        $this->assertSame([], $recommendations);
    }

    public function testNoRuleMatchStillReturnsPopularityFallback(): void
    {
        // Category yields nothing, so hybrid fills entirely with popularity.
        $this->mockRepository->expects($this->once())
            ->method('findByCategory')
            ->willReturn([]);

        $popular = [
            ['id' => 2, 'name' => 'Mouse Gamer', 'category' => 'Eletrônicos', 'price' => '150.00'],
            ['id' => 3, 'name' => 'Teclado', 'category' => 'Eletrônicos', 'price' => '300.00'],
        ];
        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->willReturn($this->productsFromRows($popular));

        $recommendations = $this->fallback->getRecommendations($this->contextProduct, 2, 'hybrid');

        $this->assertCount(2, $recommendations);
        foreach ($recommendations as $rec) {
            $this->assertSame('popular_product', $rec['fallback_reason']);
        }
    }

    public function testColdStartGetPopularRecommendations(): void
    {
        $popular = [
            ['id' => 2, 'name' => 'Camiseta', 'category' => 'Roupas', 'price' => '79.90'],
        ];
        $this->mockRepository->expects($this->once())
            ->method('findAll')
            ->with(1, 0)
            ->willReturn($this->productsFromRows($popular));

        $recommendations = $this->fallback->getPopularRecommendations(1);

        $this->assertCount(1, $recommendations);
        $this->assertSame('popular_product', $recommendations[0]['fallback_reason']);
        $this->assertSame(2, $recommendations[0]['product_id']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, Product>
     */
    private function productsFromRows(array $rows): array
    {
        return array_map(static fn (array $row): Product => Product::fromArray($row), $rows);
    }
}
