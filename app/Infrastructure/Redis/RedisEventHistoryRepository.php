<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Event\EventHistoryRepositoryInterface;
use JsonException;
use Predis\ClientInterface;
use UnexpectedValueException;

final class RedisEventHistoryRepository implements EventHistoryRepositoryInterface
{
    private const LIMIT = 50;
    private const PREFIX = 'ec-hub:event-history:';

    public function __construct(private readonly ClientInterface $client, private readonly int $ttl)
    {
    }

    public function append(string $sessionId, ?string $userId, array $event): void
    {
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $keys = [self::PREFIX . 'session:' . $sessionId];
        if ($userId !== null && $userId !== '') {
            $keys[] = self::PREFIX . 'user:' . $userId;
        }
        foreach ($keys as $key) {
            $this->client->rpush($key, [$encoded]);
            $this->client->ltrim($key, -self::LIMIT, -1);
            $this->client->expire($key, $this->ttl);
        }
    }

    public function getBySession(string $sessionId): array
    {
        return $this->read(self::PREFIX . 'session:' . $sessionId);
    }

    public function getByUserId(string $userId): array
    {
        return $this->read(self::PREFIX . 'user:' . $userId);
    }

    /** @return list<array<string, mixed>> */
    private function read(string $key): array
    {
        $events = [];
        foreach ($this->client->lrange($key, 0, -1) as $encoded) {
            try {
                $event = json_decode($encoded, true, 100, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new UnexpectedValueException('Stored event history is not valid JSON.', 0, $exception);
            }
            if (! is_array($event)) {
                throw new UnexpectedValueException('Stored event history must contain objects.');
            }
            $events[] = $event;
        }

        return $events;
    }
}
