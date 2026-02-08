<?php
declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Rule-Based Fallback Recommendation Service
 *
 * Provides recommendations when ML (KNN) underperforms or has insufficient data.
 * Strategies: category, popularity, or hybrid.
 */
class RuleBasedFallback
{
    private ProductRepositoryInterface $productRepository;
    private LoggerInterface $logger;

    // Fallback strategies
    private const STRATEGY_CATEGORY = 'category_only';
    private const STRATEGY_POPULARITY = 'popularity_only';
    private const STRATEGY_HYBRID = 'hybrid';

    // Score ranges for fallback recommendations
    private const MIN_FALLBACK_SCORE = 50.0;
    private const MAX_FALLBACK_SCORE = 70.0;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->logger = $logger;
    }

    /**
     * Get rule-based recommendations
     *
     * @param Product $contextProduct Product to base recommendations on
     * @param int $limit Number of recommendations
     * @param string $strategy Strategy: category_only, popularity_only, hybrid
     * @return array<array<string, mixed>> Recommendations with scores
     */
    public function getRecommendations(
        Product $contextProduct,
        int $limit = 10,
        string $strategy = self::STRATEGY_HYBRID
    ): array {
        $recommendations = [];

        switch ($strategy) {
            case self::STRATEGY_CATEGORY:
                $recommendations = $this->getByCategory($contextProduct, $limit);
                $this->logFallbackActivated('category', count($recommendations));
                break;

            case self::STRATEGY_POPULARITY:
                $recommendations = $this->getByPopularity($limit);
                $this->logFallbackActivated('popularity', count($recommendations));
                break;

            case self::STRATEGY_HYBRID:
            default:
                $recommendations = $this->getHybridRecommendations($contextProduct, $limit);
                $this->logFallbackActivated('hybrid', count($recommendations));
                break;
        }

        return $recommendations;
    }

    /**
     * Get recommendations by category
     */
    private function getByCategory(Product $contextProduct, int $limit): array
    {
        $category = $contextProduct->getCategory();
        $excludeId = $contextProduct->getId();

        // Fetch products from same category
        $productsData = $this->productRepository->findByCategory(
            $category,
            $limit + 1 // +1 to account for excluded product
        );

        $recommendations = [];
        $count = 0;

        foreach ($productsData as $productData) {
            if ($productData['id'] == $excludeId) {
                continue; // Skip context product
            }

            if ($count >= $limit) {
                break;
            }

            $score = $this->calculateFallbackScore('category', $count);

            $recommendations[] = [
                'product_id' => $productData['id'],
                'product_name' => $productData['name'],
                'category' => $productData['category'],
                'price' => $productData['price'],
                'score' => $score,
                'explanation' => $this->generateCategoryExplanation($category),
                'fallback_reason' => 'category_match',
                'fallback_strategy' => 'category',
            ];

            $count++;
        }

        return $recommendations;
    }

    /**
     * Get recommendations by popularity
     */
    private function getByPopularity(int $limit): array
    {
        // For now, random order (until we have view tracking)
        // In Epic 4, this will use actual view counts
        $productsData = $this->productRepository->findAll(limit: $limit);

        shuffle($productsData); // Random for variety

        $recommendations = [];

        foreach ($productsData as $index => $productData) {
            $score = $this->calculateFallbackScore('popularity', $index);

            $recommendations[] = [
                'product_id' => $productData['id'],
                'product_name' => $productData['name'],
                'category' => $productData['category'],
                'price' => $productData['price'],
                'score' => $score,
                'explanation' => $this->generatePopularityExplanation(),
                'fallback_reason' => 'popular_product',
                'fallback_strategy' => 'popularity',
            ];
        }

        return $recommendations;
    }

    /**
     * Get hybrid recommendations (category + popularity)
     */
    private function getHybridRecommendations(Product $contextProduct, int $limit): array
    {
        // Try category first (50% of limit)
        $categoryCount = (int) ceil($limit * 0.5);
        $categoryRecs = $this->getByCategory($contextProduct, $categoryCount);

        // Fill rest with popularity
        $popularityCount = $limit - count($categoryRecs);
        $popularityRecs = [];

        if ($popularityCount > 0) {
            $popularityRecs = $this->getByPopularity($popularityCount);

            // Exclude products already in category recommendations
            $categoryIds = array_column($categoryRecs, 'product_id');
            $popularityRecs = array_filter(
                $popularityRecs,
                fn($rec) => !in_array($rec['product_id'], $categoryIds)
            );
            $popularityRecs = array_values($popularityRecs); // Re-index
        }

        // Combine results
        $recommendations = array_merge($categoryRecs, $popularityRecs);

        // Log if we had to fallback to popularity
        if (count($categoryRecs) < $categoryCount) {
            $this->logger->info('Fallback category insufficient, using popularity', [
                'category_products' => count($categoryRecs),
                'popularity_products' => count($popularityRecs),
            ]);
        }

        return $recommendations;
    }

    /**
     * Calculate fallback score (lower than ML scores)
     */
    private function calculateFallbackScore(string $type, int $rank): float
    {
        // Category scores: 60-70
        // Popularity scores: 50-60
        $min = $type === 'category' ? 60.0 : 50.0;
        $max = $type === 'category' ? 70.0 : 60.0;

        // Decrease score by rank (0 = highest)
        $score = $max - ($rank * 2);

        return max($min, min($max, $score));
    }

    /**
     * Generate explanation for category-based recommendation
     */
    private function generateCategoryExplanation(string $category): string
    {
        return sprintf(
            "Recomendado por ser da categoria %s",
            $category
        );
    }

    /**
     * Generate explanation for popularity-based recommendation
     */
    private function generatePopularityExplanation(): string
    {
        return "Recomendado por ser um produto popular";
    }

    /**
     * Log when fallback is activated
     */
    private function logFallbackActivated(string $strategy, int $productsCount): void
    {
        $this->logger->info('Rule-based fallback activated', [
            'strategy' => $strategy,
            'products_count' => $productsCount,
            'reason' => 'ml_insufficient_data_or_error',
        ]);
    }
}
