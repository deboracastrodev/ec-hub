<?php

declare(strict_types=1);

namespace App\Application\Recommendation\Evaluation;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Evaluation\HoldoutSplit;
use App\Domain\Recommendation\Evaluation\RankingMetrics;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use Closure;
use InvalidArgumentException;

/**
 * Story 10.2: offline evaluation of a recommendation strategy.
 *
 * Splits the catalog (HoldoutSplit), trains a fresh strategy with the train
 * part only, asks top-max(k) for every holdout product and averages
 * precision@k / recall@k (macro average over the evaluated queries).
 *
 * Relevance (ground truth): for a holdout query q, the relevant products
 * are the TRAIN products with q's category. Queries without any relevant
 * product are left out of both averages and counted in without_relevant.
 *
 * The strategy is called directly -- not through GenerateRecommendations,
 * whose cold-start fallback would answer for holdout products (they are
 * not in any repository). That fallback is Story 10.4's subject.
 */
final class OfflineEvaluation
{
    public const RELEVANCE = 'same_category';
    private const DECIMALS = 4;

    /** @var list<int> */
    private readonly array $ks;

    /**
     * @param Closure(list<Product>): RecommendationStrategy $strategyFactory a fresh, untrained
     *        strategy per run; receives the train split only, so nothing it builds (e.g. a
     *        repository for lazy training) can see the holdout
     * @param array<mixed> $ks positive integers
     */
    public function __construct(
        private readonly Closure $strategyFactory,
        array $ks = [1, 5, 10],
        private readonly float $holdoutRatio = 0.2,
    ) {
        if ($ks === []) {
            throw new InvalidArgumentException('Informe pelo menos um valor de k.');
        }
        $valid = [];
        foreach ($ks as $k) {
            if (! is_int($k) || $k < 1) {
                throw new InvalidArgumentException('Todo k precisa ser um inteiro >= 1.');
            }
            $valid[$k] = $k;
        }
        ksort($valid);
        $this->ks = array_values($valid);
    }

    /**
     * @param list<Product> $products
     * @return array{
     *     algorithm: string,
     *     split: array{seed: int, holdout_ratio: float, train_size: int, holdout_size: int, holdout_ids: list<int>},
     *     relevance: string,
     *     queries: array{evaluated: int, without_relevant: int},
     *     metrics: array{precision_at_k: array<int, float|null>, recall_at_k: array<int, float|null>}
     * }
     */
    public function run(array $products, int $seed): array
    {
        $split = HoldoutSplit::fromProducts($products, $seed, $this->holdoutRatio);

        $strategy = ($this->strategyFactory)($split->train());
        $strategy->train($split->train());

        $trainIdsByCategory = [];
        foreach ($split->train() as $product) {
            $trainIdsByCategory[$product->getCategory()][] = (int) $product->getId();
        }

        $limit = max($this->ks);
        $precisionSums = array_fill_keys($this->ks, 0.0);
        $recallSums = array_fill_keys($this->ks, 0.0);
        $evaluated = 0;
        $withoutRelevant = 0;

        foreach ($split->holdout() as $query) {
            $relevantIds = $trainIdsByCategory[$query->getCategory()] ?? [];
            if ($relevantIds === []) {
                $withoutRelevant++;

                continue;
            }

            $recommendedIds = [];
            foreach ($strategy->recommend($query, $limit) as $result) {
                $recommendedIds[] = $result->getProductId();
            }

            foreach ($this->ks as $k) {
                $precisionSums[$k] += RankingMetrics::precisionAtK($recommendedIds, $relevantIds, $k);
                $recallSums[$k] += RankingMetrics::recallAtK($recommendedIds, $relevantIds, $k);
            }
            $evaluated++;
        }

        $holdoutIds = array_map(static fn (Product $p): int => (int) $p->getId(), $split->holdout());
        sort($holdoutIds);

        return [
            'algorithm' => $strategy->getName(),
            'split' => [
                'seed' => $seed,
                'holdout_ratio' => $this->holdoutRatio,
                'train_size' => count($split->train()),
                'holdout_size' => count($split->holdout()),
                'holdout_ids' => $holdoutIds,
            ],
            'relevance' => self::RELEVANCE,
            'queries' => [
                'evaluated' => $evaluated,
                'without_relevant' => $withoutRelevant,
            ],
            'metrics' => [
                'precision_at_k' => $this->averages($precisionSums, $evaluated),
                'recall_at_k' => $this->averages($recallSums, $evaluated),
            ],
        ];
    }

    /**
     * @param array<int, float> $sums
     * @return array<int, float|null> null when no query could be evaluated
     */
    private function averages(array $sums, int $evaluated): array
    {
        $averages = [];
        foreach ($sums as $k => $sum) {
            $averages[$k] = $evaluated > 0 ? round($sum / $evaluated, self::DECIMALS) : null;
        }

        return $averages;
    }
}
