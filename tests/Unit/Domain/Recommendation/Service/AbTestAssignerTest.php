<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Service;

use App\Domain\Recommendation\Service\AbTestAssigner;
use PHPUnit\Framework\TestCase;

final class AbTestAssignerTest extends TestCase
{
    public function testSameSubjectAlwaysGetsTheSameVariant(): void
    {
        $assigner = new AbTestAssigner();

        $first = $assigner->variantFor('session-abc');
        for ($i = 0; $i < 20; ++$i) {
            self::assertSame($first, $assigner->variantFor('session-abc'));
        }
        self::assertSame($first, (new AbTestAssigner())->variantFor('session-abc'));
    }

    public function testVariantFollowsTheSha256Rule(): void
    {
        $assigner = new AbTestAssigner();

        foreach (['s', 'u', 'user-42', str_repeat('a', 64)] as $subject) {
            $expected = hexdec(substr(hash('sha256', $subject), 0, 8)) % 2 === 0 ? 'A' : 'B';
            self::assertSame($expected, $assigner->variantFor($subject));
        }
    }

    public function testThousandDistinctSubjectsSplitRoughlyInHalf(): void
    {
        $assigner = new AbTestAssigner();
        $counts = ['A' => 0, 'B' => 0];

        for ($i = 0; $i < 1000; ++$i) {
            ++$counts[$assigner->variantFor('subject-' . $i)];
        }

        self::assertSame(1000, $counts['A'] + $counts['B']);
        self::assertGreaterThanOrEqual(450, $counts['A']);
        self::assertLessThanOrEqual(550, $counts['A']);
        self::assertGreaterThanOrEqual(450, $counts['B']);
        self::assertLessThanOrEqual(550, $counts['B']);
    }
}
