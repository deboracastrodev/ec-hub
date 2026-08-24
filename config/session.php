<?php

declare(strict_types=1);

/**
 * Session retention settings. Loading this configuration creates no client.
 *
 * @return array{ttl: int, cookie_secret: string}
 */
$rawTtl = getenv('SESSION_TTL');
$ttl = $rawTtl === false ? 1800 : filter_var($rawTtl, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 2147483647],
]);

if ($ttl === false) {
    throw new InvalidArgumentException('SESSION_TTL must be an integer between 1 and 2147483647.');
}

$cookieSecret = getenv('SESSION_COOKIE_SECRET');
if (! is_string($cookieSecret) || strlen($cookieSecret) < 32) {
    throw new InvalidArgumentException('SESSION_COOKIE_SECRET must contain at least 32 characters.');
}

return ['ttl' => $ttl, 'cookie_secret' => $cookieSecret];
