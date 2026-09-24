<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Recommendation\AlgorithmAssignment;
use App\Application\Recommendation\GenerateRecommendations;
use App\Application\Recommendation\RecommendationExperiment;
use App\Controller\Exceptions\InvalidRequestException;
use App\Domain\Recommendation\Exception\RecommendationException;
use App\Domain\Session\Repository\SessionRepositoryInterface;
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
    private const SNAPSHOT_PERSISTENCE_ATTEMPTS = 3;
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
        LoggerInterface $logger,
        private readonly ?SessionRepositoryInterface $sessions = null,
        // Story 8.2: when present, routes each request to its A/B arm and
        // records per-algorithm metrics; absent, behaves as before.
        private readonly ?RecommendationExperiment $experiment = null,
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
    public function getRecommendations(array $queryParams, ?array $headers = null, ?string $sessionId = null): array
    {
        $startTime = microtime(true);

        // Validate request
        $this->validateAuth($headers);
        $productId = $this->validateProductId($queryParams);
        $limit = $this->validateAndParseLimit($queryParams);

        // Generate recommendations
        $userId = null;
        if (array_key_exists('user_id', $queryParams)) {
            if (! is_string($queryParams['user_id']) || trim($queryParams['user_id']) === '') {
                throw new InvalidRequestException('user_id must be a non-empty string');
            }
            $userId = trim($queryParams['user_id']);
        }
        $assignment = null;
        $useCase = $this->generateRecommendations;
        if ($this->experiment !== null) {
            $assignment = $this->experiment->assign($sessionId, $userId);
            $useCase = $this->experiment->useCaseFor($assignment->algorithm);
        }

        $recommendations = ($sessionId === null && $userId === null)
            ? $useCase->execute($productId, $limit)
            : $useCase->execute($productId, $limit, false, null, $sessionId, $userId);

        $responseTime = (microtime(true) - $startTime) * 1000;

        // Log slow requests (AC2: performance monitoring)
        if ($responseTime > self::SLOW_REQUEST_THRESHOLD_MS) {
            $this->logger->warning('Slow recommendation', [
                'product_id' => $productId,
                'time_ms' => round($responseTime, 2),
            ]);
        }

        // Format response (AC1, AC8)
        $response = $this->formatResponse($recommendations, $responseTime, $useCase, $assignment);

        if ($sessionId !== null) {
            $this->persistRecommendationSnapshot($sessionId, $response);
            $this->recordColdStart($sessionId, $response);
        }

        if ($this->experiment !== null && $assignment !== null) {
            // Never throws: a metrics failure is logged as a warning.
            $this->experiment->record($assignment, $response);
        }

        return $response;
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
     * @param float $responseTime Response time in milliseconds
     * @param GenerateRecommendations $useCase The use case that actually served the request
     * @param AlgorithmAssignment|null $assignment A/B assignment (Story 8.2), null without experiment
     * @return array<string, mixed> Formatted response
     */
    private function formatResponse(
        array $recommendations,
        float $responseTime,
        GenerateRecommendations $useCase,
        ?AlgorithmAssignment $assignment,
    ): array {
        $source = $this->detectSource($recommendations);
        $data = array_map(function (array $rec) use ($source): array {
            $productId = $rec['product_id'] ?? $rec['id'] ?? null;

            return [
                // 'id' kept for backward compatibility; 'product_id' matches AC8.
                'id' => $productId,
                'product_id' => $productId,
                'name' => $rec['name'] ?? $rec['product_name'] ?? null,
                'price' => isset($rec['price']) ? (float) $rec['price'] : null,
                'score' => isset($rec['score']) ? (float) $rec['score'] : null,
                'score_label' => $rec['score_label'] ?? null,
                'explanation' => $rec['explanation'] ?? null,
                'reasons' => $rec['reasons'] ?? [],
                // Per-item source (AC8): a single response can mix ML and
                // fallback items when ML returns fewer than requested, so
                // this is more precise than the batch-level meta.source.
                'source' => $rec['source'] ?? $source,
                'confidence_level' => $rec['confidence_level'] ?? null,
            ];
        }, $recommendations);

        return [
            'data' => $data,
            'meta' => [
                'source' => $source,
                // Story 8.1: active RecommendationStrategy (additive field).
                'algorithm' => $useCase->getAlgorithmName(),
                // Story 8.2: A/B arm ("A"/"B"), null when A/B is off or there is no subject (additive field).
                'ab_variant' => $assignment?->variant,
                'count' => count($data),
                'response_time_ms' => round($responseTime, 2),
                'generated_at' => date('c'),
            ],
        ];
    }

    /** @param array<string, mixed> $response */
    private function persistRecommendationSnapshot(string $sessionId, array $response): void
    {
        if ($this->sessions === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $scores = [];
        foreach ($data as $recommendation) {
            if (is_array($recommendation) && is_numeric($recommendation['score'] ?? null)) {
                $scores[] = (float) $recommendation['score'];
            }
        }

        $meta = is_array($response['meta'] ?? null) ? $response['meta'] : [];
        $productIds = [];
        foreach ($data as $recommendation) {
            $productId = is_array($recommendation) ? ($recommendation['product_id'] ?? null) : null;
            if (is_int($productId) || is_string($productId)) {
                $productIds[] = $productId;
            }
        }

        $current = [
            'source' => is_string($meta['source'] ?? null) ? $meta['source'] : 'unknown',
            'latency_ms' => is_numeric($meta['response_time_ms'] ?? null) ? (float) $meta['response_time_ms'] : 0.0,
            'avg_confidence' => $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 2),
            'count' => is_int($meta['count'] ?? null) ? $meta['count'] : count($data),
            'generated_at' => is_string($meta['generated_at'] ?? null) ? $meta['generated_at'] : date(DATE_ATOM),
            'product_ids' => $productIds,
        ];

        try {
            $this->persistSnapshotAtomically($sessionId, $current);
        } catch (\Throwable $exception) {
            $this->logger->error('Não foi possível persistir o snapshot de recomendação.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Story 10.5: per-session cold-start counter (`recommendation.cold_start`,
     * {requests, fallback_activated}) shown on /metrics Level 3. A response
     * counts as "fallback activated" when any item has a source other than
     * `ml` (same definition as the offline harness, Story 10.4); an empty
     * list only counts as a request. Never changes nor breaks the response.
     *
     * @param array<string, mixed> $response
     */
    private function recordColdStart(string $sessionId, array $response): void
    {
        if ($this->sessions === null) {
            return;
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $fallbackActivated = false;
        foreach ($data as $recommendation) {
            if (is_array($recommendation) && ($recommendation['source'] ?? null) !== 'ml') {
                $fallbackActivated = true;

                break;
            }
        }

        try {
            for ($attempt = 0; $attempt < self::SNAPSHOT_PERSISTENCE_ATTEMPTS; ++$attempt) {
                $existing = $this->sessions->get($sessionId, 'recommendation.cold_start');
                $counter = $this->validColdStartCounter($existing) ?? ['requests' => 0, 'fallback_activated' => 0];
                $updated = [
                    'requests' => $counter['requests'] + 1,
                    'fallback_activated' => $counter['fallback_activated'] + ($fallbackActivated ? 1 : 0),
                ];

                if ($this->sessions->compareAndSwap($sessionId, 'recommendation.cold_start', $existing, $updated)) {
                    return;
                }
            }

            throw new \RuntimeException('O contador de cold-start foi alterado concorrentemente.');
        } catch (\Throwable $exception) {
            $this->logger->error('Não foi possível registrar a taxa de cold-start da sessão.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * A malformed stored counter restarts from zero instead of being carried over.
     *
     * @return array{requests: int, fallback_activated: int}|null
     */
    private function validColdStartCounter(mixed $counter): ?array
    {
        if (! is_array($counter)) {
            return null;
        }
        $requests = $counter['requests'] ?? null;
        $activated = $counter['fallback_activated'] ?? null;
        if (! is_int($requests) || ! is_int($activated) || $requests < 0 || $activated < 0 || $activated > $requests) {
            return null;
        }

        return ['requests' => $requests, 'fallback_activated' => $activated];
    }

    /** @param array<string, mixed> $current */
    private function persistSnapshotAtomically(string $sessionId, array $current): void
    {
        for ($attempt = 0; $attempt < self::SNAPSHOT_PERSISTENCE_ATTEMPTS; ++$attempt) {
            $existing = $this->sessions->get($sessionId, 'recommendation.snapshot');
            $previous = $this->comparableSnapshotSide($existing);
            $snapshot = ['current' => $current];
            if ($previous !== null) {
                $snapshot['previous'] = $previous;
            }

            if ($this->sessions->compareAndSwap($sessionId, 'recommendation.snapshot', $existing, $snapshot)) {
                return;
            }
        }

        throw new \RuntimeException('O snapshot de recomendação foi alterado concorrentemente.');
    }

    /** @return array<string, mixed>|null */
    private function comparableSnapshotSide(mixed $snapshot): ?array
    {
        $current = is_array($snapshot) ? ($snapshot['current'] ?? null) : null;

        if (! is_array($current) || ! is_array($current['product_ids'] ?? null) || ! array_is_list($current['product_ids'])) {
            return null;
        }

        foreach ($current['product_ids'] as $productId) {
            if (! is_int($productId) && ! is_string($productId)) {
                return null;
            }
        }

        return $current;
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
            return getallheaders();
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
