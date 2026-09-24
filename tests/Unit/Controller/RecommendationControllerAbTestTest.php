<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Controller\RecommendationController;
use App\Domain\Recommendation\Service\AbTestAssigner;
use App\Domain\Recommendation\ValueObject\RecommendationSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Tests\Support\InMemoryAlgorithmMetricsRepository;

/**
 * Story 8.2: RecommendationController with the optional A/B experiment.
 */
final class RecommendationControllerAbTestTest extends TestCase
{
    public function testWithoutExperimentAbVariantIsNull(): void
    {
        $default = $this->useCase('knn');
        $controller = new RecommendationController($default, new NullLogger());

        $response = $controller->getRecommendations(['product_id' => '1'], null, 'session-1');

        self::assertArrayHasKey('ab_variant', $response['meta']);
        self::assertNull($response['meta']['ab_variant']);
        self::assertSame('knn', $response['meta']['algorithm']);
    }

    public function testAbOnRunsTheAssignedArmAndExposesItsAlgorithmAndVariant(): void
    {
        $knn = $this->useCase('knn');
        $collaborative = $this->useCase('collaborative');
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = $this->experiment(['ab_test' => 'knn,collaborative'], ['knn' => $knn, 'collaborative' => $collaborative], $metrics);
        $controller = new RecommendationController($knn, new NullLogger(), null, $experiment);
        $assigner = new AbTestAssigner();

        foreach (['s', 'session-2', 'session-3', 'session-4', 'session-5'] as $sessionId) {
            $response = $controller->getRecommendations(['product_id' => '1'], null, $sessionId);
            $variant = $assigner->variantFor($sessionId);

            self::assertSame($variant, $response['meta']['ab_variant']);
            self::assertSame($variant === 'A' ? 'knn' : 'collaborative', $response['meta']['algorithm']);
            $again = $controller->getRecommendations(['product_id' => '1'], null, $sessionId);
            self::assertSame($response['meta']['algorithm'], $again['meta']['algorithm']);
            self::assertSame($response['meta']['ab_variant'], $again['meta']['ab_variant']);
        }

        self::assertCount(10, $metrics->recorded);
        foreach ($metrics->recorded as $entry) {
            self::assertSame(1, $entry['sample']->totalItems);
            self::assertSame(1, $entry['sample']->mlItems);
        }
    }

    public function testUserIdDrivesTheAssignment(): void
    {
        $knn = $this->useCase('knn');
        $collaborative = $this->useCase('collaborative');
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = $this->experiment(['ab_test' => 'knn,collaborative'], ['knn' => $knn, 'collaborative' => $collaborative], $metrics);
        $controller = new RecommendationController($knn, new NullLogger(), null, $experiment);

        $response = $controller->getRecommendations(['product_id' => '1', 'user_id' => 'u'], null, 's');

        self::assertSame((new AbTestAssigner())->variantFor('u'), $response['meta']['ab_variant']);
        self::assertSame('u', $metrics->recorded[0]['sample']->subjectId);
    }

    public function testAbOffRecordsUnderTheEnvAlgorithmWithNullVariant(): void
    {
        $collaborative = $this->useCase('collaborative');
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = $this->experiment(['algorithm' => 'collaborative'], ['collaborative' => $collaborative], $metrics);
        $controller = new RecommendationController($collaborative, new NullLogger(), null, $experiment);

        $response = $controller->getRecommendations(['product_id' => '1'], null, 'session-1');

        self::assertNull($response['meta']['ab_variant']);
        self::assertSame('collaborative', $response['meta']['algorithm']);
        self::assertCount(1, $metrics->recorded);
        self::assertSame('collaborative', $metrics->recorded[0]['algorithm']);
        self::assertSame('session-1', $metrics->recorded[0]['sample']->subjectId);
    }

    public function testNoSubjectUsesTheEnvAlgorithmAndRecordsWithoutSubject(): void
    {
        $knn = $this->useCase('knn');
        $metrics = new InMemoryAlgorithmMetricsRepository();
        $experiment = $this->experiment(['ab_test' => 'collaborative,knn'], ['knn' => $knn], $metrics);
        $controller = new RecommendationController($knn, new NullLogger(), null, $experiment);

        $response = $controller->getRecommendations(['product_id' => '1']);

        self::assertNull($response['meta']['ab_variant']);
        self::assertSame('knn', $response['meta']['algorithm']);
        self::assertSame('knn', $metrics->recorded[0]['algorithm']);
        self::assertNull($metrics->recorded[0]['sample']->subjectId);
    }

    public function testMetricsFailureDoesNotBreakTheResponse(): void
    {
        $knn = $this->useCase('knn');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')
            ->with('Não foi possível registrar métricas do algoritmo.', $this->anything());
        $experiment = $this->experiment([], ['knn' => $knn], new InMemoryAlgorithmMetricsRepository(failOnRecord: true), $logger);
        $controller = new RecommendationController($knn, $logger, null, $experiment);

        $response = $controller->getRecommendations(['product_id' => '1'], null, 'session-1');

        self::assertCount(1, $response['data']);
        self::assertSame('knn', $response['meta']['algorithm']);
    }

    private function useCase(string $algorithm): GenerateRecommendations
    {
        $useCase = $this->createStub(GenerateRecommendations::class);
        $useCase->method('getAlgorithmName')->willReturn($algorithm);
        $useCase->method('execute')->willReturn([
            ['product_id' => 2, 'name' => 'Mouse', 'score' => 80.0, 'source' => 'ml'],
        ]);

        return $useCase;
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, GenerateRecommendations> $useCases
     */
    private function experiment(
        array $config,
        array $useCases,
        InMemoryAlgorithmMetricsRepository $metrics,
        ?LoggerInterface $logger = null,
    ): RecommendationExperiment {
        return new RecommendationExperiment(
            RecommendationSettings::fromArray($config),
            static fn (string $algorithm): GenerateRecommendations => $useCases[$algorithm]
                ?? throw new \LogicException('Unexpected algorithm ' . $algorithm),
            new AbTestAssigner(),
            $metrics,
            $logger ?? new NullLogger(),
        );
    }
}
