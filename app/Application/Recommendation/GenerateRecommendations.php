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
    }

    /**
     * Generate product recommendations for a target product
     *
     * @param int $targetProductId ID of product to get recommendations for
     * @param int $limit Number of recommendations to return
     * @return array<array<string, mixed>> Array of recommendation DTOs
     * @throws RecommendationException If target product not found
     */
    public function execute(int $targetProductId, int $limit = 10): array
    {
        try {
            // Load products to check if we have enough for ML
            $products = $this->loadProducts();

            // Get target product
            $targetProductData = $this->productRepository->findById($targetProductId);

            if ($targetProductData === null) {
                throw new RecommendationException(
                    sprintf('Product with ID %d not found', $targetProductId)
                );
            }

            // Convert array to Product entity
            $targetProduct = $this->arrayToProduct($targetProductData);

            // Check if we should use fallback
            if ($this->shouldUseFallback()) {
                $this->logger->info('Using rule-based fallback', [
                    'reason' => 'insufficient_session_data',
                    'product_count' => count($this->productsCache),
                ]);

                return $this->fallbackService->getRecommendations(
                    $targetProduct,
                    $limit,
                    'hybrid'
                );
            }

            // Ensure KNN model is trained
            $this->ensureModelTrained();

            // Generate recommendations using KNN
            $recommendations = $this->knnService->recommend($targetProduct, $limit);

            // Convert to DTO format
            return $this->formatRecommendations($recommendations);

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

            return $this->fallbackService->getRecommendations(
                $targetProduct,
                $limit,
                'hybrid'
            );
        }
    }

    /**
     * Determine if fallback should be used
     */
    private function shouldUseFallback(): bool
    {
        // Use fallback if we have too few products for ML
        return count($this->productsCache ?? []) < self::MIN_PRODUCTS_FOR_ML;
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
     * Clear product cache (useful for testing or when products change)
     */
    public function clearCache(): void
    {
        $this->productsCache = null;
        $this->modelTrained = false;
    }
}
