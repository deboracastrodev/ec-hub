<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\ValueObject;

/**
 * Recommendation tuning settings, injected at construction time instead of
 * being read from disk by the classes that use them (R3.5).
 *
 * Owned by the Domain so both the Application use case
 * (GenerateRecommendations) and a Domain service (RuleBasedFallback) can
 * depend on it without the Domain reaching up into Application.
 */
final class RecommendationSettings
{
    public function __construct(
        private readonly string $fallbackStrategy,
        private readonly int $minProductsForMl,
        private readonly float $categoryScoreMin,
        private readonly float $categoryScoreMax,
        private readonly float $popularityScoreMin,
        private readonly float $popularityScoreMax
    ) {
    }

    /**
     * Build from the array shape returned by config/recommendation.php.
     * Missing keys fall back to the same defaults that file used to encode
     * as PHP literals -- kept here as the single place that knows them.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $fallback = $config['fallback'] ?? [];
        $scores = $fallback['scores'] ?? [];

        return new self(
            isset($fallback['strategy']) ? (string) $fallback['strategy'] : 'hybrid',
            isset($fallback['min_products_for_ml']) ? (int) $fallback['min_products_for_ml'] : 5,
            isset($scores['category_min']) ? (float) $scores['category_min'] : 60.0,
            isset($scores['category_max']) ? (float) $scores['category_max'] : 70.0,
            isset($scores['popularity_min']) ? (float) $scores['popularity_min'] : 50.0,
            isset($scores['popularity_max']) ? (float) $scores['popularity_max'] : 60.0
        );
    }

    public function getFallbackStrategy(): string
    {
        return $this->fallbackStrategy;
    }

    public function getMinProductsForMl(): int
    {
        return $this->minProductsForMl;
    }

    public function getCategoryScoreMin(): float
    {
        return $this->categoryScoreMin;
    }

    public function getCategoryScoreMax(): float
    {
        return $this->categoryScoreMax;
    }

    public function getPopularityScoreMin(): float
    {
        return $this->popularityScoreMin;
    }

    public function getPopularityScoreMax(): float
    {
        return $this->popularityScoreMax;
    }
}
