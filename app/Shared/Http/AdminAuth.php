<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Closure;

/**
 * Stateless single-admin session (Story 8.3).
 *
 * The cookie is "{expiresAt}.{hmac}", signed with SESSION_COOKIE_SECRET over
 * the username and the password hash: changing either invalidates every
 * issued session. Nothing is stored server side, so a session cannot be
 * revoked before it expires (except by changing the password or the secret).
 *
 * CSRF is stateless too: authenticated forms carry an HMAC of the admin
 * cookie; the login form carries an HMAC of the visitor session id
 * (SessionContext), since there is no admin cookie yet.
 */
final class AdminAuth
{
    public const COOKIE_NAME = 'ec_hub_admin';
    public const TTL_SECONDS = 7200;
    public const COOKIE_PATH = '/admin';

    private const TOKEN_PATTERN = '/^\d{1,12}\.[a-f0-9]{64}\z/';
    private const MINIMUM_SECRET_LENGTH = 32;

    /** @var Closure(string, string, array<string, mixed>): bool */
    private Closure $cookieEmitter;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param null|Closure(string, string, array<string, mixed>): bool $cookieEmitter
     * @param null|Closure(): int $clock Current Unix timestamp
     */
    public function __construct(
        private readonly AdminCredentials $credentials,
        private readonly string $secret,
        ?Closure $cookieEmitter = null,
        ?Closure $clock = null
    ) {
        if (strlen($this->secret) < self::MINIMUM_SECRET_LENGTH) {
            throw new \InvalidArgumentException('SESSION_COOKIE_SECRET must contain at least 32 characters.');
        }

        $this->cookieEmitter = $cookieEmitter ?? static fn (string $name, string $value, array $options): bool =>
            setcookie($name, $value, $options);
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function isConfigured(): bool
    {
        return $this->credentials->isConfigured();
    }

    /**
     * Checks the credentials and, on success, issues the admin cookie.
     *
     * @throws \RuntimeException when the credentials are valid but the cookie
     *                           cannot be emitted (e.g. headers already sent)
     */
    public function attempt(mixed $username, mixed $password): bool
    {
        if (! $this->isConfigured() || ! is_string($username) || ! is_string($password)) {
            return false;
        }

        // Both checks always run, so the response time does not reveal
        // which of the two fields was wrong.
        $usernameMatches = hash_equals($this->credentials->username, trim($username));
        $passwordMatches = password_verify($password, $this->credentials->passwordHash);
        if (! $usernameMatches || ! $passwordMatches) {
            return false;
        }

        $expiresAt = ($this->clock)() + self::TTL_SECONDS;
        $token = $expiresAt . '.' . $this->sign((string) $expiresAt);
        // Like SessionContext: a login that cannot set its cookie must fail
        // loudly, not look like a wrong password.
        if (! $this->emitCookie($token, $expiresAt)) {
            throw new \RuntimeException('Não foi possível emitir o cookie da sessão admin.');
        }

        $_COOKIE[self::COOKIE_NAME] = $token;

        return true;
    }

    public function isAuthenticated(): bool
    {
        return $this->currentToken() !== null;
    }

    /** @throws \RuntimeException when the expired cookie cannot be emitted */
    public function logout(): void
    {
        if (! $this->emitCookie('', 1)) {
            throw new \RuntimeException('Não foi possível remover o cookie da sessão admin.');
        }
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /** CSRF token for the authenticated forms; '' when not authenticated. */
    public function csrfToken(): string
    {
        $token = $this->currentToken();

        return $token === null ? '' : hash_hmac('sha256', 'admin-csrf|' . $token, $this->secret);
    }

    public function isValidCsrfToken(mixed $candidate): bool
    {
        $expected = $this->csrfToken();

        return $expected !== '' && is_string($candidate) && hash_equals($expected, $candidate);
    }

    /** CSRF token for the login form, bound to the visitor session id. */
    public function loginCsrfToken(string $sessionId): string
    {
        return hash_hmac('sha256', 'admin-login-csrf|' . $sessionId, $this->secret);
    }

    public function isValidLoginCsrfToken(mixed $candidate, string $sessionId): bool
    {
        return is_string($candidate) && hash_equals($this->loginCsrfToken($sessionId), $candidate);
    }

    /** The current cookie value when it is a valid, unexpired admin session. */
    private function currentToken(): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (! is_string($token) || preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            return null;
        }

        [$expiresAt, $signature] = explode('.', $token, 2);
        if ((int) $expiresAt <= ($this->clock)()) {
            return null;
        }

        return hash_equals($this->sign($expiresAt), $signature) ? $token : null;
    }

    private function sign(string $expiresAt): string
    {
        return hash_hmac(
            'sha256',
            'admin-session|' . $this->credentials->username . '|' . $this->credentials->passwordHash . '|' . $expiresAt,
            $this->secret
        );
    }

    private function emitCookie(string $value, int $expires): bool
    {
        return ($this->cookieEmitter)(self::COOKIE_NAME, $value, [
            'expires' => $expires,
            'path' => self::COOKIE_PATH,
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
