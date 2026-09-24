<?php

declare(strict_types=1);

namespace App\Domain\Product\Service;

use App\Domain\Product\Model\Product;
use App\Domain\Product\Model\SearchQuery;

/**
 * Relevance of a product search (Story 8.5, FR109).
 *
 * Pure Domain rule, so the order is the same whatever repository produced
 * the candidates: for each term, +3 when the normalized name contains it and
 * +1 when the normalized description does. Score 0 is dropped; ties break by
 * normalized name, then id, so the result is deterministic.
 */
final class ProductSearchRanker
{
    public const NAME_WEIGHT = 3;
    public const DESCRIPTION_WEIGHT = 1;

    /**
     * @param list<Product> $products
     * @return list<Product>
     */
    public function rank(array $products, SearchQuery $query): array
    {
        $terms = $query->terms();
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach ($products as $product) {
            $name = SearchQuery::normalize($product->getName());
            $description = SearchQuery::normalize($product->getDescription());
            $score = 0;
            foreach ($terms as $term) {
                $score += (str_contains($name, $term) ? self::NAME_WEIGHT : 0)
                    + (str_contains($description, $term) ? self::DESCRIPTION_WEIGHT : 0);
            }
            if ($score > 0) {
                $scored[] = ['product' => $product, 'score' => $score, 'name' => $name, 'id' => $product->getId() ?? 0];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']
            ?: strcmp($a['name'], $b['name'])
            ?: $a['id'] <=> $b['id']);

        return array_map(static fn (array $entry): Product => $entry['product'], $scored);
    }
}
