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
    private const COLLABORATIVE_EXPLANATION_TEMPLATE = 'Quem se interessou por %s também se interessou por este produto (%d%% de afinidade)';

    /** AC7: never show more than 3 reasons at once. */
    private const MAX_REASONS = 3;

    /**
     * Fallback strategy names that produce category-based text; shared by
     * generateForFallback() and buildFallbackReasons() so explanation and
     * reasons cannot drift apart. Any other strategy is popularity.
     */
    private const CATEGORY_STRATEGIES = ['category', 'category_only', 'category_match'];

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

        return $this->isCategoryStrategy($strategy)
            ? sprintf(self::CATEGORY_FALLBACK_TEMPLATE, $category)
            : self::POPULARITY_FALLBACK_TEMPLATE;
    }

    /**
     * AC7: structured reasons behind a rule-based fallback recommendation
     * (max 3). Same strategy mapping as generateForFallback() (via
     * isCategoryStrategy()): category strategies name the category,
     * anything else is a popularity reason.
     *
     * @param array<string, mixed> $fallbackResult Raw fallback recommendation
     *        (as built by RuleBasedFallback): product_name, category, etc.
     * @return list<array{type: string, description: string}>
     */
    public function buildFallbackReasons(array $fallbackResult, string $strategy): array
    {
        $category = (string) ($fallbackResult['category'] ?? '');

        $reasons = $this->isCategoryStrategy($strategy)
            ? [['type' => 'category', 'description' => sprintf('Mesma categoria: %s', $category)]]
            : [['type' => 'popularity', 'description' => 'Produto popular entre os clientes']];

        return array_slice($reasons, 0, self::MAX_REASONS);
    }

    private function isCategoryStrategy(string $strategy): bool
    {
        return in_array($strategy, self::CATEGORY_STRATEGIES, true);
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

    /**
     * Story 8.1: explanation for an item-based collaborative filtering
     * result -- the link is shared interest across sessions, not
     * category/price similarity, so it must not reuse the KNN template.
     */
    public function generateForCollaborative(RecommendationResult $result, Product $targetProduct): string
    {
        return sprintf(
            self::COLLABORATIVE_EXPLANATION_TEMPLATE,
            $targetProduct->getName(),
            (int) floor($result->getScore())
        );
    }

    /**
     * Story 8.1: structured reasons behind a collaborative filtering
     * result (max 3): how many sessions interacted with both products and,
     * when it applies, the shared category.
     *
     * @return list<array{type: string, description: string}>
     */
    public function buildCollaborativeReasons(
        RecommendationResult $result,
        Product $targetProduct,
        int $sharedSessions
    ): array {
        $reasons = [
            [
                'type' => 'co_interaction',
                'description' => $sharedSessions === 1
                    ? sprintf('1 sessão se interessou por este produto e por %s', $targetProduct->getName())
                    : sprintf('%d sessões se interessaram por este produto e por %s', $sharedSessions, $targetProduct->getName()),
            ],
        ];

        if ($targetProduct->getCategory() === $result->getCategory()) {
            $reasons[] = [
                'type' => 'category',
                'description' => sprintf('Mesma categoria: %s', $result->getCategory()),
            ];
        }

        return array_slice($reasons, 0, self::MAX_REASONS);
    }
}
