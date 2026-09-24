<?php

declare(strict_types=1);

namespace App\Shared\Http;

/** The single admin account (Story 8.3), read from config/admin.php. */
final class AdminCredentials
{
    private function __construct(
        public readonly string $username,
        public readonly string $passwordHash
    ) {
    }

    /** @param array<string, mixed> $config */
    public static function fromArray(array $config): self
    {
        $username = $config['username'] ?? '';
        $passwordHash = $config['password_hash'] ?? '';

        return new self(
            is_string($username) ? trim($username) : '',
            is_string($passwordHash) ? $passwordHash : ''
        );
    }

    /**
     * Fail-closed: without a username and a hash that password_get_info()
     * recognizes, the admin panel stays disabled (login always fails).
     */
    public function isConfigured(): bool
    {
        return $this->username !== '' && password_get_info($this->passwordHash)['algo'] !== null;
    }
}
