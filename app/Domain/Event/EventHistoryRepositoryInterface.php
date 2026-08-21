<?php

declare(strict_types=1);

namespace App\Domain\Event;

interface EventHistoryRepositoryInterface
{
    /** @param array<string, mixed> $event */
    public function append(string $sessionId, ?string $userId, array $event): void;

    /** @return list<array<string, mixed>> */
    public function getBySession(string $sessionId): array;

    /** @return list<array<string, mixed>> */
    public function getByUserId(string $userId): array;
}
