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

        $recommendationPair = $this->recommendationPair($sessionId);

        return $this->twig->render('metrics/history.html.twig', [
            'events' => $history,
            'total' => count($history),
            'recommendation' => $this->levelOneSnapshot($recommendationPair['current']),
            'viewed_products' => $this->viewedProducts($history),
            'recommendation_comparison' => $this->recommendationComparison($recommendationPair),
        ]);
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
