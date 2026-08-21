<?php

declare(strict_types=1);

namespace App\Domain\Session\Repository;

/**
 * Porta para o estado efêmero associado a uma sessão compartilhada.
 */
interface SessionRepositoryInterface
{
    /**
     * Persiste um valor serializável em um campo literal da sessão.
     */
    public function put(string $sessionId, string $field, mixed $value): void;

    /**
     * Retorna null quando o campo ou a sessão não existir (inclusive após expiração).
     */
    public function get(string $sessionId, string $field): mixed;
}
