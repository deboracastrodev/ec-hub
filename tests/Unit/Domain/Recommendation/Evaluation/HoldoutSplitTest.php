<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Evaluation;

use App\Domain\Product\Model\Product;
use App\Domain\Recommendation\Evaluation\HoldoutSplit;
use App\Domain\Shared\ValueObject\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HoldoutSplitTest extends TestCase
{
    public function test_same_seed_gives_the_same_split(): void
    {
        $first = HoldoutSplit::fromProducts($this->catalog(50), 42);
        $second = HoldoutSplit::fromProducts($this->catalog(50), 42);

        $this->assertSame($this->ids($first->holdout()), $this->ids($second->holdout()));
        $this->assertSame($this->ids($first->train()), $this->ids($second->train()));
    }

    public function test_input_order_does_not_change_the_split(): void
    {
        $catalog = $this->catalog(50);
        $reversed = array_reverse($catalog);

        $this->assertSame(
            $this->ids(HoldoutSplit::fromProducts($catalog, 42)->holdout()),
            $this->ids(HoldoutSplit::fromProducts($reversed, 42)->holdout())
        );
    }

    public function test_different_seed_changes_the_holdout(): void
    {
        $a = $this->sortedIds(HoldoutSplit::fromProducts($this->catalog(50), 42)->holdout());
        $b = $this->sortedIds(HoldoutSplit::fromProducts($this->catalog(50), 7)->holdout());

        $this->assertNotSame($a, $b);
    }

    public function test_split_is_pinned_for_seed_42(): void
    {
        // Mt19937 é portável: mudar isto muda o relatório versionado.
        $split = HoldoutSplit::fromProducts($this->catalog(10), 42);

        $this->assertSame([2, 4], $this->ids($split->holdout()));
    }

    /**
     * @return iterable<string, array{int, float, int}>
     */
    public static function sizes(): iterable
    {
        yield '80 × 0.2' => [80, 0.2, 16];
        yield '10 × 0.2' => [10, 0.2, 2];
        yield '3 × 0.2 arredonda para 1' => [3, 0.2, 1];
        yield '4 × 0.1 garante pelo menos 1' => [4, 0.1, 1];
        yield '7 × 0.5 arredonda para cima' => [7, 0.5, 4];
    }

    #[DataProvider('sizes')]
    public function test_holdout_size_is_max_one_round_n_times_ratio(int $n, float $ratio, int $expectedHoldout): void
    {
        $split = HoldoutSplit::fromProducts($this->catalog($n), 1, $ratio);

        $this->assertCount($expectedHoldout, $split->holdout());
        $this->assertCount($n - $expectedHoldout, $split->train());
    }

    public function test_train_and_holdout_partition_the_catalog(): void
    {
        $split = HoldoutSplit::fromProducts($this->catalog(37), 3);
        $train = $this->ids($split->train());
        $holdout = $this->ids($split->holdout());

        $this->assertSame([], array_intersect($train, $holdout));
        $all = array_merge($train, $holdout);
        sort($all);
        $this->assertSame(range(1, 37), $all);
        $this->assertSame(3, $split->seed());
        $this->assertSame(0.2, $split->holdoutRatio());
    }

    public function test_rejects_catalog_with_fewer_than_three_products(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HoldoutSplit::fromProducts($this->catalog(2), 42);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidRatios(): iterable
    {
        yield 'zero' => [0.0];
        yield 'um' => [1.0];
        yield 'negativo' => [-0.1];
        yield 'maior que um' => [1.5];
    }

    #[DataProvider('invalidRatios')]
    public function test_rejects_ratio_outside_open_interval(float $ratio): void
    {
        $this->expectException(InvalidArgumentException::class);

        HoldoutSplit::fromProducts($this->catalog(10), 42, $ratio);
    }

    public function test_rejects_split_that_leaves_fewer_than_two_in_train(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('treino');

        HoldoutSplit::fromProducts($this->catalog(3), 42, 0.6);
    }

    public function test_rejects_product_without_id(): void
    {
        $catalog = $this->catalog(3);
        $catalog[] = new Product('Sem id', '', Money::fromDecimal(10.0), 'Casa');

        $this->expectException(InvalidArgumentException::class);

        HoldoutSplit::fromProducts($catalog, 42);
    }

    public function test_rejects_duplicate_ids(): void
    {
        $catalog = $this->catalog(3);
        $catalog[] = $catalog[0];

        $this->expectException(InvalidArgumentException::class);

        HoldoutSplit::fromProducts($catalog, 42);
    }

    /**
     * @return list<Product>
     */
    private function catalog(int $n): array
    {
        $products = [];
        for ($id = 1; $id <= $n; $id++) {
            $products[] = Product::fromArray([
                'id' => $id,
                'name' => "Produto {$id}",
                'price' => 10 + $id,
                'category' => $id % 2 === 0 ? 'Casa' : 'Livros',
            ]);
        }

        return $products;
    }

    /**
     * @param list<Product> $products
     * @return list<int>
     */
    private function ids(array $products): array
    {
        return array_map(static fn (Product $p): int => (int) $p->getId(), $products);
    }

    /**
     * @param list<Product> $products
     * @return list<int>
     */
    private function sortedIds(array $products): array
    {
        $ids = $this->ids($products);
        sort($ids);

        return $ids;
    }
}
