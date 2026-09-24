<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation\Evaluation;

use App\Application\Recommendation\Evaluation\OfflineEvaluation;
use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Evaluation\HoldoutSplit;
use App\Domain\Recommendation\Model\RecommendationResult;
use App\Domain\Recommendation\Service\RecommendationStrategy;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfflineEvaluationTest extends TestCase
{
    public function test_hand_computed_averages_with_short_list(): void
    {
        // 5 produtos da mesma categoria, holdout 40% → 2 consultas, 3 no treino,
        // e todo o treino é relevante. A estratégia devolve só 1 item (relevante).
        $strategy = new FakeStrategy(static fn (Product $q, array $train): array => [$train[0]]);
        $evaluation = new OfflineEvaluation(static fn () => $strategy, [2, 1], 0.4);

        $result = $evaluation->run($this->catalog(array_fill(0, 5, 'X')), 42);

        $this->assertSame('fake', $result['algorithm']);
        $this->assertSame(['evaluated' => 2, 'without_relevant' => 0], $result['queries']);
        $this->assertSame([1 => 1.0, 2 => 0.5], $result['metrics']['precision_at_k']);
        $this->assertSame([1 => 0.3333, 2 => 0.3333], $result['metrics']['recall_at_k']);
        $this->assertSame('same_category', $result['relevance']);
        $this->assertSame(
            ['seed' => 42, 'holdout_ratio' => 0.4, 'train_size' => 3, 'holdout_size' => 2],
            array_diff_key($result['split'], ['holdout_ids' => true])
        );
    }

    public function test_exact_macro_averages_and_query_without_relevant_is_excluded(): void
    {
        // Categoria 'Única' tem um só produto: quando ele cai no holdout, não há relevante no treino.
        $categories = array_merge(array_fill(0, 10, 'A'), array_fill(0, 9, 'B'), ['Única']);
        $catalog = $this->catalog($categories);
        $seed = $this->seedWithProductInHoldout($catalog, 20);
        $split = HoldoutSplit::fromProducts($catalog, $seed);

        // Intercala: relevante, outra categoria, relevante, outra...
        $strategy = new FakeStrategy(static function (Product $q, array $train): array {
            $same = array_values(array_filter($train, static fn (Product $p) => $p->getCategory() === $q->getCategory()));
            $other = array_values(array_filter($train, static fn (Product $p) => $p->getCategory() !== $q->getCategory()));
            $list = [];
            for ($i = 0; $i < 10; $i++) {
                $list[] = $same[$i] ?? null;
                $list[] = $other[$i] ?? null;
            }

            return array_values(array_filter($list));
        });
        $evaluation = new OfflineEvaluation(static fn () => $strategy, [1, 2, 4]);

        $result = $evaluation->run($catalog, $seed);

        $expectedPrecision = [1 => 0.0, 2 => 0.0, 4 => 0.0];
        $expectedRecall = [1 => 0.0, 2 => 0.0, 4 => 0.0];
        $evaluated = 0;
        foreach ($split->holdout() as $query) {
            if ($query->getCategory() === 'Única') {
                continue;
            }
            $relevant = count(array_filter(
                $split->train(),
                static fn (Product $p) => $p->getCategory() === $query->getCategory()
            ));
            // hits: top-1 = 1, top-2 = 1, top-4 = 2 (há >= 2 relevantes em A e B no treino)
            $expectedPrecision[1] += 1.0;
            $expectedPrecision[2] += 0.5;
            $expectedPrecision[4] += 0.5;
            $expectedRecall[1] += 1 / $relevant;
            $expectedRecall[2] += 1 / $relevant;
            $expectedRecall[4] += 2 / $relevant;
            $evaluated++;
        }
        $expectedPrecision = array_map(static fn (float $v) => round($v / $evaluated, 4), $expectedPrecision);
        $expectedRecall = array_map(static fn (float $v) => round($v / $evaluated, 4), $expectedRecall);

        $this->assertSame(['evaluated' => 3, 'without_relevant' => 1], $result['queries']);
        $this->assertSame($expectedPrecision, $result['metrics']['precision_at_k']);
        $this->assertSame($expectedRecall, $result['metrics']['recall_at_k']);
    }

    public function test_trains_only_on_train_and_asks_for_max_k(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 10, 'A'), array_fill(0, 10, 'B')));
        $strategy = new FakeStrategy(static fn (Product $q, array $train): array => []);
        $factoryCalls = [];
        $factory = static function (array $train) use ($strategy, &$factoryCalls): FakeStrategy {
            $factoryCalls[] = $train;

            return $strategy;
        };
        $evaluation = new OfflineEvaluation($factory, [5, 1, 10]);

        $result = $evaluation->run($catalog, 42);

        $split = HoldoutSplit::fromProducts($catalog, 42);
        $trainIds = array_map(static fn (Product $p) => $p->getId(), $split->train());
        $trainedIds = array_map(static fn (Product $p) => $p->getId(), $strategy->trainedWith);
        sort($trainIds);
        sort($trainedIds);

        // A fábrica recebe exatamente o treino: o holdout nunca fica visível à estratégia.
        $this->assertCount(1, $factoryCalls);
        $this->assertSame($split->train(), $factoryCalls[0]);
        $this->assertSame(1, $strategy->trainCalls);
        $this->assertSame($trainIds, $trainedIds);
        $this->assertSame([], array_intersect($trainedIds, $result['split']['holdout_ids']));
        $this->assertSame([10, 10, 10, 10], $strategy->limits);
        $this->assertSame([1, 5, 10], array_keys($result['metrics']['precision_at_k']));
        $this->assertSame([0.0, 0.0, 0.0], array_values($result['metrics']['precision_at_k']));
    }

    public function test_holdout_ids_are_sorted_and_reproducible(): void
    {
        $catalog = $this->catalog(array_fill(0, 30, 'A'));
        $factory = static fn () => new FakeStrategy(static fn (Product $q, array $train): array => []);

        $first = (new OfflineEvaluation($factory))->run($catalog, 42);
        $second = (new OfflineEvaluation($factory))->run($catalog, 42);
        $other = (new OfflineEvaluation($factory))->run($catalog, 7);

        $ids = $first['split']['holdout_ids'];
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertSame($first, $second);
        $this->assertNotSame($ids, $other['split']['holdout_ids']);
    }

    public function test_metrics_are_null_when_no_query_has_relevant(): void
    {
        $catalog = $this->catalog(['A', 'B', 'C', 'D', 'E']);
        $strategy = new FakeStrategy(static fn (Product $q, array $train): array => []);

        $result = (new OfflineEvaluation(static fn () => $strategy, [1]))->run($catalog, 1);

        $this->assertSame(['evaluated' => 0, 'without_relevant' => 1], $result['queries']);
        $this->assertSame([1 => null], $result['metrics']['precision_at_k']);
    }

    public function test_query_without_relevant_still_counts_in_coverage_and_lists(): void
    {
        // Cinco categorias únicas: a única consulta do holdout não tem relevante no treino.
        $catalog = $this->catalog(['A', 'B', 'C', 'D', 'E']);
        $strategy = new FakeStrategy(static fn (Product $q, array $train): array => $train);

        $result = (new OfflineEvaluation(static fn () => $strategy, [1, 10]))->run($catalog, 1);

        $this->assertSame(['evaluated' => 0, 'without_relevant' => 1], $result['queries']);
        $this->assertSame([1 => null, 10 => null], $result['metrics']['precision_at_k']);
        $this->assertSame([10], $strategy->limits);
        $this->assertSame(
            [
                'candidate_products' => 4,
                'lists' => 1,
                'covered_products_at_k' => [1 => 1, 10 => 4],
                'catalog_coverage_at_k' => [1 => 0.25, 10 => 1.0],
            ],
            $result['coverage']
        );
        // 4 categorias diferentes na lista de 4 itens
        $this->assertSame([1 => null, 10 => 1.0], $result['diversity']['intra_list_diversity_at_k']);
        $this->assertSame([1 => 1.0, 10 => 4.0], $result['diversity']['distinct_categories_at_k']);
    }

    public function test_empty_lists_follow_the_matrix(): void
    {
        $catalog = $this->catalog(array_merge(array_fill(0, 10, 'A'), array_fill(0, 10, 'B')));
        $strategy = new FakeStrategy(static fn (Product $q, array $train): array => []);

        $result = (new OfflineEvaluation(static fn () => $strategy))->run($catalog, 42);

        $this->assertSame(16, $result['coverage']['candidate_products']);
        $this->assertSame(4, $result['coverage']['lists']);
        $this->assertSame([1 => 0, 5 => 0, 10 => 0], $result['coverage']['covered_products_at_k']);
        $this->assertSame([1 => 0.0, 5 => 0.0, 10 => 0.0], $result['coverage']['catalog_coverage_at_k']);
        $this->assertSame(
            ['distance' => 'category', 'intra_list_diversity_at_k' => [1 => null, 5 => null, 10 => null],
                'distinct_categories_at_k' => [1 => 0.0, 5 => 0.0, 10 => 0.0]],
            $result['diversity']
        );
        $this->assertSame(
            [
                'top_share_ratio' => 0.1,
                'excessive_top_share' => 0.5,
                'top_products' => 2,
                'gini_at_k' => [1 => null, 5 => null, 10 => null],
                'top_share_at_k' => [1 => null, 5 => null, 10 => null],
                'excessive_at_k' => [1 => null, 5 => null, 10 => null],
                'most_recommended' => [],
            ],
            $result['concentration']
        );
    }

    public function test_identical_lists_are_flagged_as_excessive(): void
    {
        $catalog = $this->catalog(array_fill(0, 20, 'A'));
        // Toda consulta recebe o mesmo único produto.
        $strategy = new FakeStrategy(static fn (Product $q, array $train): array => [$train[0]]);

        $result = (new OfflineEvaluation(static fn () => $strategy, [1, 5]))->run($catalog, 42);

        $top = (int) $strategy->trainedWith[0]->getId();
        $this->assertSame([1 => 1, 5 => 1], $result['coverage']['covered_products_at_k']);
        // 16 candidatos, tudo num só: (16 − 1) / 16
        $this->assertSame([1 => 0.9375, 5 => 0.9375], $result['concentration']['gini_at_k']);
        $this->assertSame([1 => 1.0, 5 => 1.0], $result['concentration']['top_share_at_k']);
        $this->assertSame([1 => true, 5 => true], $result['concentration']['excessive_at_k']);
        $this->assertSame([['product_id' => $top, 'appearances' => 4]], $result['concentration']['most_recommended']);
    }

    public function test_exact_catalog_metrics_on_a_small_fixture(): void
    {
        // Treino = 1..8 (A A A B B C C C), holdout = 9 (A) e 10 (B).
        $catalog = $this->catalog(['A', 'A', 'A', 'B', 'B', 'C', 'C', 'C', 'A', 'B']);
        $seed = $this->seedWithHoldout($catalog, [9, 10]);
        $picks = [9 => [1, 2, 4], 10 => [1, 6, 7]];
        $strategy = new FakeStrategy(static function (Product $q, array $train) use ($picks): array {
            $byId = [];
            foreach ($train as $product) {
                $byId[(int) $product->getId()] = $product;
            }

            return array_map(static fn (int $id): Product => $byId[$id], $picks[(int) $q->getId()]);
        });

        $result = (new OfflineEvaluation(static fn () => $strategy, [1, 3]))->run($catalog, $seed);

        // k=1: [1], [1]. k=3: [1,2,4] (A A B) e [1,6,7] (A C C).
        $this->assertSame(
            [
                'candidate_products' => 8,
                'lists' => 2,
                'covered_products_at_k' => [1 => 1, 3 => 5],
                'catalog_coverage_at_k' => [1 => 0.125, 3 => 0.625],
            ],
            $result['coverage']
        );
        // ILD de cada lista em k=3: 2/3
        $this->assertSame([1 => null, 3 => 0.6667], $result['diversity']['intra_list_diversity_at_k']);
        $this->assertSame([1 => 1.0, 3 => 2.0], $result['diversity']['distinct_categories_at_k']);
        // k=1: contagens [0×7, 2] → Gini 7/8, top ceil(0.8)=1 fica com 2/2.
        // k=3: [0,0,0,1,1,1,1,2] → Σ(2i−9)cᵢ = 22, Gini = 22/48; top 1 fica com 2/6.
        $this->assertSame([1 => 0.875, 3 => 0.4583], $result['concentration']['gini_at_k']);
        $this->assertSame([1 => 1.0, 3 => 0.3333], $result['concentration']['top_share_at_k']);
        $this->assertSame([1 => true, 3 => false], $result['concentration']['excessive_at_k']);
        // Maior k; empate de 1 aparição resolvido por id crescente.
        $this->assertSame(
            [
                ['product_id' => 1, 'appearances' => 2],
                ['product_id' => 2, 'appearances' => 1],
                ['product_id' => 4, 'appearances' => 1],
                ['product_id' => 6, 'appearances' => 1],
                ['product_id' => 7, 'appearances' => 1],
            ],
            $result['concentration']['most_recommended']
        );
    }

    public function test_duplicate_id_inside_a_list_counts_once(): void
    {
        // Treino = 1..8 (A A A B B C C C), holdout = 9 e 10; as duas listas repetem o produto 1.
        $catalog = $this->catalog(['A', 'A', 'A', 'B', 'B', 'C', 'C', 'C', 'A', 'B']);
        $seed = $this->seedWithHoldout($catalog, [9, 10]);
        $strategy = new FakeStrategy(static function (Product $q, array $train): array {
            $byId = [];
            foreach ($train as $product) {
                $byId[(int) $product->getId()] = $product;
            }

            return [$byId[1], $byId[1], $byId[4]];
        });

        $result = (new OfflineEvaluation(static fn () => $strategy, [1, 3]))->run($catalog, $seed);

        // Lista bruta [1, 1, 4]; sem a duplicata, o top-3 de cada lista é [1 (A), 4 (B)].
        $this->assertSame([1 => 1, 3 => 2], $result['coverage']['covered_products_at_k']);
        $this->assertSame([1 => null, 3 => 1.0], $result['diversity']['intra_list_diversity_at_k']);
        $this->assertSame([1 => 1.0, 3 => 2.0], $result['diversity']['distinct_categories_at_k']);
        // k=3: produtos 1 e 4 com 2 aparições cada (não 4 e 2); top 1 (ceil(0.8)) fica com 2/4.
        $this->assertSame([1 => 1.0, 3 => 0.5], $result['concentration']['top_share_at_k']);
        $this->assertSame(
            [['product_id' => 1, 'appearances' => 2], ['product_id' => 4, 'appearances' => 2]],
            $result['concentration']['most_recommended']
        );
        // precision continua com a lista bruta: consulta 9 (A) acerta 1 em k=1 e k=3; consulta 10 (B)
        // erra em k=1 e acerta o 4 em k=3.
        $this->assertSame([1 => 0.5, 3 => 0.3333], $result['metrics']['precision_at_k']);
    }

    public function test_excessive_signal_uses_the_published_rounded_share(): void
    {
        // 0.49996 é publicado como 0.5000: o sinal precisa concordar com o número mostrado.
        $this->assertTrue(OfflineEvaluation::isExcessive(0.49996));
        $this->assertTrue(OfflineEvaluation::isExcessive(0.5));
        $this->assertFalse(OfflineEvaluation::isExcessive(0.49994));
        $this->assertFalse(OfflineEvaluation::isExcessive(0.3));
    }

    public function test_ids_outside_train_do_not_count_in_coverage_or_concentration(): void
    {
        $catalog = $this->catalog(array_fill(0, 10, 'A'));
        $ghost = Product::fromArray(['id' => 999, 'name' => 'Fantasma', 'price' => 1, 'category' => 'Z']);
        $strategy = new FakeStrategy(static fn (Product $q, array $train): array => [$ghost, $train[0]]);

        $result = (new OfflineEvaluation(static fn () => $strategy, [1, 2]))->run($catalog, 42);

        $this->assertSame([1 => 0, 2 => 1], $result['coverage']['covered_products_at_k']);
        $this->assertSame([1 => null, 2 => 1.0], $result['concentration']['top_share_at_k']);
        $this->assertSame((int) $strategy->trainedWith[0]->getId(), $result['concentration']['most_recommended'][0]['product_id']);
        $this->assertCount(1, $result['concentration']['most_recommended']);
        // A categoria do fantasma entra na diversidade: ela é da lista, não do catálogo.
        $this->assertSame([1 => null, 2 => 1.0], $result['diversity']['intra_list_diversity_at_k']);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidKs(): iterable
    {
        yield 'zero' => [[0, 5]];
        yield 'negativo' => [[-1]];
        yield 'float' => [[1, 2.5]];
        yield 'string' => [['5']];
        yield 'vazio' => [[]];
    }

    /**
     * @param array<mixed> $ks
     */
    #[DataProvider('invalidKs')]
    public function test_rejects_invalid_ks(array $ks): void
    {
        $this->expectException(InvalidArgumentException::class);

        new OfflineEvaluation(static fn () => new FakeStrategy(static fn () => []), $ks);
    }

    /**
     * @param list<Product> $catalog
     */
    private function seedWithProductInHoldout(array $catalog, int $productId): int
    {
        for ($seed = 1; $seed < 1000; $seed++) {
            foreach (HoldoutSplit::fromProducts($catalog, $seed)->holdout() as $product) {
                if ($product->getId() === $productId) {
                    return $seed;
                }
            }
        }

        $this->fail('nenhuma seed coloca o produto no holdout');
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
final class FakeStrategy implements RecommendationStrategy
{
    /** @var list<Product> */
    public array $trainedWith = [];
    public int $trainCalls = 0;
    /** @var list<int> */
    public array $limits = [];

    /**
     * @param Closure(Product, list<Product>): list<Product> $pick
     */
    public function __construct(private readonly Closure $pick)
    {
    }

    public function getName(): string
    {
        return 'fake';
    }

    public function isTrained(): bool
    {
        return $this->trainCalls > 0;
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
