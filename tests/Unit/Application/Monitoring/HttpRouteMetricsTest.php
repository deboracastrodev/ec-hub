<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\HttpRouteMetrics;
use PHPUnit\Framework\TestCase;

final class HttpRouteMetricsTest extends TestCase
{
    public function testDerivedCounts(): void
    {
        $metrics = new HttpRouteMetrics('GET', '/x', [200 => 5, 302 => 1, 404 => 2, 422 => 1, 500 => 3, 503 => 1], 1.5, []);

        self::assertSame(13, $metrics->requests());
        self::assertSame(4, $metrics->errors());
        self::assertSame(3, $metrics->clientErrors());
    }

    public function testNoStatusesMeansZero(): void
    {
        $metrics = new HttpRouteMetrics('GET', '/x', [], 0.0, []);

        self::assertSame(0, $metrics->requests());
        self::assertSame(0, $metrics->errors());
        self::assertSame(0, $metrics->clientErrors());
    }

    public function testBucketForPicksTheSmallestLimitThatFits(): void
    {
        self::assertSame('0.005', HttpRouteMetrics::bucketFor(0.0));
        self::assertSame('0.005', HttpRouteMetrics::bucketFor(0.005));
        self::assertSame('0.01', HttpRouteMetrics::bucketFor(0.0051));
        self::assertSame('0.05', HttpRouteMetrics::bucketFor(0.05));
        self::assertSame('1', HttpRouteMetrics::bucketFor(0.7));
        self::assertSame('5', HttpRouteMetrics::bucketFor(5.0));
        self::assertSame('+Inf', HttpRouteMetrics::bucketFor(5.01));
    }

    public function testCumulativeBucketsCoverEveryLimitAndEndWithTheTotal(): void
    {
        $metrics = new HttpRouteMetrics('GET', '/x', [200 => 4], 2.0, ['0.005' => 1, '0.1' => 2, '+Inf' => 1]);

        $cumulative = $metrics->cumulativeBuckets();

        self::assertSame(HttpRouteMetrics::BUCKETS, array_map('strval', array_keys($cumulative)));
        self::assertSame([1, 1, 1, 1, 3, 3, 3, 3, 3, 3, 3, 4], array_values($cumulative));
    }
}
