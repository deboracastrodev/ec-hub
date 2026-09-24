<?php

declare(strict_types=1);

namespace App\Application\Recommendation\Evaluation;

use JsonException;

/**
 * Story 10.5: the quality numbers published on /metrics (Level 3) and in the
 * README, read from the committed `make eval` report
 * (docs/evaluation/offline-evaluation.json). Nothing is recomputed here: the
 * values are the report's precision@5, catalog coverage@5 (same k as the
 * precision), measured_at, holdout size and -- when the report has the
 * Story 10.4 block -- the offline fallback activation.
 *
 * Any failure (missing file, invalid JSON, missing or out-of-range field)
 * yields null, so the dashboard degrades to "indisponível" instead of
 * showing a wrong number.
 */
final readonly class PublishedQualityMetrics
{
    public const K = '5';

    public function __construct(
        public float $precisionAt5,
        public float $catalogCoverageAt5,
        public string $measuredAt,
        public int $holdoutSize,
        public ?int $fallbackActivated = null,
        public ?int $fallbackQueries = null,
    ) {
    }

    /** Reads the report on every call (no cache); never emits a PHP warning. */
    public static function fromReportFile(string $path): ?self
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            return null;
        }

        try {
            $report = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($report) ? self::fromReport($report) : null;
    }

    /** @param array<mixed> $report decoded offline-evaluation.json */
    public static function fromReport(array $report): ?self
    {
        $measuredAt = $report['measured_at'] ?? null;
        if (! is_string($measuredAt) || trim($measuredAt) === '') {
            return null;
        }

        $precision = self::unitInterval(self::path($report, ['metrics', 'precision_at_k', self::K]));
        $coverage = self::unitInterval(self::path($report, ['coverage', 'catalog_coverage_at_k', self::K]));
        if ($precision === null || $coverage === null) {
            return null;
        }

        $holdoutSize = self::path($report, ['split', 'holdout_size']);
        // An empty holdout would publish the precision of zero queries.
        if (! is_int($holdoutSize) || $holdoutSize <= 0) {
            return null;
        }

        // Optional (Story 10.4 block): a malformed activation is omitted, never shown.
        $activated = self::path($report, ['cold_start', 'activation', 'activated']);
        $queries = self::path($report, ['cold_start', 'activation', 'queries']);
        if (! is_int($activated) || ! is_int($queries) || $activated < 0 || $queries < 0 || $activated > $queries) {
            $activated = null;
            $queries = null;
        }

        return new self($precision, $coverage, trim($measuredAt), $holdoutSize, $activated, $queries);
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $keys
     */
    private static function path(array $data, array $keys): mixed
    {
        $value = $data;
        foreach ($keys as $key) {
            if (! is_array($value) || ! array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private static function unitInterval(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        $value = (float) $value;

        return is_finite($value) && $value >= 0.0 && $value <= 1.0 ? $value : null;
    }
}
