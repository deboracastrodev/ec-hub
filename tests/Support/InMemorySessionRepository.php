<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Session\Repository\SessionRepositoryInterface;

/**
 * Session fake with the Redis repository's semantics (Story 8.6): values go
 * through a JSON round trip and compareAndSwap compares the encoded JSON
 * (expected null = field absent). $casConflicts forces that many CAS misses,
 * $casFailWith makes only compareAndSwap throw, and $failWith every call.
 */
final class InMemorySessionRepository implements SessionRepositoryInterface
{
    /** @var array<string, string> */
    private array $values = [];

    public int $casConflicts = 0;

    public int $casCalls = 0;

    public int $writes = 0;

    public ?\Throwable $failWith = null;

    public ?\Throwable $casFailWith = null;

    public function save(string $sessionId, string $field, mixed $value): void
    {
        $this->guard();
        $this->values[$sessionId . ':' . $field] = $this->encode($value);
        $this->writes++;
    }

    public function get(string $sessionId, string $field): mixed
    {
        $this->guard();
        $encoded = $this->values[$sessionId . ':' . $field] ?? null;

        return $encoded === null ? null : json_decode($encoded, true, 100, JSON_THROW_ON_ERROR);
    }

    public function compareAndSwap(string $sessionId, string $field, mixed $expected, mixed $value): bool
    {
        $this->guard();
        $this->casCalls++;
        if ($this->casFailWith !== null) {
            throw $this->casFailWith;
        }
        if ($this->casConflicts > 0) {
            $this->casConflicts--;

            return false;
        }

        $key = $sessionId . ':' . $field;
        $current = $this->values[$key] ?? null;
        if ($expected === null ? $current !== null : $current !== $this->encode($expected)) {
            return false;
        }

        $this->values[$key] = $this->encode($value);
        $this->writes++;

        return true;
    }

    /** Stores a raw JSON value, e.g. a corrupted cart. */
    public function putRaw(string $sessionId, string $field, string $json): void
    {
        $this->values[$sessionId . ':' . $field] = $json;
    }

    public function raw(string $sessionId, string $field): ?string
    {
        return $this->values[$sessionId . ':' . $field] ?? null;
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function guard(): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }
}
