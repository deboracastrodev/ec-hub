<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\RecommendationController;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RecommendationController
 *
 * Tests HTTP request handling, validation, error handling,
 * response formatting, and performance tracking.
 */
class RecommendationControllerTest extends TestCase
{
    private RecommendationController $controller;
    private GenerateRecommendations $mockGenerateRecommendations;
    private LoggerInterface $mockLogger;

    protected function setUp(): void
    {
        $this->mockGenerateRecommendations = $this->createMock(GenerateRecommendations::class);
        $this->mockLogger = $this->createMock(LoggerInterface::class);

        $this->controller = new RecommendationController(
            $this->mockGenerateRecommendations,
            $this->mockLogger
        );
    }

    public function testGetRecommendationsReturnsJsonResponse(): void
    {
        // Arrange
        $queryParams = ['product_id' => '1'];
        $expectedRecommendations = [
            [
                'product_id' => 2,
                'name' => 'Mouse Gamer',
                'price' => 150.0,
                'category' => 'Eletrônicos',
                'score' => 0.85,
                'explanation' => 'Similar ao produto visualizado',
            ],
        ];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 10)
            ->willReturn($expectedRecommendations);

        // Act
        $response = $this->controller->getRecommendations($queryParams);

        // Assert
        $this->assertIsArray($response);
        $this->assertArrayHasKey('data', $response);
        $this->assertArrayHasKey('meta', $response);
        $this->assertCount(1, $response['data']);
        $this->assertEquals(2, $response['data'][0]['id']);
    }

    public function testResponseMetaExposesTheActiveAlgorithm(): void
    {
        $this->mockGenerateRecommendations->method('execute')->willReturn([
            ['product_id' => 2, 'name' => 'Mouse Gamer', 'score' => 80.0, 'source' => 'ml'],
        ]);
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('getAlgorithmName')
            ->willReturn('collaborative');
        $this->mockLogger->expects($this->never())->method('error');

        $response = $this->controller->getRecommendations(['product_id' => '1']);

        $this->assertSame('collaborative', $response['meta']['algorithm']);
        $this->assertSame('ml', $response['meta']['source']);
        $this->assertSame(1, $response['meta']['count']);
    }

    public function testGetRecommendationsThrowsExceptionWithoutProductId(): void
    {
        // Arrange - Empty query params
        $queryParams = [];

        // Assert/Act
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('product_id is required');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsExceptionWithInvalidProductId(): void
    {
        // Arrange - Invalid product_id
        $queryParams = ['product_id' => 'invalid'];

        // Assert/Act
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('product_id must be a valid integer');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsExceptionWithNegativeProductId(): void
    {
        // Arrange - Negative product_id
        $queryParams = ['product_id' => '-1'];

        // Assert/Act
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('product_id must be a positive integer');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsIncludesMetadata(): void
    {
        // Arrange
        $queryParams = ['product_id' => '1'];
        $expectedRecommendations = [
            [
                'product_id' => 2,
                'name' => 'Mouse Gamer',
                'price' => 150.0,
                'category' => 'Eletrônicos',
                'score' => 0.85,
                'explanation' => 'Similar ao produto visualizado',
            ],
        ];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willReturn($expectedRecommendations);

        // Act
        $response = $this->controller->getRecommendations($queryParams);

        // Assert - Metadata fields present
        $this->assertArrayHasKey('meta', $response);
        $this->assertArrayHasKey('source', $response['meta']);
        $this->assertArrayHasKey('count', $response['meta']);
        $this->assertArrayHasKey('response_time_ms', $response['meta']);
        $this->assertEquals(1, $response['meta']['count']);
        $this->assertIsFloat($response['meta']['response_time_ms']);
    }

    public function testGetRecommendationsRespectsLimitParameter(): void
    {
        // Arrange
        $queryParams = ['product_id' => '1', 'limit' => '5'];
        $expectedRecommendations = [];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 5) // Limit should be passed
            ->willReturn($expectedRecommendations);

        // Act
        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsUsesDefaultLimit(): void
    {
        // Arrange
        $queryParams = ['product_id' => '1']; // No limit specified
        $expectedRecommendations = [];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 10) // Default limit should be 10
            ->willReturn($expectedRecommendations);

        // Act
        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsEnforcesMaximumLimit(): void
    {
        // Arrange
        $queryParams = ['product_id' => '1', 'limit' => '999']; // Over max
        $expectedRecommendations = [];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 50) // Max limit should be capped at 50
            ->willReturn($expectedRecommendations);

        // Act
        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsLogsSlowRequests(): void
    {
        // Arrange
        $queryParams = ['product_id' => '1'];
        $expectedRecommendations = [
            ['product_id' => 2, 'name' => 'Test', 'price' => 100.0, 'category' => 'Test', 'score' => 0.5, 'explanation' => 'Test'],
        ];

        // Mock execute to take some time (simulated by actually working)
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function () use ($expectedRecommendations) {
                usleep(250000); // 250ms to trigger slow request logging

                return $expectedRecommendations;
            });

        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Slow recommendation'),
                $this->callback(fn ($context) => isset($context['product_id']) && isset($context['time_ms']))
            );

        // Act
        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsPropagatesDomainException(): void
    {
        // Arrange -- the controller no longer wraps domain exceptions in an
        // HTTP-flavored one (R3.2): it lets the single RecommendationException
        // propagate as-is, unchanged. Mapping it to a status code is the
        // edge's job (public/index.php), not the controller's.
        $queryParams = ['product_id' => '999']; // Non-existent product

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willThrowException(new RecommendationException('Product not found'));

        // Assert/Act
        $this->expectException(RecommendationException::class);
        $this->expectExceptionMessage('Product not found');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsFormatMatchesAcSpec(): void
    {
        // Arrange - AC1: Response format
        $queryParams = ['product_id' => '1'];
        $expectedRecommendations = [
            [
                'product_id' => 2,
                'name' => 'Mouse Gamer',
                'price' => 150.0,
                'category' => 'Eletrônicos',
                'score' => 0.95,
                'explanation' => 'Customers who bought this also bought...',
            ],
        ];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willReturn($expectedRecommendations);

        // Act
        $response = $this->controller->getRecommendations($queryParams);

        // Assert - AC1 format
        $this->assertArrayHasKey('data', $response);
        $firstRec = $response['data'][0];
        $this->assertArrayHasKey('id', $firstRec, 'AC1: id field required');
        $this->assertArrayHasKey('name', $firstRec, 'AC1: name field required');
        $this->assertArrayHasKey('price', $firstRec, 'AC1: price field required');
        $this->assertArrayHasKey('score', $firstRec, 'AC1: score field required');
        $this->assertArrayHasKey('explanation', $firstRec, 'AC1: explanation field required');
        $this->assertIsFloat($firstRec['price'], 'AC1: price should be numeric');
    }

    public function testGetRecommendationsRespectsLimitOfOne(): void
    {
        // R3.6: a client asking for limit=1 gets 1, not a server-picked
        // minimum (the old MIN_LIMIT=5 silently overrode the caller here).
        $queryParams = ['product_id' => '1', 'limit' => '1'];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 1)
            ->willReturn([]);

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsExceptionForZeroLimit(): void
    {
        $queryParams = ['product_id' => '1', 'limit' => '0'];

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('limit must be a positive integer');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsExceptionForNegativeLimit(): void
    {
        $queryParams = ['product_id' => '1', 'limit' => '-5'];

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('limit must be a positive integer');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsExceptionForNonNumericLimit(): void
    {
        $queryParams = ['product_id' => '1', 'limit' => 'abc'];

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('limit must be a positive integer');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsKeepsFastRequestsUnderSlaThreshold(): void
    {
        $queryParams = ['product_id' => '1'];
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 10)
            ->willReturn([
                ['product_id' => 2, 'name' => 'Test', 'price' => 100.0, 'score' => 0.8, 'explanation' => 'Test'],
            ]);

        $response = $this->controller->getRecommendations($queryParams);

        $this->assertArrayHasKey('meta', $response);
        $this->assertLessThan(200.0, (float) $response['meta']['response_time_ms']);
    }

    public function testGetRecommendationsIncludesConfidenceMetadataPerAc8(): void
    {
        // Story 3.5, AC8: each item carries score_label, reasons, source
        // and confidence_level alongside the existing fields.
        $queryParams = ['product_id' => '1'];
        $expectedRecommendations = [
            [
                'product_id' => 2,
                'name' => 'Fone Bluetooth Premium',
                'price' => 299.90,
                'score' => 87.5,
                'score_label' => 'Alta similaridade',
                'explanation' => 'Recomendado com base em Fone Bluetooth Sony que você visualizou (87% de similaridade)',
                'reasons' => [
                    ['type' => 'similarity', 'description' => '87% similar ao produto visualizado'],
                ],
                'source' => 'ml',
                'confidence_level' => 'high',
            ],
        ];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willReturn($expectedRecommendations);

        $response = $this->controller->getRecommendations($queryParams);

        $firstRec = $response['data'][0];
        $this->assertSame('Alta similaridade', $firstRec['score_label']);
        $this->assertSame('high', $firstRec['confidence_level']);
        $this->assertSame('ml', $firstRec['source']);
        $this->assertNotEmpty($firstRec['reasons']);
        $this->assertSame(2, $firstRec['product_id']);
    }

    public function testItPersistsRecommendationSnapshotForSession(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $controller = new RecommendationController(
            $this->mockGenerateRecommendations,
            $this->mockLogger,
            $sessions
        );
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 10, false, null, 'current-session', null)
            ->willReturn([
                ['product_id' => 2, 'score' => 80.0],
                ['product_id' => 3, 'score' => 60.0],
            ]);

        $response = $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame('current-session', $sessions->savedSessionId);
        self::assertSame('recommendation.snapshot', $sessions->savedField);
        self::assertSame('ml', $sessions->savedValue['current']['source']);
        self::assertSame(70.0, $sessions->savedValue['current']['avg_confidence']);
        self::assertSame([2, 3], $sessions->savedValue['current']['product_ids']);
        self::assertSame($response['meta']['count'], $sessions->savedValue['current']['count']);
        self::assertSame($response['meta']['generated_at'], $sessions->savedValue['current']['generated_at']);
        self::assertArrayNotHasKey('previous', $sessions->savedValue);
    }

    public function testItMovesOnlyAComparableCurrentSnapshotToPrevious(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $sessions->save('current-session', 'recommendation.snapshot', ['current' => [
            'source' => 'rules', 'latency_ms' => 8.0, 'avg_confidence' => 60.0,
            'count' => 1, 'generated_at' => '2026-08-24T11:00:00+00:00', 'product_ids' => [9],
        ]]);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->expects($this->once())->method('execute')->willReturn([['product_id' => 2, 'score' => 80.0]]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame([2], $sessions->savedValue['current']['product_ids']);
        self::assertSame([9], $sessions->savedValue['previous']['product_ids']);
    }

    public function testItDoesNotPromoteALegacyFlatSnapshotToPrevious(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $sessions->save('current-session', 'recommendation.snapshot', ['source' => 'ml', 'count' => 1]);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->expects($this->once())->method('execute')->willReturn([['product_id' => 2, 'score' => 80.0]]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertArrayNotHasKey('previous', $sessions->savedValue);
    }

    public function testItSucceedsOnTheThirdSnapshotWriteAfterTwoContentions(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $sessions->save('current-session', 'recommendation.snapshot', ['current' => [
            'source' => 'ml', 'latency_ms' => 4.0, 'avg_confidence' => 70.0,
            'count' => 1, 'generated_at' => '2026-08-24T11:00:00+00:00', 'product_ids' => [1],
        ]]);
        $sessions->casFailures = 2;
        $sessions->concurrentValue = ['current' => [
            'source' => 'rules', 'latency_ms' => 6.0, 'avg_confidence' => 65.0,
            'count' => 1, 'generated_at' => '2026-08-24T11:01:00+00:00', 'product_ids' => [9],
        ]];
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->expects($this->once())->method('execute')->willReturn([['product_id' => 2, 'score' => 80.0]]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame(3, $sessions->casAttempts);
        self::assertSame([2], $sessions->savedValue['current']['product_ids']);
        self::assertSame([9], $sessions->savedValue['previous']['product_ids']);
    }

    public function testSnapshotPersistenceFailureDoesNotPreventResponse(): void
    {
        $sessions = new RecommendationInMemorySessionRepository(true);
        $controller = new RecommendationController(
            $this->mockGenerateRecommendations,
            $this->mockLogger,
            $sessions
        );
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willReturn([]);
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Não foi possível persistir o snapshot de recomendação.', $this->arrayHasKey('error'));

        $response = $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame(0, $response['meta']['count']);
    }

    public function testItCountsAnMlOnlyResponseAsARequestWithoutFallback(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->method('execute')->willReturn([
            ['product_id' => 2, 'score' => 80.0, 'source' => 'ml'],
            ['product_id' => 3, 'score' => 60.0, 'source' => 'ml'],
        ]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame(
            ['requests' => 1, 'fallback_activated' => 0],
            $sessions->get('current-session', 'recommendation.cold_start')
        );
    }

    public function testItCountsMixedResponsesAcrossTheSession(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $ml = [['product_id' => 2, 'score' => 80.0, 'source' => 'ml']];
        $mixed = [
            ['product_id' => 2, 'score' => 80.0, 'source' => 'ml'],
            ['product_id' => 5, 'score' => 30.0, 'source' => 'rules'],
        ];
        $this->mockGenerateRecommendations->method('execute')->willReturnOnConsecutiveCalls($ml, $ml, $mixed, $ml);

        $responses = [];
        for ($i = 0; $i < 4; ++$i) {
            $responses[] = $controller->getRecommendations(['product_id' => '1'], null, 'current-session');
        }

        self::assertSame(
            ['requests' => 4, 'fallback_activated' => 1],
            $sessions->get('current-session', 'recommendation.cold_start')
        );
        self::assertSame(['ml', 'rules'], array_column($responses[2]['data'], 'source'));
    }

    public function testAnEmptyResponseCountsAsARequestButNotAsFallback(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $sessions->save('current-session', 'recommendation.cold_start', ['requests' => 2, 'fallback_activated' => 1]);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->method('execute')->willReturn([]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame(
            ['requests' => 3, 'fallback_activated' => 1],
            $sessions->get('current-session', 'recommendation.cold_start')
        );
    }

    public function testItDoesNotRecordTheColdStartCounterWithoutSession(): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->method('execute')->willReturn([['product_id' => 2, 'source' => 'popular']]);

        $controller->getRecommendations(['product_id' => '1']);

        self::assertSame(0, $sessions->saves);
        self::assertSame(0, $sessions->casAttempts);
        self::assertSame([], $sessions->all());
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedColdStartCounters(): iterable
    {
        yield 'fallback maior que requests' => [['requests' => 1, 'fallback_activated' => 2]];
        yield 'requests como string' => [['requests' => '4', 'fallback_activated' => 0]];
        yield 'requests negativo' => [['requests' => -1, 'fallback_activated' => 0]];
        yield 'valor não-array' => ['4'];
    }

    #[DataProvider('malformedColdStartCounters')]
    public function testAMalformedStoredCounterRestartsFromZeroAfterAnMlResponse(mixed $stored): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $sessions->save('current-session', 'recommendation.cold_start', $stored);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->method('execute')->willReturn([
            ['product_id' => 2, 'score' => 80.0, 'source' => 'ml'],
        ]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame(
            ['requests' => 1, 'fallback_activated' => 0],
            $sessions->get('current-session', 'recommendation.cold_start')
        );
    }

    #[DataProvider('malformedColdStartCounters')]
    public function testAMalformedStoredCounterRestartsFromZeroAfterAMixedResponse(mixed $stored): void
    {
        $sessions = new RecommendationInMemorySessionRepository();
        $sessions->save('current-session', 'recommendation.cold_start', $stored);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->method('execute')->willReturn([
            ['product_id' => 2, 'score' => 80.0, 'source' => 'ml'],
            ['product_id' => 5, 'score' => 30.0, 'source' => 'rules'],
        ]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame(
            ['requests' => 1, 'fallback_activated' => 1],
            $sessions->get('current-session', 'recommendation.cold_start')
        );
    }

    /** @return iterable<string, array{bool, int}> */
    public static function failingColdStartWrites(): iterable
    {
        yield 'compareAndSwap lança' => [true, 0];
        yield 'compareAndSwap sempre falha' => [false, 100];
    }

    #[DataProvider('failingColdStartWrites')]
    public function testAColdStartCounterFailureKeepsTheResponseAndLogsAnError(bool $casThrows, int $casFailures): void
    {
        $recommendations = [
            ['product_id' => 2, 'score' => 80.0, 'source' => 'ml'],
            ['product_id' => 5, 'score' => 30.0, 'source' => 'rules'],
        ];
        $this->mockGenerateRecommendations->method('execute')->willReturn($recommendations);
        $baselineLogger = $this->createStub(LoggerInterface::class);
        $baseline = (new RecommendationController($this->mockGenerateRecommendations, $baselineLogger))
            ->getRecommendations(['product_id' => '1'], null, 'current-session');

        $sessions = new RecommendationInMemorySessionRepository(false, 'recommendation.cold_start');
        $sessions->casThrows = $casThrows;
        $sessions->casFailures = $casFailures;
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Não foi possível registrar a taxa de cold-start da sessão.', $this->arrayHasKey('error'));
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);

        $response = $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        unset($baseline['meta']['response_time_ms'], $baseline['meta']['generated_at']);
        unset($response['meta']['response_time_ms'], $response['meta']['generated_at']);
        self::assertSame($baseline, $response);
        self::assertNull($sessions->get('current-session', 'recommendation.cold_start'));
        if (! $casThrows) {
            self::assertSame(3, $sessions->casAttempts);
        }
    }

    public function testGetRecommendationsThrowsUnauthorizedWhenAuthRequired(): void
    {
        putenv('AUTH_REQUIRED=true');
        $queryParams = ['product_id' => '1'];

        try {
            $this->expectException(InvalidRequestException::class);
            $this->expectExceptionMessage('Authentication required');
            $this->controller->getRecommendations($queryParams, []);
        } finally {
            putenv('AUTH_REQUIRED'); // cleanup
        }
    }
}

/**
 * savedX, casFailures/casAttempts and throwsOnSave refer only to
 * $trackedField (the snapshot by default), so the Story 10.5 cold-start
 * counter written in the same request does not interfere with them.
 */
final class RecommendationInMemorySessionRepository implements SessionRepositoryInterface
{
    public ?string $savedSessionId = null;
    public ?string $savedField = null;
    /** @var array<string, mixed> */
    public array $savedValue = [];
    public int $casFailures = 0;
    public int $casAttempts = 0;
    /** @var array<string, mixed>|null */
    public ?array $concurrentValue = null;
    public bool $casThrows = false;
    /** Every write to any session and field, including CAS writes. */
    public int $saves = 0;

    /** @var array<string, array<string, mixed>> */
    private array $data = [];

    public function __construct(
        private readonly bool $throwsOnSave = false,
        private readonly string $trackedField = 'recommendation.snapshot',
    ) {
    }

    public function save(string $sessionId, string $field, mixed $value): void
    {
        ++$this->saves;
        if ($field === $this->trackedField) {
            if ($this->throwsOnSave) {
                throw new \RuntimeException('Redis indisponível.');
            }
            $this->savedSessionId = $sessionId;
            $this->savedField = $field;
            $this->savedValue = $value;
        }
        $this->data[$sessionId][$field] = $value;
    }

    public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
    {
        if ($field === $this->trackedField) {
            ++$this->casAttempts;
            if ($this->casThrows) {
                throw new \RuntimeException('Redis indisponível.');
            }
            if ($this->casFailures > 0) {
                --$this->casFailures;
                if ($this->concurrentValue !== null) {
                    $this->data[$sessionId][$field] = $this->concurrentValue;
                }

                return false;
            }
        }
        if (($this->data[$sessionId][$field] ?? null) !== $expected) {
            return false;
        }
        $this->save($sessionId, $field, $value);

        return true;
    }

    public function get(string $sessionId, string $field): mixed
    {
        return $this->data[$sessionId][$field] ?? null;
    }

    /** @return array<string, array<string, mixed>> all stored fields, by session */
    public function all(): array
    {
        return $this->data;
    }
}
