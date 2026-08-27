<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Estado observável do barramento de eventos, medido em tempo real (Story 5.4).
 */
final class EventBusStatus
{
    public function __construct(
        public readonly bool $connected,
        public readonly int $publishedCount,
    ) {
    }
}
