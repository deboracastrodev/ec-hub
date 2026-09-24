<?php

declare(strict_types=1);

namespace App\Domain\Recommendation\Service;

/**
 * Story 8.2: deterministic 50/50 A/B split.
 *
 * The same subject (user_id or session_id) always lands on the same
 * variant, so nothing is persisted (no session field, no cookie): the hash
 * is the assignment.
 */
final class AbTestAssigner
{
    public const VARIANT_A = 'A';
    public const VARIANT_B = 'B';

    /** @return 'A'|'B' */
    public function variantFor(string $subjectId): string
    {
        return hexdec(substr(hash('sha256', $subjectId), 0, 8)) % 2 === 0
            ? self::VARIANT_A
            : self::VARIANT_B;
    }
}
