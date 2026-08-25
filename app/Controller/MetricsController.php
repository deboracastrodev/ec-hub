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

        return $this->twig->render('metrics/history.html.twig', [
            'events' => $history,
            'total' => count($history),
            'recommendation' => $this->recommendationSnapshot($sessionId),
        ]);
    }

    /** @return array{source: string, latency_ms: float, avg_confidence: float, count: int, generated_at: string}|null */
    private function recommendationSnapshot(?string $sessionId): ?array
    {
        if ($sessionId === null || $this->sessions === null) {
            return null;
        }

        try {
            $snapshot = $this->sessions->get($sessionId, 'recommendation.snapshot');
        } catch (\Throwable) {
            return null;
        }

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

    /** @param array<string, mixed> $event */
    private function productId(array $event): int|string|null
    {
        $productId = $event['product_id'] ?? null;

        return is_int($productId) || is_string($productId) ? $productId : null;
    }
}
