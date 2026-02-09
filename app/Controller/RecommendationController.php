<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\Exceptions\InvalidRequestException;
use App\Controller\Exceptions\RecommendationException;
use Psr\Log\LoggerInterface;

/**
 * Recommendation Controller
 *
 * Handles HTTP requests for product recommendations API.
 * Provides RESTful endpoint for getting ML-based recommendations
 * with automatic fallback to rule-based strategies.
 *
 * Follows Clean Architecture principles:
 * - Controller layer handles HTTP concerns
 * - Delegates business logic to Application layer
 * - Returns structured data for API responses
 */
class RecommendationController
{
    private GenerateRecommendations $generateRecommendations;
    private LoggerInterface $logger;

    /** @var int Default number of recommendations to return */
    private const DEFAULT_LIMIT = 10;

    /** @var int Maximum number of recommendations allowed */
    private const MAX_LIMIT = 50;

    /** @var int Response time threshold for slow request logging (ms) */
    private const SLOW_REQUEST_THRESHOLD_MS = 200;

    public function __construct(
        GenerateRecommendations $generateRecommendations,
        LoggerInterface $logger
    ) {
        $this->generateRecommendations = $generateRecommendations;
        $this->logger = $logger;
    }

    /**
     * Get product recommendations for a target product
     *
     * AC1: Returns JSON response with product recommendations
     * AC2: Response time < 200ms
     * AC4: 400 Bad Request if product_id missing
     * AC5: Uses fallback automatically when ML fails
     * AC8: Includes response headers
     *
     * @param array<string, string|int> $queryParams Query parameters (product_id, limit)
     * @return array<string, mixed> JSON-serializable response array
     * @throws InvalidRequestException If request validation fails
     * @throws RecommendationException If recommendation generation fails
     */
    public function getRecommendations(array $queryParams): array
    {
        $startTime = microtime(true);

        // Validate request
        $productId = $this->validateProductId($queryParams);
        $limit = $this->validateAndParseLimit($queryParams);

        try {
            // Generate recommendations
            $recommendations = $this->generateRecommendations->execute($productId, $limit);

            $responseTime = (microtime(true) - $startTime) * 1000;

            // Log slow requests (AC2: performance monitoring)
            if ($responseTime > self::SLOW_REQUEST_THRESHOLD_MS) {
                $this->logger->warning('Slow recommendation', [
                    'product_id' => $productId,
                    'time_ms' => round($responseTime, 2),
                ]);
            }

            // Format response (AC1, AC8)
            return $this->formatResponse($recommendations, 'ml', $responseTime);

        } catch (\App\Domain\Recommendation\Exception\RecommendationException $e) {
            // Domain exception - wrap in controller exception
            throw new RecommendationException(
                'Failed to generate recommendations',
                500,
                $e
            );
        }
    }

    /**
     * Validate product_id from query parameters
     *
     * @param array<string, string|int> $queryParams
     * @return int Validated product ID
     * @throws InvalidRequestException If validation fails
     */
    private function validateProductId(array $queryParams): int
    {
        if (!isset($queryParams['product_id'])) {
            throw new InvalidRequestException('product_id is required');
        }

        $productId = $queryParams['product_id'];

        // First check if it's a valid integer (including negative numbers)
        if (!is_numeric($productId) || (int) $productId != $productId) {
            throw new InvalidRequestException('product_id must be a valid integer');
        }

        $productIdInt = (int) $productId;

        // Validate it's positive
        if ($productIdInt <= 0) {
            throw new InvalidRequestException('product_id must be a positive integer');
        }

        return $productIdInt;
    }

    /**
     * Validate and parse limit parameter
     *
     * @param array<string, string|int> $queryParams
     * @return int Parsed and capped limit value
     */
    private function validateAndParseLimit(array $queryParams): int
    {
        if (!isset($queryParams['limit'])) {
            return self::DEFAULT_LIMIT;
        }

        $limit = $queryParams['limit'];

        // Validate it's an integer
        if (!ctype_digit((string) $limit) && !is_int($limit)) {
            return self::DEFAULT_LIMIT;
        }

        $limitInt = (int) $limit;

        // Enforce maximum limit
        if ($limitInt > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }

        // Ensure minimum of 1
        if ($limitInt < 1) {
            return 1;
        }

        return $limitInt;
    }

    /**
     * Format response according to AC1 specification
     *
     * @param array<array<string, mixed>> $recommendations
     * @param string $source Source of recommendations (ml|rules|popular)
     * @param float $responseTime Response time in milliseconds
     * @return array<string, mixed> Formatted response
     */
    private function formatResponse(array $recommendations, string $source, float $responseTime): array
    {
        return [
            'data' => $recommendations,
            'meta' => [
                'source' => $source,
                'count' => count($recommendations),
                'response_time_ms' => round($responseTime, 2),
                'generated_at' => date('c'),
            ],
        ];
    }
}
