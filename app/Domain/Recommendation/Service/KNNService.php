<?php
declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Model\RecommendationResult;
use Rubix\ML\Kernels\Distance\Euclidean;

/**
 * K-Nearest Neighbors Recommendation Service
 *
 * Uses manual KNN implementation for PHP 7.4 compatibility.
 * This is the CORE DIFFERENTIAL of ec-hub - ML in PHP!
 */
class KNNService
{
    private array $productsIndex = [];
    private int $k = 5;
    private bool $isTrained = false;
    private array $featureRanges = [];
    private array $trainingSamples = [];
    private array $categories = [];
    private array $productCache = [];
    private ProductRepositoryInterface $productRepository;
    private Euclidean $distanceKernel;

    public function __construct(
        ProductRepositoryInterface $productRepository,
        ?Euclidean $distanceKernel = null
    ) {
        $this->productRepository = $productRepository;
        $this->distanceKernel = $distanceKernel ?? new Euclidean();
    }

    /**
     * Train KNN model with product dataset
     *
     * @param array|null $products Array of Product entities
     * @param int $k Number of neighbors (default: 5)
     * @return void
     */
    public function train(?array $products = null, int $k = 5): void
    {
        $this->k = $k;
        $products = $products ?? $this->loadProductsFromRepository();

        if (empty($products)) {
            throw new \RuntimeException('Nenhum produto disponível para treinar o KNN.');
        }

        $this->productCache = $products;
        $products = array_values($products);

        $this->categories = array_unique(array_map(static function (Product $product) {
            return $product->getCategory();
        }, $products));
        sort($this->categories);

        $this->productsIndex = [];
        $this->trainingSamples = [];

        foreach ($products as $product) {
            $featureVector = $this->extractSingleProductFeatures($product);
            $this->trainingSamples[] = $featureVector;
            $this->productsIndex[] = $product;
        }

        $this->trainingSamples = $this->normalizeFeatures($this->trainingSamples);
        $this->isTrained = true;
    }

    public function trainFromRepository(int $k = 5): void
    {
        $this->train(null, $k);
    }

    /**
     * Get product recommendations for a target product
     *
     * @param Product $targetProduct Product to get recommendations for
     * @param int $limit Number of recommendations to return
     * @return array Array of recommendations with scores
     */
    public function recommend(Product $targetProduct, int $limit = 10): array
    {
        $this->ensureModelIsTrained();

        // Extract and normalize target product features
        $targetFeatures = $this->extractSingleProductFeatures($targetProduct);
        $targetFeaturesNormalized = $this->normalizeSingleFeature($targetFeatures);

        // Calculate distances to all training samples
        $distances = $this->calculateDistances($targetFeaturesNormalized);

        // Sort by distance (ascending - closest first)
        asort($distances);

        // Get k nearest neighbors
        $neighborIndices = array_slice(array_keys($distances), 0, $this->k, true);

        // Convert predictions to recommendations with scores
        $recommendations = $this->buildRecommendations(
            $neighborIndices,
            $distances,
            $limit,
            $targetProduct
        );

        return $recommendations;
    }

    /**
     * Extract features from a single product
     */
    private function extractSingleProductFeatures(Product $product): array
    {
        $featureVector = [];

        // One-hot encode category
        foreach ($this->categories as $cat) {
            $featureVector[] = (int) ($product->getCategory() === $cat ? 1 : 0);
        }

        // Add price
        $featureVector[] = $product->getPrice()->getDecimal();

        return $featureVector;
    }

    /**
     * Calculate Euclidean distances from target to all training samples
     */
    private function calculateDistances(array $targetFeatures): array
    {
        $distances = [];

        foreach ($this->trainingSamples as $index => $sample) {
            $distances[$index] = $this->distanceKernel->compute($targetFeatures, $sample);
        }

        return $distances;
    }

    /**
     * Normalize features using min-max scaling
     */
    private function normalizeFeatures(array $samples): array
    {
        if (empty($samples)) {
            return $samples;
        }

        $numFeatures = count($samples[0]);
        $this->featureRanges = [];

        // Find min/max for each feature
        for ($i = 0; $i < $numFeatures; $i++) {
            $values = array_column($samples, $i);
            $this->featureRanges[$i] = [
                'min' => min($values),
                'max' => max($values),
            ];
        }

        // Normalize
        foreach ($samples as &$sample) {
            $sample = $this->normalizeSingleFeature($sample);
        }

        return $samples;
    }

    /**
     * Normalize a single feature vector using stored ranges
     */
    private function normalizeSingleFeature(array $feature): array
    {
        foreach ($feature as $i => $value) {
            $min = (float) ($this->featureRanges[$i]['min'] ?? 0);
            $max = (float) ($this->featureRanges[$i]['max'] ?? 1);
            $value = (float) $value;

            if ($max - $min == 0) {
                $feature[$i] = 0.0;
            } else {
                $feature[$i] = ($value - $min) / ($max - $min);
            }
        }

        return $feature;
    }

    /**
     * Build recommendation results from neighbor indices
     *
     * @param array $neighborIndices Array of neighbor indices (sorted by distance)
     * @param array $distances Array of distances indexed by sample index
     * @param int $limit Number of recommendations to return
     * @param Product $targetProduct Original product
     * @return array
     */
    private function buildRecommendations(
        array $neighborIndices,
        array $distances,
        int $limit,
        Product $targetProduct
    ): array {
        $recommendations = [];

        $excludedId = $targetProduct->getId();
        $rank = 0;

        foreach ($neighborIndices as $index) {
            if (count($recommendations) >= $limit) {
                break;
            }

            $neighborProduct = $this->productsIndex[$index];

            if ($neighborProduct->getId() === $excludedId) {
                continue;
            }

            $distance = (float) $distances[$index];
            $score = max(0, min(100, 100 * (1 / (1 + $distance))));

            $recommendations[] = new RecommendationResult(
                (int) $neighborProduct->getId(),
                $neighborProduct->getName(),
                $neighborProduct->getCategory(),
                $neighborProduct->getPrice()->getFormatted(),
                $score,
                ++$rank,
                $this->generateExplanation($targetProduct, $neighborProduct)
            );
        }

        return $recommendations;
    }

    /**
     * Generate explanation for why product was recommended
     */
    private function generateExplanation(Product $target, Product $recommended): string
    {
        $targetCategory = $target->getCategory();
        $recommendedCategory = $recommended->getCategory();

        if ($targetCategory === $recommendedCategory) {
            return sprintf(
                "Recomendado porque você visualizou \"%s\" que também é da categoria %s",
                $target->getName(),
                $targetCategory
            );
        }

        return sprintf(
            "Recomendado porque você está interessado em %s e este produto é similar (%s)",
            $targetCategory,
            $recommendedCategory
        );
    }

    /**
     * Check if model is trained and ready
     */
    public function isTrained(): bool
    {
        return $this->isTrained;
    }

    public function getK(): int
    {
        return $this->k;
    }

    private function ensureModelIsTrained(): void
    {
        if ($this->isTrained) {
            return;
        }

        $products = $this->productCache ?: $this->loadProductsFromRepository();
        $this->train($products, $this->k);
    }
    /**
     * Load and convert products from repository.
     *
     * @return Product[]
     */
    private function loadProductsFromRepository(): array
    {
        $rawProducts = $this->productRepository->findAll(1000, 0);

        return array_map(static function (array $item): Product {
            return Product::fromArray($item);
        }, $rawProducts);
    }
}
