<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Evaluation;

use App\Domain\Recommendation\Evaluation\RankingMetrics;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RankingMetricsTest extends TestCase
{
    public function test_hand_computed_values(): void
    {
        $recommended = [10, 20, 30, 40, 50];
        $relevant = [20, 40, 60, 70];

        // top-1 = [10]: 0 acertos
        $this->assertSame(0.0, RankingMetrics::precisionAtK($recommended, $relevant, 1));
        $this->assertSame(0.0, RankingMetrics::recallAtK($recommended, $relevant, 1));
        // top-2 = [10, 20]: 1 acerto
        $this->assertSame(0.5, RankingMetrics::precisionAtK($recommended, $relevant, 2));
        $this->assertSame(0.25, RankingMetrics::recallAtK($recommended, $relevant, 2));
        // top-5: 2 acertos (20, 40)
        $this->assertSame(0.4, RankingMetrics::precisionAtK($recommended, $relevant, 5));
        $this->assertSame(0.5, RankingMetrics::recallAtK($recommended, $relevant, 5));
    }

    public function test_short_list_is_still_divided_by_k(): void
    {
        $recommended = [1, 2];
        $relevant = [1, 2, 3];

        $this->assertSame(0.2, RankingMetrics::precisionAtK($recommended, $relevant, 10));
        $this->assertEqualsWithDelta(2 / 3, RankingMetrics::recallAtK($recommended, $relevant, 10), 1e-12);
    }

    public function test_empty_list_scores_zero(): void
    {
        $this->assertSame(0.0, RankingMetrics::precisionAtK([], [1], 5));
        $this->assertSame(0.0, RankingMetrics::recallAtK([], [1], 5));
    }

    public function test_zero_hits(): void
    {
        $this->assertSame(0.0, RankingMetrics::precisionAtK([4, 5, 6], [1, 2, 3], 3));
        $this->assertSame(0.0, RankingMetrics::recallAtK([4, 5, 6], [1, 2, 3], 3));
    }

    public function test_all_hits(): void
    {
        $this->assertSame(1.0, RankingMetrics::precisionAtK([1, 2, 3], [1, 2, 3], 3));
        $this->assertSame(1.0, RankingMetrics::recallAtK([1, 2, 3], [1, 2, 3], 3));
    }

    public function test_duplicates_count_once(): void
    {
        // O mesmo item repetido no top-k não vira dois acertos.
        $this->assertSame(0.25, RankingMetrics::precisionAtK([7, 7, 8, 9], [7], 4));
        $this->assertSame(1.0, RankingMetrics::recallAtK([7, 7, 8, 9], [7], 4));
        // Relevantes duplicados não inflam o denominador do recall.
        $this->assertSame(1.0, RankingMetrics::recallAtK([7], [7, 7], 1));
    }

    public function test_recall_without_relevant_is_undefined(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RankingMetrics::recallAtK([1, 2], [], 2);
    }

    public function test_rejects_non_positive_k(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RankingMetrics::precisionAtK([1], [1], 0);
    }
}
