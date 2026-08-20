<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Model\RecommendationResult;

/**
 * Explanation Generator Service
 *
 * Centralizes the Portuguese-language explanation text and structured
 * "reasons" shown to Ana for every recommendation (Story 3.5, FR11/FR12,
 * AC2-AC4, AC7): why an ML result was suggested, and why a rule-based
 * fallback result was suggested, in a single place instead of scattered
 * sprintf() calls across KNNService/RuleBasedFallback.
 *
 * Pure text formatting -- no persistence, no infrastructure dependency.
 */
class ExplanationGenerator
{
    private const ML_EXPLANATION_TEMPLATE = 'Recomendado com base em %s que você visualizou (%d%% de similaridade)';
    private const CATEGORY_FALLBACK_TEMPLATE = 'Produtos populares na categoria %s';
    private const POPULARITY_FALLBACK_TEMPLATE = 'Produtos mais visualizados';

    /** AC7: never show more than 3 reasons at once. */
    private const MAX_REASONS = 3;

    /**
     * AC3: "Recomendado com base em [Produto X] que você visualizou
     * ([Score]% de similaridade)".
     */
    public function generateForML(RecommendationResult $result, Product $targetProduct): string
    {
        return sprintf(
            self::ML_EXPLANATION_TEMPLATE,
            $targetProduct->getName(),
            (int) floor($result->getScore())
        );
    }

    /**
     * AC4: fallback explanations clearly name the strategy that produced
     * the recommendation.
     *
     * `RuleBasedFallback`'s "hybrid" strategy (the default) is a mix of
     * separately-generated category and popularity items -- it never
     * produces a single item labeled 'hybrid', so only those two strategy
     * strings ever reach this method in practice.
     *
     * @param array<string, mixed> $fallbackResult Raw fallback recommendation
     *        (as built by RuleBasedFallback): product_name, category, etc.
     */
    public function generateForFallback(array $fallbackResult, string $strategy): string
    {
        $category = (string) ($fallbackResult['category'] ?? '');

        return match ($strategy) {
            'category', 'category_only', 'category_match' => sprintf(self::CATEGORY_FALLBACK_TEMPLATE, $category),
            default => self::POPULARITY_FALLBACK_TEMPLATE,
        };
    }

    /**
     * AC7: structured, ranked reasons behind an ML recommendation (max 3).
     *
     * @return list<array{type: string, description: string}>
     */
    public function buildReasonsArray(RecommendationResult $result, ?Product $target): array
    {
        $reasons = [
            [
                'type' => 'similarity',
                'description' => sprintf('%d%% similar ao produto visualizado', (int) floor($result->getScore())),
            ],
        ];

        if ($target !== null && $target->getCategory() === $result->getCategory()) {
            $reasons[] = [
                'type' => 'category',
                'description' => sprintf('Mesma categoria: %s', $result->getCategory()),
            ];
        }

        return array_slice($reasons, 0, self::MAX_REASONS);
    }
}
