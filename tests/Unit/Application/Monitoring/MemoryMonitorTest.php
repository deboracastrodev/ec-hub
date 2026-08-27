<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\MemoryMonitor;
use PHPUnit\Framework\TestCase;

final class MemoryMonitorTest extends TestCase
{
    public function testItBuildsCurrentPeakAndGrowthSampleBelowThreshold(): void
    {
        $snapshot = (new MemoryMonitor(1_000))->sample(1_100, 1_200);

        self::assertSame(1_100, $snapshot->currentUsageBytes);
        self::assertSame(1_200, $snapshot->peakUsageBytes);
        self::assertEqualsWithDelta(10.0, $snapshot->growthPercent, 0.000_001);
        self::assertFalse($snapshot->alert);
        self::assertSame([
            'current_usage_bytes' => 1_100,
            'peak_usage_bytes' => 1_200,
            'growth_percent' => 10.0,
            'alert' => false,
        ], $snapshot->toArray());
    }

    public function testItActivatesTheAlertOnlyAboveTenPercent(): void
    {
        $snapshot = (new MemoryMonitor(1_000))->sample(1_101, 1_300);

        self::assertEqualsWithDelta(10.1, $snapshot->growthPercent, 0.000_001);
        self::assertTrue($snapshot->alert);
    }

    public function testEachMonitorUsesItsOwnRequestLocalBaseline(): void
    {
        $firstRequest = (new MemoryMonitor(1_000))->sample(1_100, 1_200);
        $secondRequest = (new MemoryMonitor(2_000))->sample(2_100, 2_200);

        self::assertEqualsWithDelta(10.0, $firstRequest->growthPercent, 0.000_001);
        self::assertEqualsWithDelta(5.0, $secondRequest->growthPercent, 0.000_001);
        self::assertFalse($firstRequest->alert);
        self::assertFalse($secondRequest->alert);
    }
}
