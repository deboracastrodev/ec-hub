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

    /** @var array{A: string, B: string}|null Story 8.2: A/B variants (A = first, B = second), null when off */
    private readonly ?array $abTestVariants;

    /**
     * @throws \InvalidArgumentException When $algorithm is not one of ALGORITHMS,
     *         or $abTest is not empty and not exactly two distinct ALGORITHMS
     */
    public function __construct(
        private readonly string $fallbackStrategy,
        private readonly int $minProductsForMl,
        private readonly float $categoryScoreMin,
        private readonly float $categoryScoreMax,
        private readonly float $popularityScoreMin,
        private readonly float $popularityScoreMax,
        string $algorithm = self::DEFAULT_ALGORITHM,
        string $abTest = ''
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
        $this->abTestVariants = self::parseAbTest($abTest);
    }

    /**
     * Story 8.2: RECOMMENDATION_AB_TEST is "a,b" -- each item trimmed and
     * lowercased. Empty turns A/B off; anything other than exactly two
     * distinct ALGORITHMS fails fast.
     *
     * @return array{A: string, B: string}|null
     * @throws \InvalidArgumentException
     */
    private static function parseAbTest(string $abTest): ?array
    {
        if (trim($abTest) === '') {
            return null;
        }

        $names = array_map(
            static fn (string $name): string => strtolower(trim($name)),
            explode(',', $abTest)
        );

        $valid = count($names) === 2
            && $names[0] !== $names[1]
            && in_array($names[0], self::ALGORITHMS, true)
            && in_array($names[1], self::ALGORITHMS, true);

        if (! $valid) {
            throw new \InvalidArgumentException(sprintf(
                'RECOMMENDATION_AB_TEST inválido: "%s". Informe exatamente 2 algoritmos distintos separados por vírgula, dentre: %s.',
                $abTest,
                implode(', ', self::ALGORITHMS)
            ));
        }

        return ['A' => $names[0], 'B' => $names[1]];
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
            isset($config['algorithm']) ? (string) $config['algorithm'] : self::DEFAULT_ALGORITHM,
            isset($config['ab_test']) ? (string) $config['ab_test'] : ''
        );
    }

    /** Story 8.1: canonical name of the active RecommendationStrategy. */
    public function getAlgorithm(): string
    {
        return $this->algorithm;
    }

    /** Story 8.2: whether RECOMMENDATION_AB_TEST splits subjects between two algorithms. */
    public function isAbTestEnabled(): bool
    {
        return $this->abTestVariants !== null;
    }

    /**
     * Story 8.2: variant => algorithm (A = first listed, B = second), or null when off.
     *
     * @return array{A: string, B: string}|null
     */
    public function getAbTestVariants(): ?array
    {
        return $this->abTestVariants;
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
