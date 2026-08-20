<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Model\RecommendationResult;

/**
 * K-Nearest Neighbors Recommendation Service
 *
 * Orchestrates item-to-item recommendations: asks a NeighborFinderInterface
 * (Rubix ML, in Infrastructure -- see R4.3) for the closest products by
 * category + price, then turns the result into scored, explained
 * RecommendationResult entries. Scoring and explanation text are business
 * rules and stay here in the Domain.
 */
class KNNService
{
    private ProductRepositoryInterface $productRepository;
    private NeighborFinderInterface $neighborFinder;
    private int $k = 5;
    private array $productCache = [];

    public function __construct(
        ProductRepositoryInterface $productRepository,
        NeighborFinderInterface $neighborFinder
    ) {
        $this->productRepository = $productRepository;
        $this->neighborFinder = $neighborFinder;
    }

    /**
     * Train the neighbor index with a product dataset.
     *
     * @param Product[]|null $products
     */
    public function train(?array $products = null, int $k = 5): void
    {
        $this->k = $k;
        $products = $products ?? $this->loadProductsFromRepository();

        $this->productCache = $products;
        $this->neighborFinder->train($products);
    }

    public function trainFromRepository(int $k = 5): void
    {
        $this->train(null, $k);
    }

    /**
     * Get product recommendations for a target product.
     *
     * Asks for limit+1 neighbors (R4.1): the query product is frequently
     * its own nearest neighbor (distance 0), and the +1 leaves room to drop
     * it and still return exactly $limit results, instead of the fixed
     * internal k silently capping every response regardless of $limit.
     *
     * @return RecommendationResult[]
     */
    public function recommend(Product $targetProduct, int $limit = 10): array
    {
        $this->ensureModelIsTrained();

        $neighbors = $this->neighborFinder->nearest($targetProduct, $limit + 1);

        return $this->buildRecommendations($neighbors, $limit, $targetProduct);
    }

    /**
     * @param list<array{product: Product, distance: float}> $neighbors
     * @return RecommendationResult[]
     */
    private function buildRecommendations(array $neighbors, int $limit, Product $targetProduct): array
    {
        $recommendations = [];
        $excludedId = $targetProduct->getId();
        $rank = 0;

        foreach ($neighbors as $neighbor) {
            if (count($recommendations) >= $limit) {
                break;
            }

            $neighborProduct = $neighbor['product'];

            if ($neighborProduct->getId() === $excludedId) {
                continue;
            }

            $score = max(0, min(100, 100 * (1 / (1 + $neighbor['distance']))));

            $recommendations[] = new RecommendationResult(
                (int) $neighborProduct->getId(),
                $neighborProduct->getName(),
                $neighborProduct->getCategory(),
                $neighborProduct->getPrice()->getDecimal(),
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
        return $this->neighborFinder->isTrained();
    }

    public function getK(): int
    {
        return $this->k;
    }

    private function ensureModelIsTrained(): void
    {
        if ($this->neighborFinder->isTrained()) {
            return;
        }

        $products = $this->productCache ?: $this->loadProductsFromRepository();
        $this->train($products, $this->k);
    }

    /**
     * Load products from repository.
     *
     * @return Product[]
     */
    private function loadProductsFromRepository(): array
    {
        return $this->productRepository->findAll(1000, 0);
    }
}
