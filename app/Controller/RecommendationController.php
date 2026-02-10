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

    /** @var int Minimum number of recommendations allowed */
    private const MIN_LIMIT = 5;

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
     * AC4: 400 Bad Request if user_id missing
     * AC5: Uses fallback automatically when ML fails
     * AC8: Includes response headers
     *
     * @param array<string, string|int> $queryParams Query parameters (user_id, limit)
     * @return array<string, mixed> JSON-serializable response array
     * @throws InvalidRequestException If request validation fails
     * @throws RecommendationException If recommendation generation fails
     */
    public function getRecommendations(array $queryParams, ?array $headers = null): array
    {
        $startTime = microtime(true);

        // Validate request
        $this->validateAuth($headers);
        $userId = $this->validateUserId($queryParams);
        $limit = $this->validateAndParseLimit($queryParams);

        try {
            // Generate recommendations
            $recommendations = $this->generateRecommendations->execute($userId, $limit);

            $responseTime = (microtime(true) - $startTime) * 1000;

            // Log slow requests (AC2: performance monitoring)
            if ($responseTime > self::SLOW_REQUEST_THRESHOLD_MS) {
                $this->logger->warning('Slow recommendation', [
                    'user_id' => $userId,
                    'time_ms' => round($responseTime, 2),
                ]);
            }

            // Format response (AC1, AC8)
            return $this->formatResponse($recommendations, $responseTime);

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
     * Validate user_id from query parameters
     *
     * @param array<string, string|int> $queryParams
     * @return int Validated user ID
     * @throws InvalidRequestException If validation fails
     */
    private function validateUserId(array $queryParams): int
    {
        $rawId = $queryParams['user_id'] ?? null;
        if ($rawId === null) {
            throw new InvalidRequestException('user_id is required', 400);
        }

        $userId = $rawId;

        // First check if it's a valid integer (including negative numbers)
        if (!is_numeric($userId) || (int) $userId != $userId) {
            throw new InvalidRequestException('user_id must be a valid integer');
        }

        $userIdInt = (int) $userId;

        // Validate it's positive
        if ($userIdInt <= 0) {
            throw new InvalidRequestException('user_id must be a positive integer');
        }

        return $userIdInt;
    }

    private function validateAuth(?array $headers = null): void
    {
        $authRequired = getenv('AUTH_REQUIRED');
        if ($authRequired === false || strtolower((string) $authRequired) !== 'true') {
            return;
        }

        $headers = $headers ?? $this->getRequestHeaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if ($authHeader === null || trim((string) $authHeader) === '') {
            throw new InvalidRequestException('Authentication required', 401);
        }
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

        // Enforce AC lower bound
        if ($limitInt < self::MIN_LIMIT) {
            return self::MIN_LIMIT;
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
    private function formatResponse(array $recommendations, float $responseTime): array
    {
        $source = $this->detectSource($recommendations);
        $data = array_map(function (array $rec): array {
            return [
                'id' => $rec['product_id'] ?? $rec['id'] ?? null,
                'name' => $rec['name'] ?? $rec['product_name'] ?? null,
                'price' => $this->normalizePrice($rec['price'] ?? null),
                'score' => $rec['score'] ?? null,
                'explanation' => $rec['explanation'] ?? null,
            ];
        }, $recommendations);

        return [
            'data' => $data,
            'meta' => [
                'source' => $source,
                'count' => count($data),
                'response_time_ms' => round($responseTime, 2),
                'generated_at' => date('c'),
            ],
        ];
    }

    /**
     * @param array<array<string, mixed>> $recommendations
     */
    private function detectSource(array $recommendations): string
    {
        foreach ($recommendations as $rec) {
            if (!isset($rec['fallback_reason'])) {
                continue;
            }
            if ($rec['fallback_reason'] === 'popular_product') {
                return 'popular';
            }
            return 'rules';
        }

        return 'ml';
    }

    /**
     * @return array<string, string>
     */
    private function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            return is_array($headers) ? $headers : [];
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Normalize price to numeric value when possible.
     *
     * @param mixed $price
     */
    private function normalizePrice($price): ?float
    {
        if ($price === null) {
            return null;
        }

        if (is_int($price) || is_float($price)) {
            return (float) $price;
        }

        if (is_string($price)) {
            $normalized = preg_replace('/[^\d,.\-]/', '', $price);
            if ($normalized === null || $normalized === '') {
                return null;
            }

            if (strpos($normalized, ',') !== false && strpos($normalized, '.') !== false) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } elseif (strpos($normalized, ',') !== false) {
                $normalized = str_replace(',', '.', $normalized);
            }

            return is_numeric($normalized) ? (float) $normalized : null;
        }

        return null;
    }
}
