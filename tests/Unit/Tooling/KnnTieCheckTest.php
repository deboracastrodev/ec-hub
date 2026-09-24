<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use PHPUnit\Framework\TestCase;
use Tests\Support\KnnTieCheck;

/**
 * A regra que decide o código de saída 2 do bin/compare-knn-rewrite.php:
 * divergência só é aceita quando as distâncias batem posição a posição.
 */
final class KnnTieCheckTest extends TestCase
{
    public function test_same_distances_in_same_positions_is_a_tie(): void
    {
        $this->assertTrue(KnnTieCheck::explained([0.1, 0.25, 0.25], [0.1, 0.25, 0.25 + 1e-12]));
    }

    public function test_distance_gap_at_any_position_is_not_a_tie(): void
    {
        $this->assertFalse(KnnTieCheck::explained([0.1, 0.25, 0.3], [0.1, 0.25, 0.3 + KnnTieCheck::EPSILON]));
        $this->assertFalse(KnnTieCheck::explained([0.1, 0.25], [0.2, 0.25]));
    }

    public function test_different_lengths_are_not_a_tie(): void
    {
        $this->assertFalse(KnnTieCheck::explained([0.1, 0.25], [0.1, 0.25, 0.3]));
        $this->assertFalse(KnnTieCheck::explained([0.1], []));
    }
}
