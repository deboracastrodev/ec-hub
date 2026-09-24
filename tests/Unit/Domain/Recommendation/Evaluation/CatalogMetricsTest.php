<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Evaluation;

use App\Domain\Recommendation\Evaluation\CatalogMetrics;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogMetricsTest extends TestCase
{
    public function test_coverage_ignores_ids_outside_candidates_and_duplicates(): void
    {
        // 99 não é candidato; 2 aparece duas vezes; candidato 4 repetido conta uma vez no denominador.
        $result = CatalogMetrics::coverage([[1, 2, 2], [99, 3], []], [1, 2, 3, 4, 4]);

        $this->assertSame(['covered' => 3, 'coverage' => 0.75], $result);
    }

    public function test_coverage_without_lists_is_zero(): void
    {
        $this->assertSame(['covered' => 0, 'coverage' => 0.0], CatalogMetrics::coverage([], [1, 2]));
        $this->assertSame(['covered' => 0, 'coverage' => 0.0], CatalogMetrics::coverage([[], []], [1, 2]));
    }

    public function test_coverage_requires_candidates(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogMetrics::coverage([[1]], []);
    }

    public function test_intra_list_diversity_hand_computed(): void
    {
        $this->assertSame(0.0, CatalogMetrics::intraListDiversity(['A', 'A', 'A']));
        $this->assertSame(1.0, CatalogMetrics::intraListDiversity(['A', 'B', 'C']));
        // pares: (A,A) igual, (A,B) e (A,B) diferentes → 2/3
        $this->assertEqualsWithDelta(2 / 3, CatalogMetrics::intraListDiversity(['A', 'A', 'B']), 1e-12);
        $this->assertSame(1.0, CatalogMetrics::intraListDiversity(['A', 'B']));
    }

    public function test_intra_list_diversity_is_null_below_two_items(): void
    {
        $this->assertNull(CatalogMetrics::intraListDiversity(['A']));
        $this->assertNull(CatalogMetrics::intraListDiversity([]));
    }

    public function test_gini_hand_computed(): void
    {
        $this->assertSame(0.0, CatalogMetrics::gini([2, 2, 2, 2]));
        // tudo num só item: (n − 1) / n
        $this->assertSame(0.75, CatalogMetrics::gini([0, 5, 0, 0]));
        // [1, 3]: (−1·1 + 1·3) / (2·4) = 0.25
        $this->assertSame(0.25, CatalogMetrics::gini([3, 1]));
    }

    public function test_gini_is_null_without_appearances(): void
    {
        $this->assertNull(CatalogMetrics::gini([0, 0, 0]));
        $this->assertNull(CatalogMetrics::gini([]));
    }

    public function test_top_share_rounds_the_top_up_with_ceil(): void
    {
        // 11 candidatos × 0.1 = 1.1 → top 2: (5 + 1) / 15
        $counts = array_merge([5], array_fill(0, 10, 1));
        $this->assertSame(0.4, CatalogMetrics::topShare($counts, 0.1));
        // 30 × 0.1 = 3.0000000000000004 em ponto flutuante, mas o top é 3, não 4
        $this->assertSame(0.1, CatalogMetrics::topShare(array_fill(0, 30, 1), 0.1));
        $this->assertSame(1.0, CatalogMetrics::topShare([1, 2, 3], 1.0));
    }

    public function test_top_group_size_uses_ceil_with_float_guard(): void
    {
        $this->assertSame(7, CatalogMetrics::topGroupSize(64, 0.1));
        $this->assertSame(3, CatalogMetrics::topGroupSize(30, 0.1));
        $this->assertSame(1, CatalogMetrics::topGroupSize(1, 0.1));
        $this->assertSame(0, CatalogMetrics::topGroupSize(0, 0.1));
        $this->assertSame(5, CatalogMetrics::topGroupSize(5, 1.0));

        $this->expectException(InvalidArgumentException::class);
        CatalogMetrics::topGroupSize(10, 0.0);
    }

    public function test_top_share_is_null_without_appearances(): void
    {
        $this->assertNull(CatalogMetrics::topShare([0, 0], 0.1));
        $this->assertNull(CatalogMetrics::topShare([], 0.1));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidRatios(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negativo' => [-0.1];
        yield 'acima de 1' => [1.5];
        yield 'NaN' => [NAN];
    }

    #[DataProvider('invalidRatios')]
    public function test_top_share_rejects_ratio_outside_zero_one(float $ratio): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogMetrics::topShare([1, 2], $ratio);
    }

    public function test_negative_counts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogMetrics::gini([1, -1]);
    }

    public function test_negative_counts_are_rejected_in_top_share(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogMetrics::topShare([1, -1], 0.5);
    }
}
