<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Publica eventos de domínio sem revelar o transporte utilizado.
 */
interface EventPublisherInterface
{
    /**
     * @param string|int|float|bool|array<string|int, mixed> $data
     */
    public function publish(string $event, mixed $data): void;
}
