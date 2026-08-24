<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Closure;

/** Mantém o identificador opaco da sessão exclusivamente no cookie HTTP. */
final class SessionContext
{
    public const COOKIE_NAME = 'ec_hub_session_id';
    public const SIGNATURE_COOKIE_NAME = 'ec_hub_session_signature';

    private const HEX64_PATTERN = '/^[a-f0-9]{64}\z/';
    private const MINIMUM_SECRET_LENGTH = 32;

    private ?string $sessionId = null;

    /** @var Closure(string, string, array<string, mixed>): bool */
    private Closure $cookieEmitter;

    /** @param null|Closure(string, string, array<string, mixed>): bool $cookieEmitter */
    public function __construct(private readonly string $cookieSecret, ?Closure $cookieEmitter = null)
    {
        if (strlen($this->cookieSecret) < self::MINIMUM_SECRET_LENGTH) {
            throw new \InvalidArgumentException('SESSION_COOKIE_SECRET must contain at least 32 characters.');
        }

        $this->cookieEmitter = $cookieEmitter ?? static fn (string $name, string $value, array $options): bool =>
            setcookie($name, $value, $options);
    }

    public function id(): string
    {
        if ($this->sessionId !== null) {
            return $this->sessionId;
        }

        $candidate = $_COOKIE[self::COOKIE_NAME] ?? null;
        $signature = $_COOKIE[self::SIGNATURE_COOKIE_NAME] ?? null;
        if ($this->isValidSessionPair($candidate, $signature)) {
            return $this->sessionId = $candidate;
        }

        $sessionId = bin2hex(random_bytes(32));
        $idEmitted = $this->emitCookie(self::COOKIE_NAME, $sessionId);
        $signatureEmitted = $this->emitCookie(self::SIGNATURE_COOKIE_NAME, $this->sign($sessionId));

        // O par precisa ser emitido atomicamente (spec): se qualquer metade falhar
        // (ex. headers já enviados), servir a sessão mesmo assim deixaria um ID sem
        // assinatura reconhecível, então a falha é explícita em vez de silenciosa.
        if (! $idEmitted || ! $signatureEmitted) {
            throw new \RuntimeException('Não foi possível emitir o par de cookies de sessão.');
        }

        return $this->sessionId = $sessionId;
    }

    private function isValidSessionPair(mixed $candidate, mixed $signature): bool
    {
        if (! is_string($candidate) || preg_match(self::HEX64_PATTERN, $candidate) !== 1) {
            return false;
        }

        if (! is_string($signature) || preg_match(self::HEX64_PATTERN, $signature) !== 1) {
            return false;
        }

        return hash_equals($this->sign($candidate), $signature);
    }

    private function sign(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, $this->cookieSecret);
    }

    private function emitCookie(string $name, string $value): bool
    {
        return ($this->cookieEmitter)($name, $value, [
            'expires' => 0,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
