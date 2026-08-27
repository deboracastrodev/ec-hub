<?php

declare(strict_types=1);

namespace App\Domain\Event;

/**
 * Expõe o estado observável do barramento de eventos, sem revelar o transporte utilizado.
 */
interface EventBusStatusInterface
{
    public function status(): EventBusStatus;
}
