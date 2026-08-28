<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\ValueObject;

use App\Domain\Recommendation\ValueObject\RecommendationSettings;
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
}
