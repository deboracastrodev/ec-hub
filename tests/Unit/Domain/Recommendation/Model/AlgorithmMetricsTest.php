<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recommendation\Model;

use App\Domain\Recommendation\Model\AlgorithmMetrics;
use App\Domain\Recommendation\Model\AlgorithmRequestSample;
use PHPUnit\Framework\TestCase;

final class AlgorithmMetricsTest extends TestCase
{
    public function testEmptyMetricsHaveZeroCountersAndNullDerivedValues(): void
    {
        $metrics = AlgorithmMetrics::empty('knn');

        self::assertSame('knn', $metrics->algorithm);
        self::assertSame(0, $metrics->requests);
        self::assertSame(0, $metrics->uniqueSubjects);
        self::assertSame(0, $metrics->totalItems);
        self::assertNull($metrics->mlItemRate());
        self::assertNull($metrics->avgResponseTimeMs());
        self::assertNull($metrics->avgScore());
    }

    public function testDerivedValuesAreRoundedToTwoPlaces(): void
    {
        $metrics = new AlgorithmMetrics('collaborative', 3, 2, 3, 1, 200.0, 3, 10.0);

        self::assertSame(33.33, $metrics->mlItemRate());
        self::assertSame(3.33, $metrics->avgResponseTimeMs());
        self::assertSame(66.67, $metrics->avgScore());
    }

    public function testRequestsWithoutItemsKeepItemRatesNull(): void
    {
        $metrics = new AlgorithmMetrics('knn', 2, 1, 0, 0, 0.0, 0, 5.0);

        self::assertNull($metrics->mlItemRate());
        self::assertNull($metrics->avgScore());
        self::assertSame(2.5, $metrics->avgResponseTimeMs());
    }

    public function testSampleIsDerivedFromTheFormattedResponse(): void
    {
        $sample = AlgorithmRequestSample::fromResponse([
            'data' => [
                ['source' => 'ml', 'score' => 80.0],
                ['source' => 'ml', 'score' => 60.0],
                ['source' => 'rules', 'score' => null],
                ['source' => 'popular', 'score' => 50.0],
            ],
            'meta' => ['response_time_ms' => 12.34],
        ], 'session-1');

        self::assertSame(4, $sample->totalItems);
        self::assertSame(2, $sample->mlItems);
        self::assertSame(190.0, $sample->scoreSum);
        self::assertSame(3, $sample->scoredItems);
        self::assertSame(12.34, $sample->responseTimeMs);
        self::assertSame('session-1', $sample->subjectId);
    }

    public function testSampleFromEmptyResponseHasNoSubjectWhenNoneIsGiven(): void
    {
        $sample = AlgorithmRequestSample::fromResponse(['data' => [], 'meta' => []]);

        self::assertSame(0, $sample->totalItems);
        self::assertSame(0.0, $sample->responseTimeMs);
        self::assertNull($sample->subjectId);
    }
}
