<?php

declare(strict_types=1);

namespace App\Shared\Http;

/** Immutable request snapshot handed to the admin controllers (Story 8.3). */
final class Request
{
    /**
     * @param list<string> $params Captured path segments, in order
     * @param array<array-key, mixed> $query $_GET
     * @param array<array-key, mixed> $body $_POST
     */
    public function __construct(
        public readonly string $method,
        public readonly array $params = [],
        public readonly array $query = [],
        public readonly array $body = []
    ) {
    }

    public function param(int $index): ?string
    {
        return $this->params[$index] ?? null;
    }

    public function query(string $key): mixed
    {
        return $this->query[$key] ?? null;
    }

    public function input(string $key): mixed
    {
        return $this->body[$key] ?? null;
    }
}
