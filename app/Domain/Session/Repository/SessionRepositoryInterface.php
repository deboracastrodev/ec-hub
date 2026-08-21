<?php

declare(strict_types=1);

namespace App\Domain\Session\Repository;

/**
 * Armazena dados efêmeros associados a uma sessão compartilhada.
 */
interface SessionRepositoryInterface
{
    /**
     * @param string|int|float|bool|array<string|int, mixed> $value
     */
    public function save(string $sessionId, string $field, mixed $value): void;

    /**
     * @return string|int|float|bool|array<string|int, mixed>|null
     */
    public function get(string $sessionId, string $field): mixed;
}
