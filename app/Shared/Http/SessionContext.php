<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Closure;

/** Mantém o identificador opaco da sessão exclusivamente no cookie HTTP. */
final class SessionContext
{
    public const COOKIE_NAME = 'ec_hub_session_id';

    private ?string $sessionId = null;

    /** @var Closure(string, string, array<string, mixed>): bool */
    private Closure $cookieEmitter;

    /** @param null|Closure(string, string, array<string, mixed>): bool $cookieEmitter */
    public function __construct(?Closure $cookieEmitter = null)
    {
        $this->cookieEmitter = $cookieEmitter ?? static fn (string $name, string $value, array $options): bool =>
            setcookie($name, $value, $options);
    }

    public function id(): string
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        $candidate = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (is_string($candidate) && preg_match('/^[a-f0-9]{64}$/', $candidate) === 1) {
            return $this->sessionId = $candidate;
        }

        $this->sessionId = bin2hex(random_bytes(32));
        ($this->cookieEmitter)(self::COOKIE_NAME, $this->sessionId, [
            'expires' => 0,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $this->sessionId;
    }
}
