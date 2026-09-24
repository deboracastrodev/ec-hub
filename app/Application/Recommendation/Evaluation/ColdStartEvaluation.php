<?php

declare(strict_types=1);

namespace App\Application\Recommendation\Evaluation;

use App\Application\Recommendation\GenerateRecommendations;
use App\Domain\Product\Model\Product;
use App\Domain\Product\Repository\ProductRepositoryInterface;
use App\Domain\Recommendation\Evaluation\HoldoutSplit;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Domain\Recommendation\Service\RuleBasedFallback;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use Closure;
use LogicException;
use Psr\Log\NullLogger;
use UnexpectedValueException;

/**
 * Story 10.4: cold-start measured through the REAL use case.
 *
 * Scenario 'new_product': for each holdout query q the repository holds the
 * train split (in split order) plus q appended at the end -- q is in the
 * catalog, as in production, but the model was trained with the train split
 * only (a product the batch-trained index has not seen yet). The strategy is
 * trained once and shared; since it arrives trained, the use case never
 * retrains it.
 *
 * Each query runs GenerateRecommendations twice, without session or user
 * (no personalization):
 *  - served: execute(q, max(k)) -- gives the fallback activation rate and,
 *    filtered to source = ml, the ML population (queries with no ML item are
 *    left out of it);
 *  - forced: execute(q, max(k), insufficientData: true) -- the fallback
 *    population, counterfactual by construction: what the fallback would
 *    serve if it were activated, not real traffic.
 *
 * Both populations are aggregated by OfflineEvaluation::measure(), so the
 * formulas are exactly the Story 10.2/10.3 ones. The popularity fallback
 * uses shuffle(), so mt_srand($seed) runs before the served executions and
 * mt_srand($seed + query id) right before every forced one: the same seed
 * gives the same result, and the forced (counterfactual) population depends only on seed + catalog,
 * never on how much randomness the served run consumed. Both runs ask for
 * limit = max(ks), and the activation depends on that limit (it is reported).
 */
final class ColdStartEvaluation
{
    public const SCENARIO = 'new_product';
    public const SELECTION_ML = 'served_ml_items';
    public const SELECTION_FALLBACK = 'forced_fallback';
    private const DECIMALS = 4;

    private readonly OfflineEvaluation $aggregation;
    private readonly RecommendationSettings $settings;

    /**
     * @param Closure(list<Product>): RecommendationStrategy $strategyFactory a fresh, untrained
     *        strategy; receives the train split only
     * @param Closure(list<Product>): ProductRepositoryInterface $repositoryFactory the catalog one
     *        query sees (train + q), in the given order
     * @param array<mixed> $ks positive integers
     */
    public function __construct(
        private readonly Closure $strategyFactory,
        private readonly Closure $repositoryFactory,
        array $ks = [1, 5, 10],
        private readonly float $holdoutRatio = 0.2,
        ?RecommendationSettings $settings = null,
    ) {
        // Padrão independente de env: fallback 'hybrid', como config/recommendation.php sem variáveis.
        $this->settings = $settings ?? RecommendationSettings::fromArray([]);
        // Mesma validação de ks e mesma agregação da avaliação offline.
        $this->aggregation = new OfflineEvaluation($strategyFactory, $ks, $holdoutRatio);
    }

    /**
     * @param list<Product> $products
     * @return array{
     *     scenario: string,
     *     fallback_strategy: string,
     *     limit: int,
     *     activation: array{
     *         queries: int,
     *         activated: int,
     *         activation_rate: float|null,
     *         items: int,
     *         fallback_items: int,
     *         fallback_item_share: float|null
     *     },
     *     populations: array{ml: array<string, mixed>, fallback: array<string, mixed>}
     * }
     */
    public function run(array $products, int $seed): array
    {
        $split = HoldoutSplit::fromProducts($products, $seed, $this->holdoutRatio);

        $strategy = ($this->strategyFactory)($split->train());
        $strategy->train($split->train());
        if (! $strategy->isTrained()) {
            // O caso de uso retreinaria com o catálogo da consulta (treino + q) e vazaria o holdout.
            throw new LogicException(
                'A estratégia continua sem treino após train(): o caso de uso retreinaria com a consulta do holdout.'
            );
        }

        $limit = max($this->aggregation->ks());
        $queries = 0;
        $activated = 0;
        $items = 0;
        $fallbackItems = 0;
        $mlLists = [];
        $fallbackLists = [];

        // getByPopularity() usa shuffle(): a seed fixa torna o relatório reproduzível.
        mt_srand($seed);

        try {
            foreach ($split->holdout() as $query) {
                $queryId = (int) $query->getId();
                $repository = ($this->repositoryFactory)([...$split->train(), $query]);
                $logger = new NullLogger();
                $useCase = new GenerateRecommendations(
                    $repository,
                    $strategy,
                    new RuleBasedFallback($repository, $logger, $this->settings),
                    $logger,
                    $this->settings
                );

                $served = $useCase->execute($queryId, $limit);
                // Resemeia antes do forçado: se a execução servida consumiu shuffle() (popularidade),
                // o contrafactual não pode herdar esse estado. Seed + id da consulta: depende só da
                // seed e do catálogo, sem repetir a mesma permutação em todas as consultas.
                mt_srand($seed + $queryId);
                $forced = $useCase->execute($queryId, $limit, true);

                $queries++;
                $mlItems = [];
                $servedFallback = 0;
                foreach ($served as $recommendation) {
                    if (($recommendation['source'] ?? null) === 'ml') {
                        $mlItems[] = self::item($recommendation);
                    } else {
                        $servedFallback++;
                    }
                }
                $items += count($served);
                $fallbackItems += $servedFallback;
                if ($servedFallback > 0) {
                    $activated++;
                }
                if ($mlItems !== []) {
                    $mlLists[$queryId] = $mlItems;
                }

                $fallbackLists[$queryId] = array_map(self::item(...), $forced);
            }
        } finally {
            // Devolve o gerador global a um estado não determinístico.
            mt_srand();
        }

        return [
            'scenario' => self::SCENARIO,
            'fallback_strategy' => $this->settings->getFallbackStrategy(),
            'limit' => $limit,
            'activation' => [
                'queries' => $queries,
                'activated' => $activated,
                'activation_rate' => $queries > 0 ? round($activated / $queries, self::DECIMALS) : null,
                'items' => $items,
                'fallback_items' => $fallbackItems,
                'fallback_item_share' => $items > 0 ? round($fallbackItems / $items, self::DECIMALS) : null,
            ],
            'populations' => [
                'ml' => ['selection' => self::SELECTION_ML] + $this->aggregation->measure($split, $mlLists),
                'fallback' => ['selection' => self::SELECTION_FALLBACK] + $this->aggregation->measure($split, $fallbackLists),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $recommendation
     * @return array{product_id: int, category: string}
     */
    private static function item(array $recommendation): array
    {
        $productId = $recommendation['product_id'] ?? null;
        $category = $recommendation['category'] ?? null;
        if (! is_int($productId) || ! is_string($category)) {
            throw new UnexpectedValueException(
                'Recomendação sem product_id inteiro ou sem category: não é possível avaliá-la.'
            );
        }

        return ['product_id' => $productId, 'category' => $category];
    }
}
