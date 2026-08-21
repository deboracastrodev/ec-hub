<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

use App\Domain\Event\EventPublisherInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use Predis\ClientInterface;

final class RedisEventBus implements EventPublisherInterface
{
    private const JSON_MAX_DEPTH = 100;

    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function publish(string $event, mixed $data): void
    {
        $this->assertEventName($event);
        $this->assertJsonData($data);

        $envelope = [
            'event' => $event,
            'data' => $data,
            'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM),
        ];

        try {
            $payload = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION, self::JSON_MAX_DEPTH);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Event data cannot be encoded as JSON.', 0, $exception);
        }

        $this->client->publish("events:{$event}", $payload);
    }

    public static function assertEventName(string $event): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/', $event) !== 1) {
            throw new InvalidArgumentException('Event name must use the noun.verb format.');
        }
    }

    public static function assertJsonData(mixed $data, int $depth = 0): void
    {
        if ($depth >= self::JSON_MAX_DEPTH) {
            throw new InvalidArgumentException('Event data exceeds the maximum JSON nesting depth.');
        }

        if ($data === null) {
            throw new InvalidArgumentException('Event data must not contain null.');
        }

        if (is_string($data) || is_int($data) || is_bool($data)) {
            return;
        }

        if (is_float($data)) {
            if (! is_finite($data)) {
                throw new InvalidArgumentException('Event data floats must be finite.');
            }

            return;
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Event data must contain only native JSON values.');
        }

        foreach ($data as $item) {
            self::assertJsonData($item, $depth + 1);
        }
    }
}
