<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation\Evaluation;

use App\Application\Recommendation\Evaluation\ColdStartEvaluation;
use App\Application\Recommendation\Evaluation\OfflineEvaluation;
use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Evaluation\HoldoutSplit;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tests\Support\InMemoryProductRepository;
use UnexpectedValueException;

final class ColdStartEvaluationTest extends TestCase
{
    private const POPULATION_KEYS = ['queries', 'metrics', 'coverage', 'diversity', 'concentration'];

    public function test_complete_strategy_never_activates_and_ml_population_matches_offline_evaluation(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 10, 'A'), array_fill(0, 10, 'B')));
        // Mesma categoria primeiro, depois o resto: sempre 16 itens para limite 10.
        $factory = self::strategyFactory(static fn (Product $q, array $train): array => self::sameCategoryFirst($q, $train));

        $result = $this->coldStart($factory)->run($catalog, 42);
        $offline = (new OfflineEvaluation($factory))->run($catalog, 42);

        $this->assertSame('new_product', $result['scenario']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame('hybrid', $result['fallback_strategy']);
        $this->assertSame(
            ['queries' => 4, 'activated' => 0, 'activation_rate' => 0.0, 'items' => 40, 'fallback_items' => 0,
                'fallback_item_share' => 0.0],
            $result['activation']
        );
        $this->assertSame('served_ml_items', $result['populations']['ml']['selection']);
        $this->assertSame('forced_fallback', $result['populations']['fallback']['selection']);
        foreach (self::POPULATION_KEYS as $key) {
            $this->assertSame($offline[$key], $result['populations']['ml'][$key], $key);
        }
    }

    public function test_short_strategy_list_is_completed_by_fallback_and_ml_population_keeps_only_ml_items(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 10, 'A'), array_fill(0, 10, 'B')));
        $factory = self::strategyFactory(
            static fn (Product $q, array $train): array => array_slice(self::sameCategoryFirst($q, $train), 0, 2)
        );

        $result = $this->coldStart($factory)->run($catalog, 42);
        $offline = (new OfflineEvaluation($factory))->run($catalog, 42);

        $activation = $result['activation'];
        $this->assertSame(4, $activation['queries']);
        $this->assertSame(4, $activation['activated']);
        $this->assertSame(1.0, $activation['activation_rate']);
        $this->assertGreaterThan(0, $activation['fallback_items']);
        // 2 itens de ML por consulta; o resto da lista servida é fallback.
        $this->assertSame($activation['items'] - 8, $activation['fallback_items']);
        $this->assertSame(round($activation['fallback_items'] / $activation['items'], 4), $activation['fallback_item_share']);
        foreach (self::POPULATION_KEYS as $key) {
            $this->assertSame($offline[$key], $result['populations']['ml'][$key], $key);
        }
        // Só os 2 itens de ML (ambos relevantes) entram: precision@10 = 2/10.
        $this->assertSame(4, $result['populations']['ml']['coverage']['lists']);
        $this->assertSame([1 => 1.0, 5 => 0.4, 10 => 0.2], $result['populations']['ml']['metrics']['precision_at_k']);
    }

    public function test_empty_strategy_activates_every_query_and_ml_population_is_empty(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 10, 'A'), array_fill(0, 10, 'B')));
        $factory = self::strategyFactory(static fn (Product $q, array $train): array => []);

        $result = $this->coldStart($factory)->run($catalog, 42);

        $this->assertSame(4, $result['activation']['activated']);
        $this->assertSame(1.0, $result['activation']['activation_rate']);
        $this->assertSame(1.0, $result['activation']['fallback_item_share']);

        $ml = $result['populations']['ml'];
        $this->assertSame(['evaluated' => 0, 'without_relevant' => 0], $ml['queries']);
        $this->assertSame([1 => null, 5 => null, 10 => null], $ml['metrics']['precision_at_k']);
        $this->assertSame([1 => null, 5 => null, 10 => null], $ml['metrics']['recall_at_k']);
        $this->assertSame(0, $ml['coverage']['lists']);
        $this->assertSame([1 => 0.0, 5 => 0.0, 10 => 0.0], $ml['coverage']['catalog_coverage_at_k']);
        $this->assertSame([1 => null, 5 => null, 10 => null], $ml['concentration']['excessive_at_k']);

        $fallback = $result['populations']['fallback'];
        $this->assertSame(4, $fallback['coverage']['lists']);
        $this->assertSame(4, $fallback['queries']['evaluated']);
        // O híbrido abre com a mesma categoria: o top-1 é sempre relevante.
        $this->assertSame(1.0, $fallback['metrics']['precision_at_k'][1]);
        $this->assertGreaterThan(0.0, $fallback['coverage']['catalog_coverage_at_k'][10]);
    }

    public function test_same_seed_gives_the_same_result(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 12, 'A'), array_fill(0, 12, 'B'), array_fill(0, 6, 'C')));
        $factory = self::strategyFactory(
            static fn (Product $q, array $train): array => array_slice(self::sameCategoryFirst($q, $train), 0, 3)
        );

        $first = $this->coldStart($factory)->run($catalog, 42);
        mt_rand();
        shuffle($catalog);
        $catalog = array_values($catalog);
        $second = $this->coldStart($factory)->run($catalog, 42);

        $this->assertSame($first, $second);
    }

    public function test_forced_fallback_never_returns_the_query_and_only_train_ids(): void
    {
        // Treino = 1..8 (todos A), holdout = 9 e 10 (B). Com popularity_only e limite 10, o findAll devolve
        // treino + consulta; se a consulta vazasse, a lista teria a categoria B.
        $catalog = $this->catalog(array_merge(array_fill(0, 8, 'A'), ['B', 'B']));
        $seed = $this->seedWithHoldout($catalog, [9, 10]);
        $split = HoldoutSplit::fromProducts($catalog, $seed);
        $factory = self::strategyFactory(static fn (Product $q, array $train): array => array_slice($train, 0, 10));
        $catalogs = [];
        $repositories = static function (array $products) use (&$catalogs): InMemoryProductRepository {
            $catalogs[] = array_map(static fn (Product $p): int => (int) $p->getId(), $products);

            return self::repository($products);
        };
        $settings = RecommendationSettings::fromArray(['fallback' => ['strategy' => 'popularity_only']]);

        $result = (new ColdStartEvaluation($factory, $repositories, [1, 5, 10], 0.2, $settings))->run($catalog, $seed);

        // Cada consulta vê o treino na ordem do split, com ela mesma no fim.
        $trainIds = array_map(static fn (Product $p): int => (int) $p->getId(), $split->train());
        $expectedCatalogs = array_map(
            static fn (Product $q): array => [...$trainIds, (int) $q->getId()],
            $split->holdout()
        );
        $this->assertSame($expectedCatalogs, $catalogs);

        $this->assertSame('popularity_only', $result['fallback_strategy']);
        $fallback = $result['populations']['fallback'];
        $this->assertSame(2, $fallback['coverage']['lists']);
        // Todo o treino e nada além dele: 8 itens A por lista, cada um 2 vezes.
        $this->assertSame(8, $fallback['coverage']['covered_products_at_k'][10]);
        $this->assertSame(1.0, $fallback['coverage']['catalog_coverage_at_k'][10]);
        $this->assertSame([1 => 1.0, 5 => 1.0, 10 => 1.0], $fallback['diversity']['distinct_categories_at_k']);
        $this->assertSame(0.0, $fallback['diversity']['intra_list_diversity_at_k'][10]);
        $this->assertSame(0.0, $fallback['concentration']['gini_at_k'][10]);
        // As consultas são B e o treino só tem A: sem relevante, fora de precision/recall.
        $this->assertSame(['evaluated' => 0, 'without_relevant' => 2], $fallback['queries']);
    }

    public function test_strategy_is_trained_once_with_the_train_split(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 10, 'A'), array_fill(0, 10, 'B')));
        $strategies = [];
        $factory = static function (array $train) use (&$strategies): ColdStartFakeStrategy {
            $strategies[] = $strategy = new ColdStartFakeStrategy(
                static fn (Product $q, array $trained): array => array_slice($trained, 0, 10)
            );

            return $strategy;
        };

        $this->coldStart($factory)->run($catalog, 42);

        $this->assertCount(1, $strategies);
        $this->assertSame(1, $strategies[0]->trainCalls);
        $this->assertSame(HoldoutSplit::fromProducts($catalog, 42)->train(), $strategies[0]->trainedWith);
        // Duas execuções (servida e forçada) por consulta, mas só a servida chama a estratégia.
        $this->assertSame([10, 10, 10, 10], $strategies[0]->limits);
    }

    public function test_forced_population_does_not_depend_on_the_served_run_consuming_randomness(): void
    {
        // Com popularity_only, uma execução servida que ativa o fallback consome shuffle(); a que não
        // ativa, não. O contrafactual forçado tem de sair igual nos dois casos.
        $catalog = $this->catalog(array_merge(array_fill(0, 20, 'A'), array_fill(0, 20, 'B')));
        $settings = RecommendationSettings::fromArray(['fallback' => ['strategy' => 'popularity_only']]);
        $repositories = static fn (array $products) => self::repository($products);
        $complete = self::strategyFactory(static fn (Product $q, array $train): array => self::sameCategoryFirst($q, $train));
        $empty = self::strategyFactory(static fn (Product $q, array $train): array => []);

        $withoutActivation = (new ColdStartEvaluation($complete, $repositories, [1, 5, 10], 0.2, $settings))->run($catalog, 7);
        $withActivation = (new ColdStartEvaluation($empty, $repositories, [1, 5, 10], 0.2, $settings))->run($catalog, 7);

        $this->assertSame(0, $withoutActivation['activation']['activated']);
        $this->assertSame($withActivation['activation']['queries'], $withActivation['activation']['activated']);
        $this->assertSame($withoutActivation['populations']['fallback'], $withActivation['populations']['fallback']);
    }

    public function test_strategy_still_untrained_after_train_is_refused(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 10, 'A'), array_fill(0, 10, 'B')));
        $factory = static fn (array $train): ColdStartFakeStrategy => new ColdStartFakeStrategy(
            static fn (Product $q, array $trained): array => $trained,
            stayUntrained: true
        );

        // Sem a guarda, o caso de uso retreinaria com treino + q e vazaria o holdout.
        $this->expectException(LogicException::class);

        $this->coldStart($factory)->run($catalog, 42);
    }

    public function test_malformed_item_is_rejected_instead_of_becoming_id_zero(): void
    {
        $item = new ReflectionMethod(ColdStartEvaluation::class, 'item');

        $this->assertSame(['product_id' => 3, 'category' => 'A'], $item->invoke(null, ['product_id' => 3, 'category' => 'A']));
        foreach ([['category' => 'A'], ['product_id' => '3', 'category' => 'A'], ['product_id' => 3]] as $malformed) {
            try {
                $item->invoke(null, $malformed);
                $this->fail('esperada UnexpectedValueException para ' . json_encode($malformed));
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function coldStart(Closure $factory): ColdStartEvaluation
    {
        return new ColdStartEvaluation($factory, static fn (array $products) => self::repository($products));
    }

    /** @param list<Product> $products */
    private static function repository(array $products): InMemoryProductRepository
    {
        return new InMemoryProductRepository(array_map(static fn (Product $p): array => $p->toArray(), $products));
    }

    /**
     * @param Closure(Product, list<Product>): list<Product> $pick
     * @return Closure(list<Product>): ColdStartFakeStrategy
     */
    private static function strategyFactory(Closure $pick): Closure
    {
        return static fn (array $train): ColdStartFakeStrategy => new ColdStartFakeStrategy($pick);
    }

    /**
     * @param list<Product> $train
     * @return list<Product>
     */
    private static function sameCategoryFirst(Product $query, array $train): array
    {
        $same = array_filter($train, static fn (Product $p): bool => $p->getCategory() === $query->getCategory());
        $other = array_filter($train, static fn (Product $p): bool => $p->getCategory() !== $query->getCategory());

        return array_values([...$same, ...$other]);
    }

    /**
     * @param list<Product> $catalog
     * @param list<int> $holdoutIds sorted
     */
    private function seedWithHoldout(array $catalog, array $holdoutIds): int
    {
        for ($seed = 1; $seed < 10000; $seed++) {
            $ids = array_map(static fn (Product $p): int => (int) $p->getId(), HoldoutSplit::fromProducts($catalog, $seed)->holdout());
            sort($ids);
            if ($ids === $holdoutIds) {
                return $seed;
            }
        }

        $this->fail('nenhuma seed produz o holdout pedido');
    }

    /**
     * @param list<string> $categories one product per entry, ids from 1
     * @return list<Product>
     */
    private function catalog(array $categories): array
    {
        $products = [];
        foreach ($categories as $index => $category) {
            $products[] = Product::fromArray([
                'id' => $index + 1,
                'name' => 'Produto ' . ($index + 1),
                'price' => 10 + $index,
                'category' => $category,
            ]);
        }

        return $products;
    }
}

/**
 * Estratégia fake: devolve os produtos que $pick escolher, na ordem dada.
 */
final class ColdStartFakeStrategy implements RecommendationStrategy
{
    /** @var list<Product> */
    public array $trainedWith = [];
    public int $trainCalls = 0;
    /** @var list<int> */
    public array $limits = [];

    /**
     * @param Closure(Product, list<Product>): list<Product> $pick
     */
    public function __construct(private readonly Closure $pick, private readonly bool $stayUntrained = false)
    {
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function isTrained(): bool
    {
        return ! $this->stayUntrained && $this->trainCalls > 0;
    }

    public function train(array $products): void
    {
        $this->trainCalls++;
        $this->trainedWith = array_values($products);
    }

    public function recommend(Product $target, int $limit): array
    {
        $this->limits[] = $limit;
        $picked = array_slice(($this->pick)($target, $this->trainedWith), 0, $limit);

        return array_map(
            static fn (Product $p, int $i) => new RecommendationResult(
                (int) $p->getId(),
                $p->getName(),
                $p->getCategory(),
                $p->getPrice()->getDecimal(),
                50.0,
                $i + 1,
                'fake'
            ),
            $picked,
            array_keys($picked)
        );
    }
}
