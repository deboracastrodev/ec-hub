<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Model\RecommendationResult;

/**
 * Port for a recommendation algorithm (Strategy Pattern, FR103).
 *
 * The Application use case (GenerateRecommendations) depends on this
 * interface only, so the active algorithm is picked once, in the container
 * (config/bootstrap.php, RECOMMENDATION_ALGORITHM), instead of being
 * hard-wired by concrete type. No Rubix or Infrastructure type appears in
 * this file (ADR-003): an implementation that needs a library hides it
 * behind its own port, as KNNService does with NeighborFinderInterface.
 */
interface RecommendationStrategy
{
    /**
     * Canonical algorithm name, as accepted by RECOMMENDATION_ALGORITHM
     * and exposed in the API's meta.algorithm (e.g. 'knn', 'collaborative').
     */
    public function getName(): string;

    public function isTrained(): bool;

    /**
     * Build the model over the given product catalog.
     *
     * @param Product[] $products
     */
    public function train(array $products): void;

    /**
     * Recommend up to $limit products for $target, best first. The target
     * itself is never part of the result.
     *
     * A result that already carries reasons keeps its own explanation and
     * reasons; results with empty reasons get the KNN (similarity)
     * explanation from GenerateRecommendations::formatRecommendations().
     *
     * @return RecommendationResult[]
     */
    public function recommend(Product $target, int $limit): array;
}
