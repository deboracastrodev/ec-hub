<?php
declare(strict_types=1);

namespace App\Application\Recommendation;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\KNNService;
use App\Domain\Recommendation\Service\RuleBasedFallback;
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
    private string $fallbackStrategy;
    private int $minProductsForMl;

    /** @var array<int, Product>|null */
    private ?array $productsCache = null;

    /** @var bool Whether KNN model has been trained */
    private bool $modelTrained = false;

    /** @var int Minimum products needed for ML recommendations */
    private const MIN_PRODUCTS_FOR_ML = 5;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        KNNService $knnService,
        RuleBasedFallback $fallbackService,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->knnService = $knnService;
        $this->fallbackService = $fallbackService;
        $this->logger = $logger;
        $this->fallbackStrategy = $this->resolveFallbackStrategy();
        $this->minProductsForMl = $this->resolveMinProductsForMl();
    }

    /**
     * Generate product recommendations for a target product
     *
     * @param int $targetProductId ID of product to get recommendations for
     * @param int $limit Number of recommendations to return
     * @return array<array<string, mixed>> Array of recommendation DTOs
     * @throws RecommendationException If target product not found
     */
    public function execute(
        int $targetProductId,
        int $limit = 10,
        bool $insufficientData = false,
        ?string $fallbackStrategy = null
    ): array
    {
        $strategy = $fallbackStrategy ?? $this->fallbackStrategy;

        try {
            // Load products to check if we have enough for ML
            $products = $this->loadProducts();

            // Get target product
            $targetProductData = $this->productRepository->findById($targetProductId);

            if ($targetProductData === null) {
                // Cold-start for unknown user/item context: return popular fallback.
                $this->logFallbackActivated('cold_start_unknown_user', $targetProductId, 'popularity_only');
                $fallbackResults = $this->fallbackService->getPopularRecommendations($limit);
                $fallbackResults = $this->normalizeFallbackResults($fallbackResults);
                return $fallbackResults;
            }

            // Convert array to Product entity
            $targetProduct = $this->arrayToProduct($targetProductData);

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
                return $fallbackResults;
            }

            // Ensure KNN model is trained
            $this->ensureModelTrained();

            // Generate recommendations using KNN
            $recommendations = $this->knnService->recommend($targetProduct, $limit);

            // Convert to DTO format
            $formatted = $this->formatRecommendations($recommendations);

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

            return $formatted;

        } catch (RecommendationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('ML failed, using fallback', [
                'error' => $e->getMessage(),
            ]);

            // Fallback to rule-based on error
            $targetProductData = $this->productRepository->findById($targetProductId);
            if ($targetProductData === null) {
                return [];
            }

            $targetProduct = $this->arrayToProduct($targetProductData);

            $this->logFallbackActivated('ml_error', $targetProductId, $strategy);
            $fallbackResults = $this->fallbackService->getRecommendations(
                $targetProduct,
                $limit,
                $strategy
            );
            $fallbackResults = $this->normalizeFallbackResults($fallbackResults);
            $fallbackResults = $this->filterOutTargetProduct($fallbackResults, $targetProductId);
            return $fallbackResults;
        }
    }

    /**
     * Determine if fallback should be used
     */
    private function shouldUseFallback(): bool
    {
        // Use fallback if we have too few products for ML
        return count($this->productsCache ?? []) < $this->minProductsForMl;
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

        $productData = $this->productRepository->findAll(1000, 0);

        $this->productsCache = array_map(
            fn(array $data) => $this->arrayToProduct($data),
            $productData
        );

        return $this->productsCache;
    }

    /**
     * Convert repository array to Product entity.
     */
    private function arrayToProduct(array $data): Product
    {
        return Product::fromArray($data);
    }

    /**
     * Format KNN results to recommendation DTOs
     *
     * @param array $knnResults Raw results from KNNService
     * @return array<array<string, mixed>>
     */
    private function formatRecommendations(array $knnResults): array
    {
        return array_map(
            fn(RecommendationResult $result) => RecommendationDTO::fromRecommendationResult($result)->toArray(),
            $knnResults
        );
    }

    /**
     * Normalize fallback results to match API response shape.
     *
     * @param array<array<string, mixed>> $fallbackResults
     * @return array<array<string, mixed>>
     */
    private function normalizeFallbackResults(array $fallbackResults): array
    {
        return array_map(function (array $rec): array {
            if (!isset($rec['name']) && isset($rec['product_name'])) {
                $rec['name'] = $rec['product_name'];
            }
            if (!isset($rec['fallback_reason'])) {
                $rec['fallback_reason'] = 'rule_based';
            }
            if (!isset($rec['explanation'])) {
                $rec['explanation'] = 'Fallback (rule-based): recommendation';
            }
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
            if (!isset($rec['product_id'])) {
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

    private function resolveFallbackStrategy(): string
    {
        $configPath = dirname(__DIR__, 3) . '/config/recommendation.php';
        if (is_file($configPath)) {
            $config = require $configPath;
            if (is_array($config) && isset($config['fallback']['strategy'])) {
                return (string) $config['fallback']['strategy'];
            }
        }

        $env = getenv('RECOMMENDATION_FALLBACK_STRATEGY');
        return $env !== false ? (string) $env : 'hybrid';
    }

    private function resolveMinProductsForMl(): int
    {
        $configPath = dirname(__DIR__, 3) . '/config/recommendation.php';
        if (is_file($configPath)) {
            $config = require $configPath;
            if (is_array($config) && isset($config['fallback']['min_products_for_ml'])) {
                return (int) $config['fallback']['min_products_for_ml'];
            }
        }

        $env = getenv('RECOMMENDATION_MIN_PRODUCTS_FOR_ML');
        return $env !== false ? (int) $env : self::MIN_PRODUCTS_FOR_ML;
    }

    /**
     * Clear product cache (useful for testing or when products change)
     */
    public function clearCache(): void
    {
        $this->productsCache = null;
        $this->modelTrained = false;
    }
}
