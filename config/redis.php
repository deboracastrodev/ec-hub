<?php

declare(strict_types=1);

/**
 * Redis connection settings. This file intentionally creates no client or
 * connection; infrastructure consumers construct Predis lazily when needed.
 *
 * @return array{host: string, port: int}
 */
$host = getenv('REDIS_HOST') ?: 'redis';
$rawPort = getenv('REDIS_PORT');
$port = $rawPort === false || $rawPort === '' ? 6379 : filter_var($rawPort, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);

if ($port === false) {
    throw new InvalidArgumentException('REDIS_PORT must be an integer between 1 and 65535.');
}

return [
    'host' => $host,
    'port' => $port,
];
