<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Recommendation\AlgorithmAssignment;
use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Controller\AbTestResultsController;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryAlgorithmMetricsRepository;

final class AbTestResultsControllerTest extends TestCase
{
    public function testItExportsTheExperimentResultsWithGeneratedAt(): void
    {
        $experiment = $this->experiment(new InMemoryAlgorithmMetricsRepository());
        $experiment->record(new AlgorithmAssignment('knn', 'A', 's1'), [
            'data' => [['score' => 90.0, 'source' => 'ml']],
            'meta' => ['response_time_ms' => 4.0],
        ]);

        $response = (new AbTestResultsController($experiment))->results([], [], null);

        self::assertSame($experiment->results(), $response['data']);
        self::assertTrue($response['data']['enabled']);
        self::assertSame(1, $response['data']['algorithms'][0]['requests']);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, $response['meta']['generated_at']));
    }

    public function testRepositoryFailurePropagates(): void
    {
        $controller = new AbTestResultsController($this->experiment(new InMemoryAlgorithmMetricsRepository(failOnGet: true)));

        $this->expectException(\RuntimeException::class);

        $controller->results();
    }

    private function experiment(InMemoryAlgorithmMetricsRepository $metrics): RecommendationExperiment
    {
        return new RecommendationExperiment(
            RecommendationSettings::fromArray(['ab_test' => 'knn,collaborative']),
            fn (string $algorithm): GenerateRecommendations => $this->createStub(GenerateRecommendations::class),
            new AbTestAssigner(),
            $metrics,
            new NullLogger(),
        );
    }
}
