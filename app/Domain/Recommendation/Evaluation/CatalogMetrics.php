<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Evaluation;

use InvalidArgumentException;

/**
 * Story 10.3: catalog-level metrics over many recommendation lists, pure PHP.
 *
 * coverage            = |distinct candidate ids present in some list| / |candidates|;
 *                       ids outside the candidates are ignored.
 * intraListDiversity  = pairs with different categories / (n·(n−1)/2), null when n < 2
 *                       (category distance: 0 if equal, 1 if different).
 * gini                = Σᵢ(2i − n − 1)·cᵢ / (n·Σc), counts sorted ascending, i = 1..n;
 *                       null when Σc = 0.
 * topShare            = share of Σc held by the topGroupSize(n, ratio) = ceil(ratio × n)
 *                       largest counts; null when Σc = 0.
 *
 * Nothing is rounded here: the caller (use case) rounds.
 */
final class CatalogMetrics
{
    /**
     * @param list<list<int>> $lists recommended ids, one list per query
     * @param list<int> $candidateIds products that can be recommended (non-empty)
     * @return array{covered: int, coverage: float}
     */
    public static function coverage(array $lists, array $candidateIds): array
    {
        $candidates = array_flip(array_unique($candidateIds));
        if ($candidates === []) {
            throw new InvalidArgumentException('A cobertura não é definida sem nenhum produto candidato.');
        }

        $covered = [];
        foreach ($lists as $list) {
            foreach ($list as $id) {
                if (isset($candidates[$id])) {
                    $covered[$id] = true;
                }
            }
        }

        return ['covered' => count($covered), 'coverage' => (float) (count($covered) / count($candidates))];
    }

    /**
     * @param list<string> $categories category of each item of one list
     */
    public static function intraListDiversity(array $categories): ?float
    {
        $n = count($categories);
        if ($n < 2) {
            return null;
        }

        $different = 0;
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($categories[$i] !== $categories[$j]) {
                    $different++;
                }
            }
        }

        return (float) ($different / ($n * ($n - 1) / 2));
    }

    /**
     * @param list<int> $counts appearances per candidate, zeros included
     */
    public static function gini(array $counts): ?float
    {
        self::assertValidCounts($counts);

        $total = array_sum($counts);
        if ($total === 0) {
            return null;
        }

        sort($counts);
        $n = count($counts);
        $weighted = 0;
        foreach ($counts as $index => $count) {
            $weighted += (2 * ($index + 1) - $n - 1) * $count;
        }

        return (float) ($weighted / ($n * $total));
    }

    /**
     * @param list<int> $counts appearances per candidate, zeros included
     * @param float $ratio fraction of candidates in the "top", in (0, 1]
     */
    public static function topShare(array $counts, float $ratio): ?float
    {
        self::assertValidRatio($ratio);
        self::assertValidCounts($counts);

        $total = array_sum($counts);
        if ($total === 0) {
            return null;
        }

        rsort($counts);
        $top = self::topGroupSize(count($counts), $ratio);

        return (float) (array_sum(array_slice($counts, 0, $top)) / $total);
    }

    /**
     * Size of the "top" group: ceil(ratio × candidates).
     *
     * @param float $ratio in (0, 1]
     */
    public static function topGroupSize(int $candidates, float $ratio): int
    {
        self::assertValidRatio($ratio);

        // round() antes do ceil(): 0.1 × 30 = 3.0000000000000004 em ponto flutuante.
        return (int) ceil(round($ratio * $candidates, 9));
    }

    private static function assertValidRatio(float $ratio): void
    {
        if (! ($ratio > 0.0 && $ratio <= 1.0)) {
            throw new InvalidArgumentException(sprintf('A proporção do top share precisa estar em (0, 1]; recebido %g.', $ratio));
        }
    }

    /**
     * @param list<int> $counts
     */
    private static function assertValidCounts(array $counts): void
    {
        foreach ($counts as $count) {
            if ($count < 0) {
                throw new InvalidArgumentException(sprintf('Contagem de aparições não pode ser negativa; recebido %d.', $count));
            }
        }
    }
}
