<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Recommendation\GenerateRecommendations;
use App\Controller\Exceptions\InvalidRequestException;
use App\Domain\Recommendation\Exception\RecommendationException;
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

    /** @var int Default number of recommendations to return when limit is omitted */
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
     * Get product recommendations similar to a given product (item-to-item).
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
     * @throws RecommendationException If recommendation generation fails -- left
     *         uncaught here; mapping a domain failure to an HTTP status is the
     *         edge's job (public/index.php), not the controller's
     */
    public function getRecommendations(array $queryParams, ?array $headers = null): array
    {
        $startTime = microtime(true);

        // Validate request
        $this->validateAuth($headers);
        $productId = $this->validateProductId($queryParams);
        $limit = $this->validateAndParseLimit($queryParams);

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
        return $this->formatResponse($recommendations, $responseTime);
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
        $rawId = $queryParams['product_id'] ?? null;
        if ($rawId === null) {
            throw new InvalidRequestException('product_id is required', 400);
        }

        $productId = $rawId;

        // First check if it's a valid integer (including negative numbers)
        if (! is_numeric($productId) || (int) $productId != $productId) {
            throw new InvalidRequestException('product_id must be a valid integer');
        }

        $productIdInt = (int) $productId;

        // Validate it's positive
        if ($productIdInt <= 0) {
            throw new InvalidRequestException('product_id must be a positive integer');
        }

        return $productIdInt;
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
     * Validate and parse limit parameter. Valid range is 1..MAX_LIMIT: values
     * above the max are capped (not an error), but a limit that isn't a
     * positive integer is rejected explicitly rather than silently swapped
     * for the default (R3.6 -- a client asking for limit=1 must get 1, not
     * a value the server picked instead).
     *
     * @param array<string, string|int> $queryParams
     * @return int Parsed and capped limit value
     * @throws InvalidRequestException If limit is provided but not a positive integer
     */
    private function validateAndParseLimit(array $queryParams): int
    {
        if (! isset($queryParams['limit'])) {
            return self::DEFAULT_LIMIT;
        }

        $limit = $queryParams['limit'];

        if (! is_int($limit) && ! ctype_digit((string) $limit)) {
            throw new InvalidRequestException('limit must be a positive integer');
        }

        $limitInt = (int) $limit;

        if ($limitInt < 1) {
            throw new InvalidRequestException('limit must be a positive integer');
        }

        return min($limitInt, self::MAX_LIMIT);
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
                'price' => isset($rec['price']) ? (float) $rec['price'] : null,
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
            if (! isset($rec['fallback_reason'])) {
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
}
