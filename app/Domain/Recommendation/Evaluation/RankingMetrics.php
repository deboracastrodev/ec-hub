<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Evaluation;

use InvalidArgumentException;

/**
 * Story 10.2: ranking metrics for one query, pure PHP.
 *
 * precision@k = |top-k ∩ relevant| / k -- always divided by k, even when
 * the list has fewer than k items (a short list is a miss, not a pass).
 * recall@k    = |top-k ∩ relevant| / |relevant|.
 * A product repeated inside the top-k counts as a single hit.
 */
final class RankingMetrics
{
    /**
     * @param list<int> $recommendedIds best first
     * @param array<int> $relevantIds
     */
    public static function precisionAtK(array $recommendedIds, array $relevantIds, int $k): float
    {
        self::assertValidK($k);

        return self::hits($recommendedIds, $relevantIds, $k) / $k;
    }

    /**
     * @param list<int> $recommendedIds best first
     * @param array<int> $relevantIds non-empty
     */
    public static function recallAtK(array $recommendedIds, array $relevantIds, int $k): float
    {
        self::assertValidK($k);

        $relevantCount = count(array_unique($relevantIds));
        if ($relevantCount === 0) {
            throw new InvalidArgumentException('recall@k não é definido sem nenhum item relevante.');
        }

        return self::hits($recommendedIds, $relevantIds, $k) / $relevantCount;
    }

    /**
     * @param list<int> $recommendedIds
     * @param array<int> $relevantIds
     */
    private static function hits(array $recommendedIds, array $relevantIds, int $k): int
    {
        $topK = array_unique(array_slice($recommendedIds, 0, $k));
        $relevant = array_flip(array_unique($relevantIds));

        $hits = 0;
        foreach ($topK as $id) {
            if (isset($relevant[$id])) {
                $hits++;
            }
        }

        return $hits;
    }

    private static function assertValidK(int $k): void
    {
        if ($k < 1) {
            throw new InvalidArgumentException(sprintf('k precisa ser >= 1; recebido %d.', $k));
        }
    }
}
