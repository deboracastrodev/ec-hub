<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation;

use App\Application\Recommendation\AlgorithmAssignment;
use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryAlgorithmMetricsRepository;

final class RecommendationExperimentTest extends TestCase
{
    /** @var list<string> */
    private array $built = [];

    public function testAbOffUsesTheEnvAlgorithmWithoutVariant(): void
    {
        $experiment = $this->experiment(['algorithm' => 'collaborative']);

        $assignment = $experiment->assign('session-1', null);

        self::assertSame('collaborative', $assignment->algorithm);
        self::assertNull($assignment->variant);
        self::assertSame('session-1', $assignment->subjectId);
    }

    public function testAbOnAssignsBySessionHashAndIsStable(): void
    {
        $experiment = $this->experiment(['ab_test' => 'knn,collaborative']);
        $assigner = new AbTestAssigner();

        foreach (['s', 'session-2', 'session-3', 'session-4'] as $sessionId) {
            $assignment = $experiment->assign($sessionId, null);
            $expectedVariant = $assigner->variantFor($sessionId);

            self::assertSame($expectedVariant, $assignment->variant);
            self::assertSame($expectedVariant === 'A' ? 'knn' : 'collaborative', $assignment->algorithm);
            self::assertEquals($assignment, $experiment->assign($sessionId, null));
        }
    }

    public function testBothArmsAreReachable(): void
    {
        $experiment = $this->experiment(['ab_test' => 'knn,collaborative']);
        $algorithms = [];
        for ($i = 0; $i < 50; ++$i) {
            $algorithms[$experiment->assign('session-' . $i, null)->algorithm] = true;
        }

        self::assertArrayHasKey('knn', $algorithms);
        self::assertArrayHasKey('collaborative', $algorithms);
    }

    public function testUserIdTakesPrecedenceOverSession(): void
    {
        $experiment = $this->experiment(['ab_test' => 'knn,collaborative']);
        $assigner = new AbTestAssigner();

        $assignment = $experiment->assign('s', ' u ');

        self::assertSame('u', $assignment->subjectId);
        self::assertSame($assigner->variantFor('u'), $assignment->variant);
    }

    public function testBlankUserIdFallsBackToSession(): void
    {
        $experiment = $this->experiment(['ab_test' => 'knn,collaborative']);

        self::assertSame('s', $experiment->assign(' s ', '   ')->subjectId);
    }

    public function testNoSubjectUsesTheEnvAlgorithmWithoutVariant(): void
    {
        $experiment = $this->experiment(['ab_test' => 'collaborative,knn']);

        foreach ([[null, null], ['', '  '], ['  ', null]] as [$sessionId, $userId]) {
            $assignment = $experiment->assign($sessionId, $userId);

            self::assertSame('knn', $assignment->algorithm);
            self::assertNull($assignment->variant);
            self::assertNull($assignment->subjectId);
        }
    }

    public function testUseCaseFactoryIsLazyAndMemoizedPerAlgorithm(): void
    {
        $experiment = $this->experiment(['ab_test' => 'knn,collaborative']);

        self::assertSame([], $this->built);
        $knn = $experiment->useCaseFor('knn');
        self::assertSame($knn, $experiment->useCaseFor('knn'));
        self::assertSame(['knn'], $this->built);
        self::assertSame('collaborative', $experiment->useCaseFor('collaborative')->getAlgorithmName());
        self::assertSame(['knn', 'collaborative'], $this->built);
    }

    public function testRecordStoresASampleUnderTheServingAlgorithm(): void
    {
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = $this->experiment([], $metrics);

        $experiment->record(new AlgorithmAssignment('knn', null, 'session-1'), $this->response());

        self::assertCount(1, $metrics->recorded);
        self::assertSame('knn', $metrics->recorded[0]['algorithm']);
        $sample = $metrics->recorded[0]['sample'];
        self::assertSame(2, $sample->totalItems);
        self::assertSame(1, $sample->mlItems);
        self::assertSame(140.0, $sample->scoreSum);
        self::assertSame(2, $sample->scoredItems);
        self::assertSame(8.5, $sample->responseTimeMs);
        self::assertSame('session-1', $sample->subjectId);
    }

