<?php

declare(strict_types=1);

namespace App\Application\Recommendation;

/**
 * Story 8.2: which algorithm serves one request, and why.
 *
 * $variant is 'A'/'B' when the A/B test assigned the subject, null when the
 * test is off or there is no subject (then $algorithm is the .env default).
 */
final readonly class AlgorithmAssignment
{
    /** @param 'A'|'B'|null $variant */
    public function __construct(
        public string $algorithm,
        public ?string $variant,
        public ?string $subjectId,
    ) {
    }
}
