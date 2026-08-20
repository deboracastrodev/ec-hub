<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

use App\Domain\Product\Model\Product;

/**
 * Port for item-to-item nearest-neighbor search over the product catalog.
 *
 * The Domain depends on this interface only -- the ML library used to
 * implement it (Rubix ML) lives entirely behind it, in the Infrastructure
 * layer (R4.3). No Rubix type appears in this file.
 */
interface NeighborFinderInterface
{
    /**
     * Index the given products for nearest-neighbor search.
     *
     * @param Product[] $products
     */
    public function train(array $products): void;

    public function isTrained(): bool;

    /**
     * Find the k products closest to $target, ordered nearest first.
     *
     * @return list<array{product: Product, distance: float}>
     */
    public function nearest(Product $target, int $k): array;
}
