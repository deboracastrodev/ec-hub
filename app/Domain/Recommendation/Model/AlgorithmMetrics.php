<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Model;

/**
 * Story 8.2: aggregated counters for one recommendation algorithm, plus the
 * derived rates the comparison panel and the JSON export show. Derived
 * values are rounded to 2 places and null when their denominator is 0.
 */
final readonly class AlgorithmMetrics
{
    public function __construct(
        public string $algorithm,
        public int $requests = 0,
        public int $uniqueSubjects = 0,
        public int $totalItems = 0,
        public int $mlItems = 0,
        public float $scoreSum = 0.0,
        public int $scoredItems = 0,
        public float $responseTimeSumMs = 0.0,
    ) {
    }

    public static function empty(string $algorithm): self
    {
        return new self($algorithm);
    }

    /** Percentage of served items whose source is 'ml'. */
    public function mlItemRate(): ?float
    {
        return $this->totalItems === 0 ? null : round($this->mlItems / $this->totalItems * 100, 2);
    }

    public function avgResponseTimeMs(): ?float
    {
        return $this->requests === 0 ? null : round($this->responseTimeSumMs / $this->requests, 2);
    }

    public function avgScore(): ?float
    {
        return $this->scoredItems === 0 ? null : round($this->scoreSum / $this->scoredItems, 2);
    }
}
