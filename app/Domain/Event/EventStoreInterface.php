<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Mantém envelopes de eventos para consulta posterior ao transporte.
 */
interface EventStoreInterface
{
    /**
     * @param array{event: string, data: string|int|float|bool|array<string|int, mixed>, timestamp: string} $envelope
     */
    public function append(array $envelope): void;

    /**
     * @return list<array{event: string, data: string|int|float|bool|array<string|int, mixed>, timestamp: string}>
     */
    public function getByEvent(string $event): array;
}
