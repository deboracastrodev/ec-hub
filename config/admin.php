<?php

declare(strict_types=1);

/**
 * Single-admin credentials for /admin (Story 8.3). Never throws: an empty or
 * invalid pair just leaves the admin panel disabled (fail-closed, see
 * App\Shared\Http\AdminCredentials::isConfigured()), without affecting the
 * rest of the site.
 *
 * ADMIN_PASSWORD_HASH is a password_hash() output, never the plain password:
 *   php -r "echo password_hash('...', PASSWORD_DEFAULT), PHP_EOL;"
 *
 * @return array{username: string, password_hash: string}
 */
$username = getenv('ADMIN_USERNAME');
$passwordHash = getenv('ADMIN_PASSWORD_HASH');

return [
    'username' => is_string($username) ? $username : '',
    'password_hash' => is_string($passwordHash) ? $passwordHash : '',
];
