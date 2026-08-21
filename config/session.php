<?php

declare(strict_types=1);

/**
 * Session retention settings. This file intentionally creates no client.
 *
 * @return array{ttl: int}
 */
$rawTtl = getenv('SESSION_TTL');
$ttl = $rawTtl === false || $rawTtl === '' ? 1800 : filter_var($rawTtl, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

if ($ttl === false) {
    throw new InvalidArgumentException('SESSION_TTL must be a positive integer.');
}

return ['ttl' => $ttl];
