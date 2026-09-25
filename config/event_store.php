<?php

declare(strict_types=1);

/**
 * Retenção do event store (DW-13). Carregar esta configuração não cria cliente.
 *
 * EVENT_STORE_MAX_EVENTS_PER_LIST: quantos envelopes mais recentes cada lista de
 * evento guarda. Ausente ou vazio usa o padrão (5000).
 *
 * @return array{max_events_per_list: int}
 */
$rawMaxEvents = getenv('EVENT_STORE_MAX_EVENTS_PER_LIST');
$maxEvents = $rawMaxEvents === false || $rawMaxEvents === ''
    ? \App\Infrastructure\Messaging\RedisEventStore::DEFAULT_MAX_EVENTS_PER_LIST
    : filter_var($rawMaxEvents, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 2147483647],
    ]);

if ($maxEvents === false) {
    throw new InvalidArgumentException('EVENT_STORE_MAX_EVENTS_PER_LIST must be an integer between 1 and 2147483647.');
}

return ['max_events_per_list' => $maxEvents];
