<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\RecommendationController;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Session\Repository\SessionRepositoryInterface;
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

    public function testItAtomicallyShiftsComparableCurrentSnapshotToPrevious(): void
    {
        $sessions = new RecommendationInMemorySessionRepository([
            'recommendation.snapshot' => ['current' => [
                'source' => 'ml', 'latency_ms' => 1.0, 'avg_confidence' => 80.0,
                'count' => 2, 'generated_at' => '2026-08-24T12:00:00+00:00', 'product_ids' => [2, 3],
            ]],
        ]);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')->willReturn([['product_id' => 4, 'score' => 90.0]]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame([4], $sessions->savedValue['current']['product_ids']);
        self::assertSame([2, 3], $sessions->savedValue['previous']['product_ids']);
        self::assertSame(1, $sessions->saveCount);
    }

    public function testItDoesNotPromoteLegacyFlatSnapshotToPrevious(): void
    {
        $sessions = new RecommendationInMemorySessionRepository([
            'recommendation.snapshot' => [
                'source' => 'ml', 'latency_ms' => 1.0, 'avg_confidence' => 80.0,
                'count' => 2, 'generated_at' => '2026-08-24T12:00:00+00:00',
            ],
        ]);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')->willReturn([['product_id' => 4, 'score' => 90.0]]);

        $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertArrayNotHasKey('previous', $sessions->savedValue);
    }

    public function testSnapshotReadFailureDoesNotPreventResponseOrCurrentWrite(): void
    {
        $sessions = new RecommendationInMemorySessionRepository([], false, true);
        $controller = new RecommendationController($this->mockGenerateRecommendations, $this->mockLogger, $sessions);
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')->willReturn([['product_id' => 4, 'score' => 90.0]]);
        $this->mockLogger->expects($this->once())
            ->method('error')
            ->with('Não foi possível ler o snapshot de recomendação anterior.', $this->arrayHasKey('error'));

        $response = $controller->getRecommendations(['product_id' => '1'], null, 'current-session');

        self::assertSame(1, $response['meta']['count']);
        self::assertSame([4], $sessions->savedValue['current']['product_ids']);
        self::assertArrayNotHasKey('previous', $sessions->savedValue);
        self::assertSame(1, $sessions->saveCount);
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

final class RecommendationInMemorySessionRepository implements SessionRepositoryInterface
{
    public ?string $savedSessionId = null;
    public ?string $savedField = null;
    /** @var array<string, mixed> */
    public array $savedValue = [];

    /** @var array<string, mixed> */
    private array $data;
    private bool $throwsOnSave;
    private bool $throwsOnGet;

    /** @param array<string, mixed>|bool $dataOrThrowsOnSave */
    public function __construct(array|bool $dataOrThrowsOnSave = false, bool $throwsOnSave = false, bool $throwsOnGet = false)
    {
        $this->data = is_array($dataOrThrowsOnSave) ? $dataOrThrowsOnSave : [];
        $this->throwsOnSave = is_bool($dataOrThrowsOnSave) ? $dataOrThrowsOnSave : $throwsOnSave;
        $this->throwsOnGet = $throwsOnGet;
    }

    public function save(string $sessionId, string $field, mixed $value): void
    {
        if ($this->throwsOnSave) {
            throw new \RuntimeException('Redis indisponível.');
        }
        $this->savedSessionId = $sessionId;
        $this->savedField = $field;
        $this->savedValue = $value;
        $this->saveCount++;
    }

    public function get(string $sessionId, string $field): mixed
    {
        if ($this->throwsOnGet) {
            throw new \RuntimeException('Redis indisponível.');
        }

        return $this->data[$field] ?? null;
    }

    public int $saveCount = 0;
}
