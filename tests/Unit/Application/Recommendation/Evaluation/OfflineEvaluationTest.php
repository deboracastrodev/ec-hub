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
