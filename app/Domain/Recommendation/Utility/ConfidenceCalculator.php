<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Utility;

/**
 * Confidence Calculator Utility
 *
 * Pure functions that turn a raw 0-100 recommendation score into the
 * user-facing confidence vocabulary used across the API and UI (Story 3.5):
 * a machine-readable level ('high'|'medium'|'low') and a human-readable
 * Portuguese label ("Alta similaridade" etc).
 *
 * Thresholds default to HIGH >= 80, MEDIUM >= 50 (AC6), but can be
 * overridden per instance for callers that need different cutoffs.
 */
final class ConfidenceCalculator
{
    public const LEVEL_HIGH = 'high';
    public const LEVEL_MEDIUM = 'medium';
    public const LEVEL_LOW = 'low';

    private const DEFAULT_HIGH_THRESHOLD = 80.0;
    private const DEFAULT_MEDIUM_THRESHOLD = 50.0;

    private float $highThreshold;
    private float $mediumThreshold;

    public function __construct(?float $highThreshold = null, ?float $mediumThreshold = null)
    {
        $this->highThreshold = $highThreshold ?? self::DEFAULT_HIGH_THRESHOLD;
        $this->mediumThreshold = $mediumThreshold ?? self::DEFAULT_MEDIUM_THRESHOLD;

        if ($this->highThreshold < $this->mediumThreshold) {
            throw new \InvalidArgumentException(
                'highThreshold must be >= mediumThreshold'
            );
        }
    }

    /**
     * AC6: Score >= 80 -> high, 50-79 -> medium, < 50 -> low.
     */
    public function calculateConfidenceLevel(float $score): string
    {
        if ($score >= $this->highThreshold) {
            return self::LEVEL_HIGH;
        }

        if ($score >= $this->mediumThreshold) {
            return self::LEVEL_MEDIUM;
        }

        return self::LEVEL_LOW;
    }

    /**
     * AC5/AC6: Portuguese label shown next to the score in the UI.
     */
    public function calculateScoreLabel(float $score): string
    {
        return match ($this->calculateConfidenceLevel($score)) {
            self::LEVEL_HIGH => 'Alta similaridade',
            self::LEVEL_MEDIUM => 'Média similaridade',
            default => 'Baixa similaridade',
        };
    }

    /**
     * Scale a raw score expressed in [$min, $max] (e.g. a 0-1 similarity)
     * into the 0-100 range used everywhere else in the recommendation API.
     * Clamped at both ends so an out-of-range input never produces an
     * out-of-range percentage.
     */
    public function normalizeScore(float $score, float $min = 0.0, float $max = 1.0): float
    {
        if ($max <= $min) {
            return 0.0;
        }

        $normalized = (($score - $min) / ($max - $min)) * 100;

        return max(0.0, min(100.0, $normalized));
    }
}
