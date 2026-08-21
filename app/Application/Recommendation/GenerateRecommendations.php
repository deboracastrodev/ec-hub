<?php

declare(strict_types=1);

namespace App\Application\Recommendation;

use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\ExplanationGenerator;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Recommendation\Utility\ConfidenceCalculator;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use Psr\Log\LoggerInterface;

/**
 * Generate Recommendations Use Case
 *
 * Orchestrates product recommendation generation using KNN ML service
 * with rule-based fallback when ML has insufficient data.
 * Handles data preparation, caching, and graceful degradation.
 */
class GenerateRecommendations
{
    private ProductRepositoryInterface $productRepository;
    private KNNService $knnService;
    private RuleBasedFallback $fallbackService;
    private LoggerInterface $logger;
    private RecommendationSettings $settings;
    private ExplanationGenerator $explanationGenerator;

    /** @var array<int, Product>|null */
    private ?array $productsCache = null;

    /** @var bool Whether KNN model has been trained */
    private bool $modelTrained = false;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        KNNService $knnService,
        RuleBasedFallback $fallbackService,
        LoggerInterface $logger,
        ?RecommendationSettings $settings = null,
        ?ExplanationGenerator $explanationGenerator = null,
        private readonly ?EventHistoryRepositoryInterface $history = null,
    ) {
        $this->productRepository = $productRepository;
        $this->knnService = $knnService;
        $this->fallbackService = $fallbackService;
        $this->logger = $logger;
        $this->settings = $settings ?? RecommendationSettings::fromArray([]);
        $this->explanationGenerator = $explanationGenerator ?? new ExplanationGenerator();
    }

    /**
     * Generate product recommendations for a target product
     *
     * @param int $targetProductId ID of product to get recommendations for
     * @param int $limit Number of recommendations to return
     * @return array<array<string, mixed>> Array of recommendation DTOs
     */
    public function execute(
        int $targetProductId,
        int $limit = 10,
        bool $insufficientData = false,
        ?string $fallbackStrategy = null,
        ?string $sessionId = null,
        ?string $userId = null,
    ): array {
        $strategy = $fallbackStrategy ?? $this->settings->getFallbackStrategy();

        try {
            // Load products to check if we have enough for ML
            $products = $this->loadProducts();

            // Get target product
            $targetProduct = $this->productRepository->findById($targetProductId);

            if ($targetProduct === null) {
                // Cold-start for an unknown target product: return popular fallback.
                $this->logFallbackActivated('cold_start_unknown_product', $targetProductId, 'popularity_only');
                $fallbackResults = $this->fallbackService->getPopularRecommendations($limit);
                $fallbackResults = $this->normalizeFallbackResults($fallbackResults);

                return $this->personalize($fallbackResults, $sessionId, $userId);
            }

            // Check if we should use fallback
            if ($insufficientData || $this->shouldUseFallback()) {
                $reason = $insufficientData ? 'insufficient_session_data' : 'insufficient_catalog_data';
                $this->logFallbackActivated($reason, $targetProductId, $strategy);

                $fallbackResults = $this->fallbackService->getRecommendations(
                    $targetProduct,
                    $limit,
                    $strategy
                );
                $fallbackResults = $this->normalizeFallbackResults($fallbackResults);
                $fallbackResults = $this->filterOutTargetProduct($fallbackResults, $targetProductId);

                return $this->personalize($fallbackResults, $sessionId, $userId);
            }

            // Ensure KNN model is trained
            $this->ensureModelTrained();

            // Generate recommendations using KNN
            $recommendations = $this->knnService->recommend($targetProduct, $limit);

            // Convert to DTO format (Story 3.5: enriched with explanation/confidence metadata)
            $formatted = $this->formatRecommendations($recommendations, $targetProduct);

            // Fill with fallback if ML returns fewer than requested
            if (count($formatted) < $limit) {
                $needed = $limit - count($formatted);
                $fallbackResults = $this->fallbackService->getRecommendations(
                    $targetProduct,
                    $needed,
                    $strategy
                );
                $fallbackResults = $this->normalizeFallbackResults($fallbackResults);
                $fallbackResults = $this->filterOutTargetProduct($fallbackResults, $targetProductId);
                $formatted = $this->mergeRecommendations($formatted, $fallbackResults, $limit);
            }

            return $this->personalize($formatted, $sessionId, $userId);

        } catch (RecommendationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('ML failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback to rule-based on error
            $targetProduct = $this->productRepository->findById($targetProductId);
            if ($targetProduct === null) {
                return [];
            }

            $this->logFallbackActivated('ml_error', $targetProductId, $strategy);
            $fallbackResults = $this->fallbackService->getRecommendations(
                $targetProduct,
                $limit,
                $strategy
            );
            $fallbackResults = $this->normalizeFallbackResults($fallbackResults);
            $fallbackResults = $this->filterOutTargetProduct($fallbackResults, $targetProductId);

            return $this->personalize($fallbackResults, $sessionId, $userId);
        }
    }

    /**
     * Determine if fallback should be used
     */
    private function shouldUseFallback(): bool
    {
        // Use fallback if we have too few products for ML
        return count($this->productsCache ?? []) < $this->settings->getMinProductsForMl();
    }

    /**
     * Ensure KNN model is trained with current product catalog
     */
    private function ensureModelTrained(): void
    {
        if ($this->knnService->isTrained()) {
            $this->modelTrained = true;

            return;
        }

        if ($this->modelTrained) {
            return;
        }

        $products = $this->loadProducts();

        if (count($products) < 2) {
            throw new \RuntimeException('At least 2 products required for recommendations');
        }

        $this->knnService->train($products, 5);
        $this->modelTrained = true;

        $this->logger->info('KNN model trained', [
            'k' => 5,
        ]);
    }

    /**
     * Load and cache products from repository.
     *
     * @return array<int, Product>
     */
    private function loadProducts(): array
    {
        if ($this->productsCache !== null) {
            return $this->productsCache;
        }

        $this->productsCache = $this->productRepository->findAll(1000, 0);

        return $this->productsCache;
    }

    /**
     * Format KNN results to recommendation DTOs, enriched with the
     * Story 3.5 explanation/confidence metadata (AC2, AC3, AC7, AC8).
     *
     * @param RecommendationResult[] $knnResults Raw results from KNNService
     * @return array<array<string, mixed>>
     */
    private function formatRecommendations(array $knnResults, Product $targetProduct): array
    {
        return array_map(function (RecommendationResult $result) use ($targetProduct): array {
            $explanation = $this->explanationGenerator->generateForML($result, $targetProduct);
            $reasons = $this->explanationGenerator->buildReasonsArray($result, $targetProduct);

            $enriched = new RecommendationResult(
                $result->getProductId(),
                $result->getProductName(),
                $result->getCategory(),
                $result->getPrice(),
                $result->getScore(),
                $result->getRank(),
                $explanation,
                $result->getConfidenceLevel(),
                $result->getScoreLabel(),
                $reasons
            );

            $data = RecommendationDTO::fromRecommendationResult($enriched)->toArray();
            $data['source'] = 'ml';

            return $data;
        }, $knnResults);
    }

    /**
     * Normalize fallback results to match API response shape (Story 3.5,
     * AC4/AC8): RuleBasedFallback already fills in explanation, reasons and
     * confidence metadata -- this only guards against a fallback
     * implementation that omits them, and adds the per-item 'source' field.
     *
     * @param array<array<string, mixed>> $fallbackResults
     * @return array<array<string, mixed>>
     */
    private function normalizeFallbackResults(array $fallbackResults): array
    {
        return array_map(function (array $rec): array {
            if (! isset($rec['name']) && isset($rec['product_name'])) {
                $rec['name'] = $rec['product_name'];
            }
            if (! isset($rec['fallback_reason'])) {
                $rec['fallback_reason'] = 'rule_based';
            }
            if (! isset($rec['explanation'])) {
                $rec['explanation'] = 'Fallback (rule-based): recommendation';
            }
            if (! isset($rec['reasons'])) {
                $rec['reasons'] = [];
            }
            if (! isset($rec['confidence_level']) || ! isset($rec['score_label'])) {
                $calculator = new ConfidenceCalculator();
                $score = isset($rec['score']) ? (float) $rec['score'] : 0.0;
                $rec['confidence_level'] = $rec['confidence_level'] ?? $calculator->calculateConfidenceLevel($score);
                $rec['score_label'] = $rec['score_label'] ?? $calculator->calculateScoreLabel($score);
            }
            $rec['source'] = $rec['fallback_reason'] === 'popular_product' ? 'popular' : 'rules';

            return $rec;
        }, $fallbackResults);
    }

    /**
     * @param array<array<string, mixed>> $recommendations
     * @return array<array<string, mixed>>
     */
    private function filterOutTargetProduct(array $recommendations, int $targetProductId): array
    {
        return array_values(array_filter($recommendations, function (array $rec) use ($targetProductId): bool {
            if (! isset($rec['product_id'])) {
                return true;
            }

            return (int) $rec['product_id'] !== $targetProductId;
        }));
    }

    /**
     * Merge ML and fallback recommendations without duplicates.
     *
     * @param array<array<string, mixed>> $primary
     * @param array<array<string, mixed>> $secondary
     * @return array<array<string, mixed>>
     */
    private function mergeRecommendations(array $primary, array $secondary, int $limit): array
    {
        $seen = [];
        foreach ($primary as $rec) {
            if (isset($rec['product_id'])) {
                $seen[(string) $rec['product_id']] = true;
            }
        }

        foreach ($secondary as $rec) {
            $id = isset($rec['product_id']) ? (string) $rec['product_id'] : null;
            if ($id !== null && isset($seen[$id])) {
                continue;
            }
            $primary[] = $rec;
            if ($id !== null) {
                $seen[$id] = true;
            }
            if (count($primary) >= $limit) {
                break;
            }
        }

        return $primary;
    }

    private function logFallbackActivated(string $reason, int $targetProductId, string $strategy): void
    {
        $this->logger->info('Fallback activated: ' . $reason, [
            'strategy_used' => $strategy,
            'products_count' => count($this->productsCache ?? []),
            'target_product_id' => $targetProductId,
        ]);
    }

    /**
     * Clear product cache (useful for testing or when products change)
     */
    public function clearCache(): void
    {
        $this->productsCache = null;
        $this->modelTrained = false;
    }

    /** @param array<array<string, mixed>> $recommendations @return array<array<string, mixed>> */
    private function personalize(array $recommendations, ?string $sessionId, ?string $userId): array
    {
        if ($this->history === null) {
            return $recommendations;
        }

        $normalizedUserId = $userId !== null ? trim($userId) : '';
        $normalizedSessionId = $sessionId !== null ? trim($sessionId) : '';
        if ($normalizedUserId === '' && $normalizedSessionId === '') {
            return $recommendations;
        }

        try {
            $events = $normalizedUserId !== ''
                ? $this->history->getByUserId($normalizedUserId)
                : $this->history->getBySession($normalizedSessionId);
        } catch (\Throwable $exception) {
            $this->logger->warning('Não foi possível ler histórico de recomendações.', [
                'error' => $exception->getMessage(),
            ]);

            return $recommendations;
        }

        $productWeights = [];
        $categoryWeights = [];
        foreach ($events as $event) {
            $productId = isset($event['product_id']) ? (int) $event['product_id'] : 0;
            if ($productId < 1) {
                continue;
            }
            $weight = ($event['event'] ?? '') === 'cart.item_added' ? 3 : 1;
            $productWeights[$productId] = ($productWeights[$productId] ?? 0) + $weight;

            try {
                $product = $this->productRepository->findById($productId);
            } catch (\Throwable $exception) {
                $this->logger->warning('Não foi possível carregar produto do histórico.', [
                    'product_id' => $productId,
                    'error' => $exception->getMessage(),
                ]);

                continue;
            }
            if ($product !== null) {
                $category = $product->getCategory();
                $categoryWeights[$category] = ($categoryWeights[$category] ?? 0) + $weight;
            }
        }
        if ($productWeights === [] && $categoryWeights === []) {
            return $recommendations;
        }

        $ranked = [];
        foreach ($recommendations as $index => $recommendation) {
            $score = ($productWeights[(int) ($recommendation['product_id'] ?? 0)] ?? 0)
                + ($categoryWeights[(string) ($recommendation['category'] ?? '')] ?? 0);
            $ranked[] = ['base_index' => $index, 'score' => $score, 'recommendation' => $recommendation];
        }
        usort(
            $ranked,
            static fn (array $left, array $right): int =>
                ($right['score'] <=> $left['score']) ?: ($left['base_index'] <=> $right['base_index'])
        );

        return array_column($ranked, 'recommendation');
    }
}
