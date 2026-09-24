<?php

declare(strict_types=1);

namespace App\Shared\Http;

/**
 * Stateless CSRF token for the visitor forms (Story 8.6, cart): an HMAC of
 * the signed visitor session id, like AdminAuth::loginCsrfToken. Nothing is
 * stored; a forged or foreign token never matches.
 */
final class SessionCsrf
{
    public function __construct(private readonly string $secret)
    {
    }

    public function token(string $sessionId): string
    {
        return hash_hmac('sha256', 'cart-csrf|' . $sessionId, $this->secret);
    }

    public function isValid(mixed $candidate, ?string $sessionId): bool
    {
        return $sessionId !== null
            && is_string($candidate)
            && hash_equals($this->token($sessionId), $candidate);
    }
}
