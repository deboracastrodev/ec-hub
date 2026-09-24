<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\ValueObject;

use App\Domain\Recommendation\Service\CollaborativeFilteringService;
use App\Domain\Recommendation\Service\KNNService;

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
    /** Story 8.1: accepted values for RECOMMENDATION_ALGORITHM (first is the default). */
    public const ALGORITHMS = [KNNService::NAME, CollaborativeFilteringService::NAME];

    public const DEFAULT_ALGORITHM = KNNService::NAME;

    private readonly string $algorithm;

    /**
     * @throws \InvalidArgumentException When $algorithm is not one of ALGORITHMS
     */
    public function __construct(
        private readonly string $fallbackStrategy,
        private readonly int $minProductsForMl,
        private readonly float $categoryScoreMin,
        private readonly float $categoryScoreMax,
        private readonly float $popularityScoreMin,
        private readonly float $popularityScoreMax,
        string $algorithm = self::DEFAULT_ALGORITHM
    ) {
        $normalized = strtolower(trim($algorithm));
        if ($normalized === '') {
            $normalized = self::DEFAULT_ALGORITHM;
        }
        if (! in_array($normalized, self::ALGORITHMS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'RECOMMENDATION_ALGORITHM inválido: "%s". Valores aceitos: %s.',
                $algorithm,
                implode(', ', self::ALGORITHMS)
            ));
        }
        $this->algorithm = $normalized;
    }

    /**
     * Build from the array shape returned by config/recommendation.php.
     * Missing keys fall back to the same defaults that file used to encode
     * as PHP literals -- kept here as the single place that knows them.
     *
     * An unknown 'algorithm' fails fast (InvalidArgumentException), same as
     * an invalid SESSION_TTL -- silently falling back to the default would
     * hide a misconfigured deploy.
     *
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException
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
            isset($scores['popularity_max']) ? (float) $scores['popularity_max'] : 60.0,
            isset($config['algorithm']) ? (string) $config['algorithm'] : self::DEFAULT_ALGORITHM
        );
    }

    /** Story 8.1: canonical name of the active RecommendationStrategy. */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
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
