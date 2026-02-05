<?php
declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;

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

    /**
     * Train KNN model with product dataset
     *
     * @param array $products Array of Product entities
     * @param int $k Number of neighbors (default: 5)
     * @return void
     */
    public function train(array $products, int $k = 5): void
    {
        $this->k = $k;

        // Get unique categories for one-hot encoding
        $this->categories = array_unique(array_map(function($p) {
            return $p->getCategory();
        }, $products));
        sort($this->categories);

        // Extract features from products
        $this->trainingSamples = [];
        foreach ($products as $index => $product) {
            $featureVector = $this->extractSingleProductFeatures($product);
            $this->trainingSamples[] = $featureVector;
            $this->productsIndex[$index] = $product;
        }

        // Normalize features (manual min-max scaling)
        $this->trainingSamples = $this->normalizeFeatures($this->trainingSamples);

        $this->isTrained = true;
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
        if (!$this->isTrained) {
            throw new \RuntimeException('KNN model must be trained before making recommendations');
        }

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
            $distance = $this->euclideanDistance($targetFeatures, $sample);
            $distances[$index] = $distance;
        }

        return $distances;
    }

    /**
     * Calculate Euclidean distance between two feature vectors
     */
    private function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        $count = min(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            $diff = (float) $a[$i] - (float) $b[$i];
            $sum += $diff * $diff;
        }

        return sqrt($sum);
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

            // Skip if same product
            if ($neighborProduct->getId() === $excludedId) {
                continue;
            }

            $distance = (float) $distances[$index];

            // Calculate similarity score (inverse of distance, normalized 0-100)
            // Closer distance = higher score
            $score = max(0, min(100, 100 * (1 / (1 + $distance))));

            $recommendations[] = [
                'product_id' => $neighborProduct->getId(),
                'product_name' => $neighborProduct->getName(),
                'category' => $neighborProduct->getCategory(),
                'price' => $neighborProduct->getPrice()->getFormatted(),
                'score' => $score,
                'rank' => $rank + 1,
                'explanation' => $this->generateExplanation($targetProduct, $neighborProduct),
            ];

            $rank++;
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

    /**
     * Get the number of neighbors used
     */
    public function getK(): int
    {
        return $this->k;
    }
}
