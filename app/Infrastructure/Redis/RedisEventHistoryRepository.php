<?php

declare(strict_types=1);

namespace App\Infrastructure\Redis;

use App\Domain\Event\EventHistoryRepositoryInterface;
use InvalidArgumentException;
use JsonException;
use Predis\ClientInterface;
use Predis\Transaction\MultiExec;
use UnexpectedValueException;

final class RedisEventHistoryRepository implements EventHistoryRepositoryInterface
{
    private const LIMIT = 50;
    private const PREFIX = 'ec-hub:event-history:';
    private const MAX_TTL = 2147483647;

    public function __construct(private readonly ClientInterface $client, private readonly int $ttl)
    {
        if ($ttl < 1 || $ttl > self::MAX_TTL) {
            throw new InvalidArgumentException('SESSION_TTL must be an integer between 1 and 2147483647.');
        }
    }

    public function append(string $sessionId, ?string $userId, array $event): void
    {
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        $keys = [$this->key('session', $sessionId)];
        if ($userId !== null && trim($userId) !== '') {
            $keys[] = $this->key('user', $userId);
        }

        foreach ($keys as $key) {
            $this->client->transaction(function (MultiExec $transaction) use ($key, $encoded): void {
                $transaction->rpush($key, [$encoded]);
                $transaction->ltrim($key, -self::LIMIT, -1);
                $transaction->expire($key, $this->ttl);
            });
        }
    }

    public function getBySession(string $sessionId): array
    {
        return $this->read($this->key('session', $sessionId));
    }

    public function getByUserId(string $userId): array
    {
        return $this->read($this->key('user', $userId));
    }

    private function key(string $index, string $identifier): string
    {
        if (trim($identifier) === '') {
            throw new InvalidArgumentException('Event history identifier must not be empty.');
        }

        return self::PREFIX . $index . ':' . $identifier;
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
