<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Model;

/**
 * Story 8.2: what one GET /api/recommendations response contributes to the
 * per-algorithm aggregate. Derived from the already-formatted response.
 */
final readonly class AlgorithmRequestSample
{
    public function __construct(
        public int $totalItems,
        public int $mlItems,
        public float $scoreSum,
        public int $scoredItems,
        public float $responseTimeMs,
        public ?string $subjectId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $response Formatted response ({data, meta})
     */
    public static function fromResponse(array $response, ?string $subjectId = null): self
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];

        $totalItems = 0;
        $mlItems = 0;
        $scoreSum = 0.0;
        $scoredItems = 0;
        foreach ($data as $item) {
            ++$totalItems;
            if (! is_array($item)) {
                continue;
            }
            if (($item['source'] ?? null) === 'ml') {
                ++$mlItems;
            }
            $score = $item['score'] ?? null;
            if ((is_int($score) || is_float($score)) && is_finite((float) $score)) {
                $scoreSum += (float) $score;
                ++$scoredItems;
            }
        }

        $responseTime = $meta['response_time_ms'] ?? null;

        return new self(
            $totalItems,
            $mlItems,
            $scoreSum,
            $scoredItems,
            is_int($responseTime) || is_float($responseTime) ? (float) $responseTime : 0.0,
            $subjectId !== null && trim($subjectId) !== '' ? $subjectId : null,
        );
    }
}
