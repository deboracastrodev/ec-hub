<?php
declare(strict_types=1);

namespace App\Application\Recommendation;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\KNNService;
use Psr\Log\LoggerInterface;

/**
 * Generate Recommendations Use Case
 *
 * Orchestrates product recommendation generation using KNN ML service.
 * Handles data preparation, caching, and graceful degradation.
 */
class GenerateRecommendations
{
    private ProductRepositoryInterface $productRepository;
    private KNNService $knnService;
    private LoggerInterface $logger;

    /** @var bool Whether KNN model has been trained */
    private bool $modelTrained = false;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        KNNService $knnService,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->knnService = $knnService;
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
            // Ensure KNN model is trained
            $this->ensureModelTrained();

            // Get target product
            $targetProductData = $this->productRepository->findById($targetProductId);

            if ($targetProductData === null) {
                throw new RecommendationException(
                    sprintf('Product with ID %d not found', $targetProductId)
                );
            }

            // Convert array to Product entity
            $targetProduct = Product::fromArray($targetProductData);

            // Generate recommendations using KNN
            $recommendations = $this->knnService->recommend($targetProduct, $limit);

            // Convert to DTO format
            return $this->formatRecommendations($recommendations);

        } catch (RecommendationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate recommendations', [
                'target_product_id' => $targetProductId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Graceful degradation - return empty recommendations
            return [];
        }
    }

    /**
     * Ensure KNN model is trained with current product catalog
     */
    private function ensureModelTrained(): void
    {
        // Check if KNN service is already trained
        if ($this->knnService->isTrained()) {
            $this->modelTrained = true;
            return;
        }

        // Check if we already trained in this instance
        if ($this->modelTrained) {
            return;
        }

        // Train KNN model with products from repository
        $this->knnService->trainFromRepository(k: 5);
        $this->modelTrained = true;

        $this->logger->info('KNN model trained', [
            'k' => 5,
        ]);
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
            fn(RecommendationResult $result) => $result->toArray(),
            $knnResults
        );
    }

    /**
     * Clear product cache (useful for testing or when products change)
     */
    public function clearCache(): void
    {
        $this->modelTrained = false;
    }
}
