<?php

declare(strict_types=1);

/**
 * Session retention settings. Loading this configuration creates no client.
 *
 * @return array{ttl: int}
 */
$rawTtl = getenv('SESSION_TTL');
$ttl = $rawTtl === false ? 1800 : filter_var($rawTtl, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);

if ($ttl === false) {
    throw new InvalidArgumentException('SESSION_TTL must be an integer between 1 and 2147483647.');
}

return ['ttl' => $ttl];
