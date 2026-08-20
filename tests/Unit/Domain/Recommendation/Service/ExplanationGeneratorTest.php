<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\ExplanationGenerator;
use App\Domain\Shared\ValueObject\Money;
use PHPUnit\Framework\TestCase;

final class ExplanationGeneratorTest extends TestCase
{
    private ExplanationGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ExplanationGenerator();
    }

    private function makeProduct(string $name, string $category, float $price = 100.0, int $id = 1): Product
    {
        $product = new Product($name, '', Money::fromDecimal($price), $category);
        $product->setId($id);

        return $product;
    }

    public function testGenerateForMlUsesTargetProductNameAndFlooredScore(): void
    {
        $target = $this->makeProduct('Fone Bluetooth Sony', 'Eletrônicos', 599.0, 1);
        $result = new RecommendationResult(2, 'Fone Bluetooth Premium', 'Eletrônicos', 299.90, 87.9, 1, 'placeholder');

        $explanation = $this->generator->generateForML($result, $target);

        // AC3: "Recomendado com base em [Produto X] que você visualizou ([Score]% de similaridade)"
        $this->assertSame(
            'Recomendado com base em Fone Bluetooth Sony que você visualizou (87% de similaridade)',
            $explanation
        );
    }

    public function testGenerateForMlFloorsFractionalScoreLikeAc8Example(): void
    {
        $target = $this->makeProduct('Fone Bluetooth Sony', 'Eletrônicos');
        $result = new RecommendationResult(2, 'Fone Bluetooth Premium', 'Eletrônicos', 299.90, 87.5, 1, 'placeholder');

        $explanation = $this->generator->generateForML($result, $target);

        $this->assertStringContainsString('87%', $explanation);
    }

    public function testGenerateForFallbackCategoryStrategyMentionsCategory(): void
    {
        $fallbackResult = ['product_name' => 'Mouse Gamer', 'category' => 'Eletrônicos'];

        $explanation = $this->generator->generateForFallback($fallbackResult, 'category');

        $this->assertSame('Produtos populares na categoria Eletrônicos', $explanation);
    }

    public function testGenerateForFallbackPopularityStrategy(): void
    {
        $fallbackResult = ['product_name' => 'Camiseta', 'category' => 'Roupas'];

        $explanation = $this->generator->generateForFallback($fallbackResult, 'popularity');

        $this->assertSame('Produtos mais visualizados', $explanation);
    }

    public function testGenerateForFallbackDefaultsToPopularityTemplateForUnknownStrategy(): void
    {
        $explanation = $this->generator->generateForFallback([], 'something_unmapped');

        $this->assertSame('Produtos mais visualizados', $explanation);
    }

    public function testGenerateForFallbackHandlesMissingProductData(): void
    {
        // Edge case: neither 'product_name' nor 'category' provided.
        $explanation = $this->generator->generateForFallback([], 'category');

        $this->assertSame('Produtos populares na categoria ', $explanation);
    }

    public function testBuildReasonsArrayIncludesSimilarityReason(): void
    {
        $result = new RecommendationResult(2, 'Mouse Gamer', 'Eletrônicos', 150.0, 87.0, 1, 'placeholder');

        $reasons = $this->generator->buildReasonsArray($result, null);

        $this->assertCount(1, $reasons);
        $this->assertSame('similarity', $reasons[0]['type']);
        $this->assertStringContainsString('87%', $reasons[0]['description']);
    }

    public function testBuildReasonsArrayAddsCategoryReasonWhenTargetSharesCategory(): void
    {
        $target = $this->makeProduct('Laptop Gamer', 'Eletrônicos');
        $result = new RecommendationResult(2, 'Mouse Gamer', 'Eletrônicos', 150.0, 87.0, 1, 'placeholder');

        $reasons = $this->generator->buildReasonsArray($result, $target);

        $this->assertCount(2, $reasons);
        $this->assertSame('similarity', $reasons[0]['type']);
        $this->assertSame('category', $reasons[1]['type']);
        $this->assertStringContainsString('Eletrônicos', $reasons[1]['description']);
    }

    public function testBuildReasonsArraySkipsCategoryReasonWhenCategoriesDiffer(): void
    {
        $target = $this->makeProduct('Camiseta', 'Roupas');
        $result = new RecommendationResult(2, 'Mouse Gamer', 'Eletrônicos', 150.0, 87.0, 1, 'placeholder');

        $reasons = $this->generator->buildReasonsArray($result, $target);

        $this->assertCount(1, $reasons);
    }

    public function testBuildReasonsArrayCapsAtThreeReasons(): void
    {
        // AC7: no máximo 3 motivos são exibidos.
        $target = $this->makeProduct('Laptop Gamer', 'Eletrônicos');
        $result = new RecommendationResult(2, 'Mouse Gamer', 'Eletrônicos', 150.0, 87.0, 1, 'placeholder');

        $reasons = $this->generator->buildReasonsArray($result, $target);

        $this->assertLessThanOrEqual(3, count($reasons));
    }
}
