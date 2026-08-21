<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RedisEventStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use UnexpectedValueException;

#[Group('redis')]
final class RedisEventStoreTest extends TestCase
{
    private Client $client;

    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        /** @var array{host: string, port: int} $config */
        $config = require dirname(__DIR__, 4) . '/config/redis.php';
        $this->client = new Client(['scheme' => 'tcp', ...$config]);
    }

    protected function tearDown(): void
    {
        try {
            $this->client->del($this->keys);
        } catch (\Throwable) {
            // Preserva a falha principal da integração Redis.
        }
    }

    public function test_it_recovers_events_in_consumption_order(): void
    {
        $event = $this->eventName();
        $store = new RedisEventStore($this->client);
        $this->track($event);
        $first = ['event' => $event, 'data' => ['product_id' => 1], 'timestamp' => '2026-08-21T12:00:00+00:00'];
        $second = ['event' => $event, 'data' => ['product_id' => 2], 'timestamp' => '2026-08-21T12:00:01+00:00'];

        $store->append($first);
        $store->append($second);

        self::assertSame([$first, $second], $store->getByEvent($event));
    }

    public function test_it_rejects_a_corrupted_persisted_record(): void
    {
        $event = $this->eventName();
        $this->track($event);
        $this->client->rpush('ec-hub:event-store:' . $event, '{invalid');
        $store = new RedisEventStore($this->client);

        $this->expectException(UnexpectedValueException::class);
        $store->getByEvent($event);
    }

    public function test_it_rejects_a_valid_json_record_with_an_invalid_envelope(): void
    {
        $event = $this->eventName();
        $this->track($event);
        $this->client->rpush('ec-hub:event-store:' . $event, json_encode([
            'event' => $event,
            'data' => ['product_id' => 42],
            'timestamp' => '2026-02-30T12:00:00+00:00',
        ], JSON_THROW_ON_ERROR));
        $store = new RedisEventStore($this->client);

        $this->expectException(UnexpectedValueException::class);
        $store->getByEvent($event);
    }

    public function test_it_rejects_a_non_utc_timestamp(): void
    {
        $event = $this->eventName();
        $this->track($event);
        $store = new RedisEventStore($this->client);

        $this->expectException(\InvalidArgumentException::class);
        $store->append([
            'event' => $event,
            'data' => ['product_id' => 42],
            'timestamp' => '2026-08-21T13:00:00+01:00',
        ]);
    }

    private function track(string $event): void
    {
        $this->keys[] = 'ec-hub:event-store:' . $event;
    }

    private function eventName(): string
    {
        return 'test' . bin2hex(random_bytes(6)) . '.viewed' . bin2hex(random_bytes(6));
    }
}
