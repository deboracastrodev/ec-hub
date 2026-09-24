<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\ValueObject;

use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecommendationSettingsTest extends TestCase
{
    public function testFromEmptyArrayAppliesDefaults(): void
    {
        $settings = RecommendationSettings::fromArray([]);

        $this->assertSame('hybrid', $settings->getFallbackStrategy());
        $this->assertSame(5, $settings->getMinProductsForMl());
        $this->assertSame(60.0, $settings->getCategoryScoreMin());
        $this->assertSame(70.0, $settings->getCategoryScoreMax());
        $this->assertSame(50.0, $settings->getPopularityScoreMin());
        $this->assertSame(60.0, $settings->getPopularityScoreMax());
    }

    public function testFromArrayWithFullConfigUsesExplicitValues(): void
    {
        $settings = RecommendationSettings::fromArray([
            'fallback' => [
                'strategy' => 'category_only',
                'min_products_for_ml' => 10,
                'scores' => [
                    'category_min' => 55.0,
                    'category_max' => 75.0,
                    'popularity_min' => 40.0,
                    'popularity_max' => 50.0,
                ],
            ],
        ]);

        $this->assertSame('category_only', $settings->getFallbackStrategy());
        $this->assertSame(10, $settings->getMinProductsForMl());
        $this->assertSame(55.0, $settings->getCategoryScoreMin());
        $this->assertSame(75.0, $settings->getCategoryScoreMax());
        $this->assertSame(40.0, $settings->getPopularityScoreMin());
        $this->assertSame(50.0, $settings->getPopularityScoreMax());
    }

    public function testPartialConfigFallsBackPerMissingKey(): void
    {
        $settings = RecommendationSettings::fromArray([
            'fallback' => [
                'strategy' => 'popularity_only',
                // min_products_for_ml and scores missing -> defaults apply
            ],
        ]);

        $this->assertSame('popularity_only', $settings->getFallbackStrategy());
        $this->assertSame(5, $settings->getMinProductsForMl());
        $this->assertSame(60.0, $settings->getCategoryScoreMin());
        $this->assertSame(50.0, $settings->getPopularityScoreMin());
    }

    public function testAlgorithmDefaultsToKnn(): void
    {
        $this->assertSame('knn', RecommendationSettings::fromArray([])->getAlgorithm());
        $this->assertSame('knn', RecommendationSettings::fromArray(['algorithm' => ''])->getAlgorithm());
        $this->assertSame('knn', RecommendationSettings::fromArray(['algorithm' => '   '])->getAlgorithm());
    }

    public function testAlgorithmIsNormalized(): void
    {
        $this->assertSame(
            'collaborative',
            RecommendationSettings::fromArray(['algorithm' => '  Collaborative '])->getAlgorithm()
        );
        $this->assertSame('knn', RecommendationSettings::fromArray(['algorithm' => 'KNN'])->getAlgorithm());
    }

    public function testUnknownAlgorithmFailsFastListingAcceptedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('knn, collaborative');

        RecommendationSettings::fromArray(['algorithm' => 'svd']);
    }

    public function testAbTestIsOffWhenAbsentOrEmpty(): void
    {
        foreach ([[], ['ab_test' => ''], ['ab_test' => '   ']] as $config) {
            $settings = RecommendationSettings::fromArray($config);

            $this->assertFalse($settings->isAbTestEnabled());
            $this->assertNull($settings->getAbTestVariants());
        }
    }

    public function testAbTestVariantsFollowTheListedOrder(): void
    {
        $settings = RecommendationSettings::fromArray(['ab_test' => 'collaborative,knn']);

        $this->assertTrue($settings->isAbTestEnabled());
        $this->assertSame(['A' => 'collaborative', 'B' => 'knn'], $settings->getAbTestVariants());
    }

    public function testAbTestItemsAreTrimmedAndLowercased(): void
    {
        $settings = RecommendationSettings::fromArray(['ab_test' => ' KNN , Collaborative ']);

        $this->assertSame(['A' => 'knn', 'B' => 'collaborative'], $settings->getAbTestVariants());
    }

    #[DataProvider('invalidAbTests')]
    public function testInvalidAbTestFailsFastCitingTheVariable(string $abTest): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/RECOMMENDATION_AB_TEST.*knn, collaborative/');

        RecommendationSettings::fromArray(['ab_test' => $abTest]);
    }

    /** @return array<string, array{string}> */
    public static function invalidAbTests(): array
    {
        return [
            'single algorithm' => ['knn'],
            'duplicate algorithm' => ['knn,knn'],
            'duplicate after normalization' => ['knn, KNN'],
            'unknown algorithm' => ['knn,svd'],
            'three items' => ['a,b,c'],
            'three valid-looking items' => ['knn,collaborative,knn'],
            'empty item' => ['knn,'],
        ];
    }
}
