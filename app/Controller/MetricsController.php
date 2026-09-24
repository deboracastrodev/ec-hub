<?php

declare(strict_types=1);

namespace App\Controller;

use App\Application\Recommendation\Evaluation\PublishedQualityMetrics;
use App\Domain\Event\EventBusStatusInterface;
use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use Closure;
use Throwable;
use Twig\Environment;

final class MetricsController
{
    /** @var list<array{label: string, story: string}> */
    private const ARCHITECTURE_SIGNALS_NOT_YET_AVAILABLE = [
        ['label' => 'Modelo KNN em cache (treinado há Xs)', 'story' => 'Epic 10 / Story 10.1'],
        ['label' => 'Memory (uso / growth %)', 'story' => 'Story 5.6'],
        ['label' => 'KNN Confidence Score (global do modelo)', 'story' => 'Epic 10'],
    ];

    public function __construct(
        private readonly EventHistoryRepositoryInterface $history,
        private readonly Environment $twig,
        private readonly ?SessionRepositoryInterface $sessions = null,
        private readonly ?EventBusStatusInterface $eventBusStatus = null,
        /**
         * Story 8.2: lazy A/B results (RecommendationExperiment::results()).
         * Lazy so an invalid RECOMMENDATION_AB_TEST or Redis being down only
         * degrades the comparison panel, never the /metrics route.
         *
         * @var (Closure(): array<string, mixed>)|null
         */
        private readonly ?Closure $abTestResults = null,
        /**
         * Story 10.5: offline quality numbers from the committed `make eval`
         * report, read on every request. An exception or null degrades to
         * "indisponível (rode make eval)".
         *
         * @var (Closure(): ?PublishedQualityMetrics)|null
         */
        private readonly ?Closure $qualityMetrics = null,
    ) {
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, mixed> $headers
     */
    public function index(array $queryParams, array $headers, ?string $sessionId): string
    {
        $events = $sessionId === null ? [] : $this->history->getBySession($sessionId);
        $history = [];

        foreach ($events as $position => $event) {
            $history[] = [
                'event' => is_string($event['event'] ?? null) ? $event['event'] : 'Evento desconhecido',
                'timestamp' => is_string($event['timestamp'] ?? null) ? $event['timestamp'] : '',
                'product_id' => $this->productId($event),
                'position' => $position,
            ];
        }

        usort($history, static function (array $left, array $right): int {
            $timestampOrder = $right['timestamp'] <=> $left['timestamp'];

            return $timestampOrder !== 0 ? $timestampOrder : $left['position'] <=> $right['position'];
        });

        foreach ($history as &$event) {
            unset($event['position']);
        }
        unset($event);

        $recommendationPair = $this->recommendationPair($sessionId);

        return $this->twig->render('metrics/history.html.twig', [
            'events' => $history,
            'total' => count($history),
            'recommendation' => $this->levelOneSnapshot($recommendationPair['current']),
            'viewed_products' => $this->viewedProducts($history),
            'recommendation_comparison' => $this->recommendationComparison($recommendationPair),
            'pubsub' => $this->pubSubStatus(),
            'architecture_signals' => self::ARCHITECTURE_SIGNALS_NOT_YET_AVAILABLE,
            'ab_test' => $this->abTestResults(),
            'quality' => $this->qualityMetrics(),
            'session_cold_start' => $this->sessionColdStart($sessionId),
        ]);
    }

    private function qualityMetrics(): ?PublishedQualityMetrics
    {
        if ($this->qualityMetrics === null) {
            return null;
        }

        try {
            $metrics = ($this->qualityMetrics)();
        } catch (Throwable) {
            return null;
        }

        return $metrics instanceof PublishedQualityMetrics ? $metrics : null;
    }

    /**
     * Story 10.5: session cold-start rate, from the counter the
     * RecommendationController keeps in `recommendation.cold_start`.
     *
     * @return array{state: 'unavailable'|'empty'|'measured', requests: int, fallback_activated: int, rate: float}
     */
    private function sessionColdStart(?string $sessionId): array
    {
        $unavailable = ['state' => 'unavailable', 'requests' => 0, 'fallback_activated' => 0, 'rate' => 0.0];
        if ($sessionId === null || $this->sessions === null) {
            return $unavailable;
        }

        try {
            $counter = $this->sessions->get($sessionId, 'recommendation.cold_start');
        } catch (Throwable) {
            return $unavailable;
        }

        if ($counter === null) {
            return ['state' => 'empty', 'requests' => 0, 'fallback_activated' => 0, 'rate' => 0.0];
        }

        $requests = is_array($counter) ? ($counter['requests'] ?? null) : null;
        $activated = is_array($counter) ? ($counter['fallback_activated'] ?? null) : null;
        if (! is_int($requests) || ! is_int($activated) || $requests < 0 || $activated < 0 || $activated > $requests) {
            return $unavailable;
        }

        if ($requests === 0) {
            return ['state' => 'empty', 'requests' => 0, 'fallback_activated' => 0, 'rate' => 0.0];
        }

        return [
            'state' => 'measured',
            'requests' => $requests,
            'fallback_activated' => $activated,
            'rate' => $activated / $requests * 100,
        ];
    }

    private const AB_TEST_ROW_KEYS = [
        'algorithm',
        'variant',
        'requests',
        'unique_subjects',
        'total_items',
        'ml_items',
        'ml_item_rate',
        'avg_response_time_ms',
        'avg_score',
    ];

    /** @return array<string, mixed>|null null renders "Comparação indisponível" */
    private function abTestResults(): ?array
    {
        if ($this->abTestResults === null) {
            return null;
        }

        try {
            $results = ($this->abTestResults)();
        } catch (Throwable) {
            return null;
        }

        // Twig runs with strict_variables: a malformed result must degrade to
        // "indisponível" instead of breaking the whole /metrics page.
        return $this->isValidAbTestResults($results) ? $results : null;
    }

    /** @param array<mixed> $results */
    private function isValidAbTestResults(array $results): bool
    {
        if (! is_bool($results['enabled'] ?? null)) {
            return false;
        }

        $variants = $results['variants'] ?? null;
        if ($variants !== null
            && (! is_array($variants) || ! is_string($variants['A'] ?? null) || ! is_string($variants['B'] ?? null))
        ) {
            return false;
        }
        if ($results['enabled'] !== ($variants !== null)) {
            return false;
        }

        $algorithms = $results['algorithms'] ?? null;
        if (! is_array($algorithms) || ! array_is_list($algorithms)) {
            return false;
        }
        foreach ($algorithms as $row) {
            if (! is_array($row)) {
                return false;
            }
            foreach (self::AB_TEST_ROW_KEYS as $key) {
                if (! array_key_exists($key, $row)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array{available: bool, connected: bool, published_count: int} */
    private function pubSubStatus(): array
    {
        if ($this->eventBusStatus === null) {
            return ['available' => false, 'connected' => false, 'published_count' => 0];
        }

        try {
            $status = $this->eventBusStatus->status();
        } catch (Throwable) {
            return ['available' => false, 'connected' => false, 'published_count' => 0];
        }

        return [
            'available' => true,
            'connected' => $status->connected,
            'published_count' => $status->publishedCount,
        ];
    }

    /** @return array{state: 'changed'|'unchanged'|'unavailable', current: list<int|string>, previous: list<int|string>} */
    private function recommendationComparison(array $snapshot): array
    {
        $current = $this->snapshotSide($snapshot['current']);
        $previous = $this->snapshotSide($snapshot['previous']);

        if ($current === null || $previous === null) {
            return ['state' => 'unavailable', 'current' => [], 'previous' => []];
        }

        return [
            'state' => $current['product_ids'] === $previous['product_ids'] ? 'unchanged' : 'changed',
            'current' => $current['product_ids'],
            'previous' => $previous['product_ids'],
        ];
    }

    /** @return array{current: mixed, previous: mixed} */
    private function recommendationPair(?string $sessionId): array
    {
        if ($sessionId === null || $this->sessions === null) {
            return ['current' => null, 'previous' => null];
        }

        try {
            $snapshot = $this->sessions->get($sessionId, 'recommendation.snapshot');
        } catch (\Throwable) {
            return ['current' => null, 'previous' => null];
        }

        if (! is_array($snapshot)) {
            return ['current' => null, 'previous' => null];
        }

        return [
            'current' => $snapshot['current'] ?? $snapshot,
            'previous' => $snapshot['previous'] ?? null,
        ];
    }

    /** @return array{source: string, latency_ms: float, avg_confidence: float, count: int, generated_at: string}|null */
    private function levelOneSnapshot(mixed $snapshot): ?array
    {
        if (! is_array($snapshot)
            || ! is_string($snapshot['source'] ?? null)
            || ! in_array($snapshot['source'], ['ml', 'rules', 'popular'], true)
            || ! is_numeric($snapshot['latency_ms'] ?? null)
            || ! is_finite((float) $snapshot['latency_ms'])
            || (float) $snapshot['latency_ms'] < 0
            || ! is_numeric($snapshot['avg_confidence'] ?? null)
            || ! is_finite((float) $snapshot['avg_confidence'])
            || (float) $snapshot['avg_confidence'] < 0
            || (float) $snapshot['avg_confidence'] > 100
            || ! is_int($snapshot['count'] ?? null)
            || $snapshot['count'] < 0
            || ! is_string($snapshot['generated_at'] ?? null)
        ) {
            return null;
        }

        return [
            'source' => $snapshot['source'],
            'latency_ms' => (float) $snapshot['latency_ms'],
            'avg_confidence' => (float) $snapshot['avg_confidence'],
            'count' => $snapshot['count'],
            'generated_at' => $snapshot['generated_at'],
        ];
    }

    /** @return array{source: string, latency_ms: float, avg_confidence: float, count: int, generated_at: string, product_ids: list<int|string>}|null */
    private function snapshotSide(mixed $snapshot): ?array
    {
        $levelOne = $this->levelOneSnapshot($snapshot);

        if ($levelOne === null || ! is_array($snapshot['product_ids'] ?? null) || ! array_is_list($snapshot['product_ids'])) {
            return null;
        }

        $productIds = [];
        foreach ($snapshot['product_ids'] as $productId) {
            if (! is_int($productId) && ! is_string($productId)) {
                return null;
            }
            $productIds[] = $productId;
        }

        return [...$levelOne, 'product_ids' => $productIds];
    }

    /** @param list<array{event: string, timestamp: string, product_id: int|string|null}> $history
     * @return list<int|string>
     */
    private function viewedProducts(array $history): array
    {
        $products = [];
        foreach ($history as $event) {
            if ($event['event'] === 'product.viewed' && $event['product_id'] !== null) {
                $products[] = $event['product_id'];
            }
        }

        return $products;
    }

    /** @param array<string, mixed> $event */
    private function productId(array $event): int|string|null
    {
        $productId = $event['product_id'] ?? null;

        return is_int($productId) || is_string($productId) ? $productId : null;
    }
}
