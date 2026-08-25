<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Event\EventHistoryRepositoryInterface;
use App\Domain\Session\Repository\SessionRepositoryInterface;
use Twig\Environment;

final class MetricsController
{
    public function __construct(
        private readonly EventHistoryRepositoryInterface $history,
        private readonly Environment $twig,
        private readonly ?SessionRepositoryInterface $sessions = null,
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

        $recommendation = $this->recommendationSnapshot($sessionId);

        return $this->twig->render('metrics/history.html.twig', [
            'events' => $history,
            'total' => count($history),
            'recommendation' => $recommendation['current'],
            'session' => [
                'viewed_products' => $this->viewedProducts($history),
                'recommendation_comparison' => $recommendation['comparison'],
            ],
        ]);
    }

    /**
     * @return array{current: array{source: string, latency_ms: float, avg_confidence: float, count: int, generated_at: string}|null, comparison: array{state: 'changed'|'unchanged'|'unavailable', current_product_ids: list<int|string>, previous_product_ids: list<int|string>}}
     */
    private function recommendationSnapshot(?string $sessionId): array
    {
        $unavailable = [
            'current' => null,
            'comparison' => ['state' => 'unavailable', 'current_product_ids' => [], 'previous_product_ids' => []],
        ];
        if ($sessionId === null || $this->sessions === null) {
            return $unavailable;
        }

        try {
            $snapshot = $this->sessions->get($sessionId, 'recommendation.snapshot');
        } catch (\Throwable) {
            return $unavailable;
        }

        // Story 5.2's plain snapshot remains usable by Nível 1, but has no
        // product_ids and therefore must never become a comparison baseline.
        $current = is_array($snapshot['current'] ?? null) ? $snapshot['current'] : $snapshot;
        $currentSide = $this->snapshotSide($current);
        if ($currentSide === null) {
            return $unavailable;
        }

        $result = ['current' => $currentSide, 'comparison' => $unavailable['comparison']];
        $previousSide = $this->snapshotSide($snapshot['previous'] ?? null, true);
        $currentProductIds = $this->productIds($current);
        if ($previousSide === null || $currentProductIds === null) {
            return $result;
        }

        $previousProductIds = $this->productIds($snapshot['previous']);
        if ($previousProductIds === null) {
            return $result;
        }

        $result['comparison'] = [
            'state' => $currentProductIds === $previousProductIds ? 'unchanged' : 'changed',
            'current_product_ids' => $currentProductIds,
            'previous_product_ids' => $previousProductIds,
        ];

        return $result;
    }

    /** @return array{source: string, latency_ms: float, avg_confidence: float, count: int, generated_at: string}|null */
    private function snapshotSide(mixed $snapshot, bool $requireProductIds = false): ?array
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

        if ($requireProductIds && $this->productIds($snapshot) === null) {
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

    /** @return list<int|string>|null */
    private function productIds(mixed $snapshot): ?array
    {
        if (! is_array($snapshot) || ! is_array($snapshot['product_ids'] ?? null) || ! array_is_list($snapshot['product_ids'])) {
            return null;
        }

        if (! array_all($snapshot['product_ids'], static fn (mixed $productId): bool => is_int($productId) || is_string($productId))) {
            return null;
        }

        return $snapshot['product_ids'];
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
