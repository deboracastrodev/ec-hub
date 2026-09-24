<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Event\EventStoreInterface;

/**
 * In-memory EventStoreInterface fake (Story 8.1): lets collaborative
 * filtering be exercised without Redis. Can be told to fail on reads to
 * simulate an unavailable store.
 */
final class InMemoryEventStore implements EventStoreInterface
{
    /** @var array<string, list<array{event: string, data: string|int|float|bool|array<string|int, mixed>, timestamp: string}>> */
    private array $envelopes = [];

    public function __construct(private readonly bool $failOnRead = false)
    {
    }

    public function append(array $envelope): void
    {
        $this->envelopes[$envelope['event']][] = $envelope;
    }

    public function getByEvent(string $event): array
    {
        if ($this->failOnRead) {
            throw new \RuntimeException('Event store indisponível.');
        }

        return $this->envelopes[$event] ?? [];
    }

    /** Convenience: append an interaction envelope as TrackProductInteraction does. */
    public function interaction(string $event, string $sessionId, int $productId): void
    {
        $timestamp = '2026-09-24T10:00:00+00:00';
        $this->append([
            'event' => $event,
            'data' => [
                'event' => $event,
                'session_id' => $sessionId,
                'product_id' => $productId,
                'timestamp' => $timestamp,
            ],
            'timestamp' => $timestamp,
        ]);
    }
}
