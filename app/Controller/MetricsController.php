<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Event\EventHistoryRepositoryInterface;
use Twig\Environment;

final class MetricsController
{
    public function __construct(
        private readonly EventHistoryRepositoryInterface $history,
        private readonly Environment $twig,
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
        ]);
    }

    /** @param array<string, mixed> $event */
    private function productId(array $event): int|string|null
    {
        $productId = $event['product_id'] ?? null;

        return is_int($productId) || is_string($productId) ? $productId : null;
    }
}