    public function testRecordWithoutSubjectStoresSampleWithoutSubjectId(): void
    {
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = $this->experiment([], $metrics);

        $experiment->record(new AlgorithmAssignment('knn', null, null), $this->response());

        self::assertNull($metrics->recorded[0]['sample']->subjectId);
    }

    public function testRecordFailureIsLoggedAsWarningAndSwallowed(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Não foi possível registrar métricas do algoritmo.', $this->arrayHasKey('error'));
        $experiment = $this->experiment([], new InMemoryAlgorithmMetricsRepository(failOnRecord: true), $logger);

        $experiment->record(new AlgorithmAssignment('knn', 'A', 's'), $this->response());
    }

    public function testResultsWithoutDataListEveryAlgorithmZeroed(): void
    {
        $results = $this->experiment()->results();

        self::assertFalse($results['enabled']);
        self::assertNull($results['variants']);
        self::assertSame(['knn', 'collaborative'], array_column($results['algorithms'], 'algorithm'));
        foreach ($results['algorithms'] as $row) {
            self::assertNull($row['variant']);
            self::assertSame(0, $row['requests']);
            self::assertSame(0, $row['unique_subjects']);
            self::assertSame(0, $row['total_items']);
            self::assertSame(0, $row['ml_items']);
            self::assertNull($row['ml_item_rate']);
            self::assertNull($row['avg_response_time_ms']);
            self::assertNull($row['avg_score']);
        }
    }

    public function testResultsAggregatePerAlgorithmWithVariants(): void
    {
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = $this->experiment(['ab_test' => 'collaborative,knn'], $metrics);
        $experiment->record(new AlgorithmAssignment('knn', 'B', 's1'), $this->response());
        $experiment->record(new AlgorithmAssignment('knn', 'B', 's1'), $this->response());
        $experiment->record(new AlgorithmAssignment('collaborative', 'A', 's2'), $this->response());

        $results = $experiment->results();

        self::assertTrue($results['enabled']);
        self::assertSame(['A' => 'collaborative', 'B' => 'knn'], $results['variants']);
        self::assertSame([
            'algorithm' => 'knn',
            'variant' => 'B',
            'requests' => 2,
            'unique_subjects' => 1,
            'total_items' => 4,
            'ml_items' => 2,
            'ml_item_rate' => 50.0,
            'avg_response_time_ms' => 8.5,
            'avg_score' => 70.0,
        ], $results['algorithms'][0]);
        self::assertSame('A', $results['algorithms'][1]['variant']);
        self::assertSame(1, $results['algorithms'][1]['requests']);
        self::assertSame(3, array_sum(array_column($results['algorithms'], 'requests')));
    }

    /** @return array<string, mixed> */
    private function response(): array
    {
        return [
            'data' => [
                ['product_id' => 2, 'score' => 80.0, 'source' => 'ml'],
                ['product_id' => 3, 'score' => 60.0, 'source' => 'rules'],
            ],
            'meta' => ['source' => 'rules', 'response_time_ms' => 8.5],
        ];
    }

    /** @param array<string, mixed> $config */
    private function experiment(
        array $config = [],
        ?InMemoryAlgorithmMetricsRepository $metrics = null,
        ?LoggerInterface $logger = null,
    ): RecommendationExperiment {
        return new RecommendationExperiment(
            RecommendationSettings::fromArray($config),
            function (string $algorithm): GenerateRecommendations {
                $this->built[] = $algorithm;
                $useCase = $this->createStub(GenerateRecommendations::class);
                $useCase->method('getAlgorithmName')->willReturn($algorithm);

                return $useCase;
            },
            new AbTestAssigner(),
            $metrics ?? new InMemoryAlgorithmMetricsRepository(),
            $logger ?? new NullLogger(),
        );
    }
}
