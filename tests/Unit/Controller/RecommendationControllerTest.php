<?php
declare(strict_types=1);

namespace Tests\Unit\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\RecommendationController;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\Exceptions\RecommendationException;
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
        $queryParams = ['user_id' => '1'];
        $expectedRecommendations = [
            [
                'product_id' => 2,
                'name' => 'Mouse Gamer',
                'price' => 'R$ 150,00',
                'category' => 'Eletrônicos',
                'score' => 0.85,
                'explanation' => 'Similar ao produto visualizado'
            ]
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
        $this->expectExceptionMessage('user_id is required');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsExceptionWithInvalidProductId(): void
    {
        // Arrange - Invalid product_id
        $queryParams = ['user_id' => 'invalid'];

        // Assert/Act
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('user_id must be a valid integer');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsExceptionWithNegativeProductId(): void
    {
        // Arrange - Negative product_id
        $queryParams = ['user_id' => '-1'];

        // Assert/Act
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('user_id must be a positive integer');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsIncludesMetadata(): void
    {
        // Arrange
        $queryParams = ['user_id' => '1'];
        $expectedRecommendations = [
            [
                'product_id' => 2,
                'name' => 'Mouse Gamer',
                'price' => 'R$ 150,00',
                'category' => 'Eletrônicos',
                'score' => 0.85,
                'explanation' => 'Similar ao produto visualizado'
            ]
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
        $queryParams = ['user_id' => '1', 'limit' => '5'];
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
        $queryParams = ['user_id' => '1']; // No limit specified
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
        $queryParams = ['user_id' => '1', 'limit' => '999']; // Over max
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
        $queryParams = ['user_id' => '1'];
        $expectedRecommendations = [
            ['product_id' => 2, 'name' => 'Test', 'price' => 'R$ 100', 'category' => 'Test', 'score' => 0.5, 'explanation' => 'Test']
        ];

        // Mock execute to take some time (simulated by actually working)
        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willReturnCallback(function() use ($expectedRecommendations) {
                usleep(250000); // 250ms to trigger slow request logging
                return $expectedRecommendations;
            });

        $this->mockLogger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Slow recommendation'),
                $this->callback(fn($context) => isset($context['user_id']) && isset($context['time_ms']))
            );

        // Act
        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsHandlesServiceException(): void
    {
        // Arrange
        $queryParams = ['user_id' => '999']; // Non-existent product

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->willThrowException(new \App\Domain\Recommendation\Exception\RecommendationException('Product not found'));

        // Assert/Act
        $this->expectException(RecommendationException::class);
        $this->expectExceptionMessage('Failed to generate recommendations');

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsFormatMatchesAcSpec(): void
    {
        // Arrange - AC1: Response format
        $queryParams = ['user_id' => '1'];
        $expectedRecommendations = [
            [
                'product_id' => 2,
                'name' => 'Mouse Gamer',
                'price' => 'R$ 150,00',
                'category' => 'Eletrônicos',
                'score' => 0.95,
                'explanation' => 'Customers who bought this also bought...'
            ]
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

    public function testGetRecommendationsEnforcesMinimumLimitOfFive(): void
    {
        $queryParams = ['user_id' => '1', 'limit' => '1'];

        $this->mockGenerateRecommendations->expects($this->once())
            ->method('execute')
            ->with(1, 5)
            ->willReturn([]);

        $this->controller->getRecommendations($queryParams);
    }

    public function testGetRecommendationsThrowsUnauthorizedWhenAuthRequired(): void
    {
        putenv('AUTH_REQUIRED=true');
        $queryParams = ['user_id' => '1'];

        try {
            $this->expectException(InvalidRequestException::class);
            $this->expectExceptionMessage('Authentication required');
            $this->controller->getRecommendations($queryParams, []);
        } finally {
            putenv('AUTH_REQUIRED'); // cleanup
        }
    }
}
