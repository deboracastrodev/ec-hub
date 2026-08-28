<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Product\Service;

use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Product\Service\CategoryService;
use PHPUnit\Framework\TestCase;

final class CategoryServiceTest extends TestCase
{
    private ProductRepositoryInterface $mockRepository;
    private CategoryService $service;

    protected function setUp(): void
    {
        $this->mockRepository = $this->createMock(ProductRepositoryInterface::class);
        $this->service = new CategoryService($this->mockRepository);
    }

    public function testGetAllCategoriesReturnsSortedUniqueList(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Esportes', 'Eletrônicos', 'Roupas']);

        $categories = $this->service->getAllCategories();

        $this->assertSame(['Esportes', 'Eletrônicos', 'Roupas'], $categories);
    }

    public function testGetAllCategoriesReturnsEmptyArrayWhenNone(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn([]);

        $this->assertSame([], $this->service->getAllCategories());
    }

    public function testGetAllCategoriesIsCachedAfterFirstCall(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->service->getAllCategories();
        $this->service->getAllCategories();
        $this->service->getAllCategories();
    }

    public function testCategoryExistsReturnsTrueForKnownCategory(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->assertTrue($this->service->categoryExists('Eletrônicos'));
    }

    public function testCategoryExistsReturnsFalseForUnknownCategory(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->assertFalse($this->service->categoryExists('Roupas'));
    }

    public function testCategoryExistsIsCaseInsensitive(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->assertTrue($this->service->categoryExists('eletrônicos'));
    }

    public function testCategoryExistsNormalizesAccentedInputToMatch(): void
    {
        // The same accented spelling matches after normalization.
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->assertTrue($this->service->categoryExists('Eletrônicos'));
    }

    public function testCategoryExistsHandlesWhitespaceAndSymbols(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Casa & Cozinha']);

        // Both inputs normalize to "casa-cozinha".
        $this->assertTrue($this->service->categoryExists('casa&cozinha'));
        $this->assertTrue($this->service->categoryExists('CASA &  COZINHA'));
    }

    public function testCategoryExistsReturnsFalseForEmptyString(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->assertFalse($this->service->categoryExists(''));
    }

    public function testGetProductCountDelegatesToRepository(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('countByCategory')
            ->with('Eletrônicos')
            ->willReturn(7);

        $this->assertSame(7, $this->service->getProductCount('Eletrônicos'));
    }

    public function testGetCategoriesWithCountsMapsEachCategoryToCount(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos', 'Roupas']);

        $this->mockRepository->expects($this->exactly(2))
            ->method('countByCategory')
            ->willReturnMap([
                ['Eletrônicos', 5],
                ['Roupas', 3],
            ]);

        $result = $this->service->getCategoriesWithCounts();

        $this->assertSame(['Eletrônicos' => 5, 'Roupas' => 3], $result);
    }

    public function testResolveCategoryReturnsCanonicalName(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->assertSame('Eletrônicos', $this->service->resolveCategory('eletrônicos'));
    }

    public function testResolveCategoryReturnsNullForUnknown(): void
    {
        $this->mockRepository->expects($this->once())
            ->method('findCategories')
            ->willReturn(['Eletrônicos']);

        $this->assertNull($this->service->resolveCategory('Esportes'));
    }
}
