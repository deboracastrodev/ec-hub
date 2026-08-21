<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Event\EventStoreInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Predis\ClientInterface;
use UnexpectedValueException;

final class RedisEventStore implements EventStoreInterface
{
    private const KEY_PREFIX = 'ec-hub:event-store:';
    private const JSON_MAX_DEPTH = 100;

    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function append(array $envelope): void
    {
        $this->assertEnvelope($envelope);

        try {
            $encoded = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, self::JSON_MAX_DEPTH);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Event envelope cannot be encoded as JSON.', 0, $exception);
        }

        $this->client->rpush($this->key($envelope['event']), [$encoded]);
    }

    public function getByEvent(string $event): array
    {
        RedisEventBus::assertEventName($event);
        $records = $this->client->lrange($this->key($event), 0, -1);
        $events = [];

        foreach ($records as $record) {
            try {
                $envelope = json_decode($record, true, self::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new UnexpectedValueException('Stored event envelope is not valid JSON.', 0, $exception);
            }

            try {
                if (! is_array($envelope)) {
                    throw new InvalidArgumentException('Event envelope must be an object.');
                }
                $this->assertEnvelope($envelope);
            } catch (InvalidArgumentException $exception) {
                throw new UnexpectedValueException('Stored event envelope violates the event contract.', 0, $exception);
            }

            $events[] = $envelope;
        }

        return $events;
    }

    private function key(string $event): string
    {
        return self::KEY_PREFIX . $event;
    }

    /** @param array<string, mixed> $envelope */
    private function assertEnvelope(array $envelope): void
    {
        if (count($envelope) !== 3 || ! isset($envelope['event'], $envelope['data'], $envelope['timestamp'])) {
            throw new InvalidArgumentException('Event envelope must contain exactly event, data, and timestamp.');
        }

        if (! is_string($envelope['event']) || ! is_string($envelope['timestamp'])) {
            throw new InvalidArgumentException('Event envelope fields event and timestamp must be strings.');
        }

        RedisEventBus::assertEventName($envelope['event']);
        RedisEventBus::assertJsonData($envelope['data']);

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $envelope['timestamp']) !== 1) {
            throw new InvalidArgumentException('Event timestamp must be a valid UTC date-time.');
        }

        try {
            $timestamp = new DateTimeImmutable($envelope['timestamp']);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Event timestamp must be a valid UTC date-time.', 0, $exception);
        }

        if ($timestamp->getOffset() !== 0 || $timestamp->format(DATE_ATOM) !== $envelope['timestamp']) {
            throw new InvalidArgumentException('Event timestamp must be the canonical UTC format emitted by the publisher.');
        }
    }
}
