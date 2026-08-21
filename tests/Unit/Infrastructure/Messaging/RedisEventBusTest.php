<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Messaging;

use App\Domain\Event\EventPublisherInterface;
use App\Infrastructure\Messaging\RedisEventBus;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Predis\ClientInterface;

final class RedisEventBusTest extends TestCase
{
    public function test_it_publishes_the_exact_event_envelope_to_its_channel(): void
    {
        $client = new RecordingClient();
        $bus = new RedisEventBus($client);
        $beforePublication = time();

        $bus->publish('product.viewed', ['product_id' => 42, 'score' => 1.0]);
        $afterPublication = time();

        self::assertSame('publish', $client->method);
        self::assertSame('events:product.viewed', $client->arguments[0]);
        $envelope = json_decode($client->arguments[1], true, 100, JSON_THROW_ON_ERROR);
        self::assertSame(['event', 'data', 'timestamp'], array_keys($envelope));
        self::assertSame('product.viewed', $envelope['event']);
        self::assertSame(['product_id' => 42, 'score' => 1.0], $envelope['data']);
        self::assertStringEndsWith('+00:00', $envelope['timestamp']);
        $publishedAt = new \DateTimeImmutable($envelope['timestamp']);
        self::assertGreaterThanOrEqual($beforePublication, $publishedAt->getTimestamp());
        self::assertLessThanOrEqual($afterPublication, $publishedAt->getTimestamp());
    }

    public function test_it_rejects_invalid_event_names_before_publishing(): void
    {
        foreach (['product', 'product.', '.viewed', 'product.viewed.more', 'Product.viewed', 'product viewed'] as $event) {
            $client = new RecordingClient();

            try {
                (new RedisEventBus($client))->publish($event, ['product_id' => 42]);
                self::fail("Nome inválido {$event} deve ser rejeitado.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            self::assertNull($client->method);
        }
    }

    public function test_it_rejects_non_json_data_before_publishing(): void
    {
        foreach ([null, new \stdClass(), INF, ['nested' => null], ['resource' => fopen('php://memory', 'r')]] as $data) {
            $client = new RecordingClient();

            try {
                (new RedisEventBus($client))->publish('product.viewed', $data);
                self::fail('Dados inválidos devem ser rejeitados antes da publicação.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                if (is_array($data) && is_resource($data['resource'] ?? null)) {
                    fclose($data['resource']);
                }
            }

            self::assertNull($client->method);
        }
    }

    public function test_it_rejects_malformed_utf8_before_publishing(): void
    {
        $client = new RecordingClient();

        $this->expectException(InvalidArgumentException::class);

        try {
            (new RedisEventBus($client))->publish('product.viewed', "\xB1\x31");
        } finally {
            self::assertNull($client->method);
        }
    }

    public function test_bootstrap_binds_the_domain_publisher_contract_without_connecting(): void
    {
        $container = require dirname(__DIR__, 4) . '/config/bootstrap.php';

        self::assertInstanceOf(RedisEventBus::class, $container->get(EventPublisherInterface::class));
    }
}

final class RecordingClient implements ClientInterface
{
    public ?string $method = null;

    /** @var array<int, mixed> */
    public array $arguments = [];

    public function getCommandFactory(): mixed
    {
        return null;
    }

    public function getOptions(): mixed
    {
        return null;
    }

    public function connect(): void
    {
    }

    public function disconnect(): void
    {
    }

    public function getConnection(): mixed
    {
        return null;
    }

    public function createCommand($method, $arguments = []): mixed
    {
        return null;
    }

    public function executeCommand(\Predis\Command\CommandInterface $command): mixed
    {
        return null;
    }

    public function __call($method, $arguments): mixed
    {
        $this->method = $method;
        $this->arguments = $arguments;

        return null;
    }
}
