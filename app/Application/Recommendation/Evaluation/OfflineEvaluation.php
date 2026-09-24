<?php

declare(strict_types=1);

namespace App\Application\Recommendation\Evaluation;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Evaluation\CatalogMetrics;
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
 * Story 10.3: the same run also measures, per k, over the top-k lists of
 * ALL holdout queries (with or without relevant): catalog coverage (the
 * candidates are the train products -- the only ones the index can
 * recommend), intra-list diversity by category and concentration (Gini and
 * the share of appearances held by the top TOP_SHARE_RATIO of candidates;
 * excessive when that share >= EXCESSIVE_TOP_SHARE, a threshold fixed
 * before measuring). Ids outside the train split are ignored there. For
 * these catalog metrics each list is deduplicated by id (first occurrence
 * kept) before taking the top-k; precision/recall keep the raw list.
 *
 * The strategy is called directly -- not through GenerateRecommendations,
 * so every list here is pure ML output. The rule-based fallback is measured
 * by ColdStartEvaluation (Story 10.4), which runs the real use case and
 * reuses measure() so both populations get exactly these formulas.
 */
final class OfflineEvaluation
{
    public const RELEVANCE = 'same_category';
    public const DIVERSITY_DISTANCE = 'category';
    public const TOP_SHARE_RATIO = 0.1;
    public const EXCESSIVE_TOP_SHARE = 0.5;
    public const MOST_RECOMMENDED_LIMIT = 5;
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
     *     metrics: array{precision_at_k: array<int, float|null>, recall_at_k: array<int, float|null>},
     *     coverage: array{
     *         candidate_products: int,
     *         lists: int,
     *         covered_products_at_k: array<int, int>,
     *         catalog_coverage_at_k: array<int, float>
     *     },
     *     diversity: array{
     *         distance: string,
     *         intra_list_diversity_at_k: array<int, float|null>,
     *         distinct_categories_at_k: array<int, float|null>
     *     },
     *     concentration: array{
     *         top_share_ratio: float,
     *         excessive_top_share: float,
     *         top_products: int,
     *         gini_at_k: array<int, float|null>,
     *         top_share_at_k: array<int, float|null>,
     *         excessive_at_k: array<int, bool|null>,
     *         most_recommended: list<array{product_id: int, appearances: int}>
     *     }
     * }
     */
    public function run(array $products, int $seed): array
    {
        $split = HoldoutSplit::fromProducts($products, $seed, $this->holdoutRatio);

        $strategy = ($this->strategyFactory)($split->train());
        $strategy->train($split->train());

        $limit = max($this->ks);
        $listsByQuery = [];
        foreach ($split->holdout() as $query) {
            // Toda consulta gera uma lista: cobertura e diversidade não dependem de relevante.
            $items = [];
            foreach ($strategy->recommend($query, $limit) as $result) {
                $items[] = ['product_id' => $result->getProductId(), 'category' => $result->getCategory()];
            }
            $listsByQuery[(int) $query->getId()] = $items;
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
        ] + $this->measure($split, $listsByQuery);
    }

    /**
     * Story 10.4: the per-population aggregation, shared by run() and
     * ColdStartEvaluation. One list per holdout query that appears in
     * $listsByQuery (keyed by the query's product id, visited in holdout
     * order); a query missing from the map is left out of the population.
     * Relevance = train products with the query's category; candidates = train.
     *
     * @param array<int, list<array{product_id: int, category: string}>> $listsByQuery
     * @return array{
     *     queries: array{evaluated: int, without_relevant: int},
     *     metrics: array{precision_at_k: array<int, float|null>, recall_at_k: array<int, float|null>},
     *     coverage: array{
     *         candidate_products: int,
     *         lists: int,
     *         covered_products_at_k: array<int, int>,
     *         catalog_coverage_at_k: array<int, float>
     *     },
     *     diversity: array{
     *         distance: string,
     *         intra_list_diversity_at_k: array<int, float|null>,
     *         distinct_categories_at_k: array<int, float|null>
     *     },
     *     concentration: array{
     *         top_share_ratio: float,
     *         excessive_top_share: float,
     *         top_products: int,
     *         gini_at_k: array<int, float|null>,
     *         top_share_at_k: array<int, float|null>,
     *         excessive_at_k: array<int, bool|null>,
     *         most_recommended: list<array{product_id: int, appearances: int}>
     *     }
     * }
     */
    public function measure(HoldoutSplit $split, array $listsByQuery): array
    {
        $trainIdsByCategory = [];
        foreach ($split->train() as $product) {
            $trainIdsByCategory[$product->getCategory()][] = (int) $product->getId();
        }

        $precisionSums = array_fill_keys($this->ks, 0.0);
        $recallSums = array_fill_keys($this->ks, 0.0);
        $evaluated = 0;
        $withoutRelevant = 0;
        /** @var list<array{ids: list<int>, categories: list<string>}> $lists */
        $lists = [];

        foreach ($split->holdout() as $query) {
            $items = $listsByQuery[(int) $query->getId()] ?? null;
            if ($items === null) {
                continue;
            }

            $recommendedIds = [];
            $unique = [];
            foreach ($items as $item) {
                $recommendedIds[] = $item['product_id'];
                // Nas métricas de catálogo, id repetido na lista conta uma vez (primeira ocorrência).
                $unique[$item['product_id']] ??= $item['category'];
            }
            $lists[] = ['ids' => array_keys($unique), 'categories' => array_values($unique)];

            $relevantIds = $trainIdsByCategory[$query->getCategory()] ?? [];
            if ($relevantIds === []) {
                $withoutRelevant++;

                continue;
            }

            foreach ($this->ks as $k) {
                $precisionSums[$k] += RankingMetrics::precisionAtK($recommendedIds, $relevantIds, $k);
                $recallSums[$k] += RankingMetrics::recallAtK($recommendedIds, $relevantIds, $k);
            }
            $evaluated++;
        }

        $candidateIds = array_map(static fn (Product $p): int => (int) $p->getId(), $split->train());

        return [
            'queries' => [
                'evaluated' => $evaluated,
                'without_relevant' => $withoutRelevant,
            ],
            'metrics' => [
                'precision_at_k' => $this->averages($precisionSums, $evaluated),
                'recall_at_k' => $this->averages($recallSums, $evaluated),
            ],
            'coverage' => $this->coverage($lists, $candidateIds),
            'diversity' => $this->diversity($lists),
            'concentration' => $this->concentration($lists, $candidateIds),
        ];
    }

    /** @return list<int> the ks, ascending and unique */
    public function ks(): array
    {
        return $this->ks;
    }

    /**
     * @param list<array{ids: list<int>, categories: list<string>}> $lists
     * @param list<int> $candidateIds
     * @return array{
     *     candidate_products: int,
     *     lists: int,
     *     covered_products_at_k: array<int, int>,
     *     catalog_coverage_at_k: array<int, float>
     * }
     */
    private function coverage(array $lists, array $candidateIds): array
    {
        $covered = [];
        $coverage = [];
        foreach ($this->ks as $k) {
            $idsAtK = array_map(static fn (array $list): array => array_slice($list['ids'], 0, $k), $lists);
            $measured = CatalogMetrics::coverage($idsAtK, $candidateIds);
            $covered[$k] = $measured['covered'];
            $coverage[$k] = round($measured['coverage'], self::DECIMALS);
        }

        return [
            'candidate_products' => count(array_unique($candidateIds)),
            'lists' => count($lists),
            'covered_products_at_k' => $covered,
            'catalog_coverage_at_k' => $coverage,
        ];
    }

    /**
     * @param list<array{ids: list<int>, categories: list<string>}> $lists
     * @return array{
     *     distance: string,
     *     intra_list_diversity_at_k: array<int, float|null>,
     *     distinct_categories_at_k: array<int, float|null>
     * }
     */
    private function diversity(array $lists): array
    {
        $ild = [];
        $distinct = [];
        foreach ($this->ks as $k) {
            $ildSum = 0.0;
            $ildLists = 0;
            $distinctSum = 0;
            foreach ($lists as $list) {
                $categories = array_slice($list['categories'], 0, $k);
                $value = CatalogMetrics::intraListDiversity($categories);
                if ($value !== null) {
                    $ildSum += $value;
                    $ildLists++;
                }
                $distinctSum += count(array_unique($categories));
            }
            $ild[$k] = $ildLists > 0 ? round($ildSum / $ildLists, self::DECIMALS) : null;
            $distinct[$k] = $lists !== [] ? round($distinctSum / count($lists), self::DECIMALS) : null;
        }

        return [
            'distance' => self::DIVERSITY_DISTANCE,
            'intra_list_diversity_at_k' => $ild,
            'distinct_categories_at_k' => $distinct,
        ];
    }

    /**
     * @param list<array{ids: list<int>, categories: list<string>}> $lists
     * @param list<int> $candidateIds
     * @return array{
     *     top_share_ratio: float,
     *     excessive_top_share: float,
     *     top_products: int,
     *     gini_at_k: array<int, float|null>,
     *     top_share_at_k: array<int, float|null>,
     *     excessive_at_k: array<int, bool|null>,
     *     most_recommended: list<array{product_id: int, appearances: int}>
     * }
     */
    private function concentration(array $lists, array $candidateIds): array
    {
        $gini = [];
        $topShare = [];
        $excessive = [];
        $counts = [];
        foreach ($this->ks as $k) {
            // Todo candidato entra, mesmo com 0 aparições; id fora do treino não conta.
            $counts = array_fill_keys($candidateIds, 0);
            foreach ($lists as $list) {
                foreach (array_slice($list['ids'], 0, $k) as $id) {
                    if (isset($counts[$id])) {
                        $counts[$id]++;
                    }
                }
            }

            $values = array_values($counts);
            $giniValue = CatalogMetrics::gini($values);
            $share = CatalogMetrics::topShare($values, self::TOP_SHARE_RATIO);
            $gini[$k] = $giniValue === null ? null : round($giniValue, self::DECIMALS);
            $topShare[$k] = $share === null ? null : round($share, self::DECIMALS);
            $excessive[$k] = $share === null ? null : self::isExcessive($share);
        }

        // $counts ficou com o maior k (ks está em ordem crescente).
        $appearing = array_filter($counts, static fn (int $count): bool => $count > 0);
        $ranked = [];
        foreach ($appearing as $id => $count) {
            $ranked[] = ['product_id' => (int) $id, 'appearances' => $count];
        }
        usort(
            $ranked,
            static fn (array $a, array $b): int => [$b['appearances'], $a['product_id']] <=> [$a['appearances'], $b['product_id']]
        );

        return [
            'top_share_ratio' => self::TOP_SHARE_RATIO,
            'excessive_top_share' => self::EXCESSIVE_TOP_SHARE,
            'top_products' => CatalogMetrics::topGroupSize(count(array_unique($candidateIds)), self::TOP_SHARE_RATIO),
            'gini_at_k' => $gini,
            'top_share_at_k' => $topShare,
            'excessive_at_k' => $excessive,
            'most_recommended' => array_slice($ranked, 0, self::MOST_RECOMMENDED_LIMIT),
        ];
    }

    /**
     * The signal compares the PUBLISHED (rounded) top share with the threshold,
     * so the report never shows 0.5000 marked as "ok".
     */
    public static function isExcessive(float $topShare): bool
    {
        return round($topShare, self::DECIMALS) >= self::EXCESSIVE_TOP_SHARE;
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
