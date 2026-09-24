<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Recommendation\RecommendationExperiment;

/**
 * Story 8.2: JSON export of the A/B test results (GET /api/ab-tests/results).
 *
 * Reads the same RecommendationExperiment::results() the /metrics panel
 * shows. A Redis failure is not caught here: it reaches the ErrorHandler
 * (500, RFC 7807).
 */
final class AbTestResultsController
{
    public function __construct(private readonly RecommendationExperiment $experiment)
    {
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed>|null $headers
     * @return array{data: array<string, mixed>, meta: array{generated_at: string}}
     */
    public function results(array $queryParams = [], ?array $headers = null, ?string $sessionId = null): array
    {
        return [
            'data' => $this->experiment->results(),
            'meta' => ['generated_at' => date('c')],
        ];
    }
}
