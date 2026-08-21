<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Messaging;

use App\Infrastructure\Messaging\RedisEventBus;
use App\Infrastructure\Messaging\RedisEventStore;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

#[Group('redis')]
final class RedisEventPubSubTest extends TestCase
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

    public function test_a_once_subscriber_persists_an_event_published_by_a_separate_client(): void
    {
        $event = $this->eventName();
        $this->keys[] = 'ec-hub:event-store:' . $event;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/bin/consume-events.php', '--once', $event],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4)
        );
        self::assertIsResource($process);

        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $this->awaitReady($pipes[1], $pipes[2]);

            (new RedisEventBus($this->client))->publish($event, ['product_id' => 42, 'score' => 1.0]);
            $errors = '';
            self::assertSame(0, $this->awaitProcessExit($process, $pipes[2], $errors));
            $process = null;

            $events = (new RedisEventStore($this->client))->getByEvent($event);
            self::assertCount(1, $events);
            self::assertSame($event, $events[0]['event']);
            self::assertSame(['product_id' => 42, 'score' => 1.0], $events[0]['data']);
            self::assertStringEndsWith('+00:00', $events[0]['timestamp']);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }

    public function test_a_once_subscriber_does_not_persist_a_payload_for_a_different_event(): void
    {
        $event = $this->eventName();
        $this->keys[] = 'ec-hub:event-store:' . $event;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/bin/consume-events.php', '--once', $event],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4)
        );
        self::assertIsResource($process);

        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $this->awaitReady($pipes[1], $pipes[2]);

            $this->client->publish('events:' . $event, json_encode([
                'event' => $this->eventName(),
                'data' => ['product_id' => 42],
                'timestamp' => '2026-08-21T12:00:00+00:00',
            ], JSON_THROW_ON_ERROR));
            $errors = '';
            self::assertNotSame(0, $this->awaitProcessExit($process, $pipes[2], $errors));
            $process = null;
            self::assertStringContainsString(
                'Received event does not match the subscribed channel.',
                $errors
            );

            self::assertSame([], (new RedisEventStore($this->client))->getByEvent($event));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }

    public function test_a_once_subscriber_rejects_a_same_event_payload_with_an_invalid_envelope(): void
    {
        $event = $this->eventName();
        $this->keys[] = 'ec-hub:event-store:' . $event;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/bin/consume-events.php', '--once', $event],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4)
        );
        self::assertIsResource($process);

        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $this->awaitReady($pipes[1], $pipes[2]);

            $this->client->publish('events:' . $event, json_encode([
                'event' => $event,
                'data' => ['product_id' => 42],
            ], JSON_THROW_ON_ERROR));
            $errors = '';
            self::assertNotSame(0, $this->awaitProcessExit($process, $pipes[2], $errors));
            $process = null;
            self::assertStringContainsString(
                'Event envelope must contain exactly event, data, and timestamp.',
                $errors
            );

            self::assertSame([], (new RedisEventStore($this->client))->getByEvent($event));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }

    public function test_a_once_subscriber_rejects_malformed_json(): void
    {
        $this->assertInvalidPayloadIsRejected('{invalid', 'Received event envelope is not valid JSON.');
    }

    public function test_a_once_subscriber_rejects_a_non_object_json_payload(): void
    {
        $this->assertInvalidPayloadIsRejected('null', 'Received event envelope must be an object.');
    }

    public function test_the_cli_rejects_an_invalid_invocation_without_waiting_for_an_event(): void
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/bin/consume-events.php', '--once'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4)
        );
        self::assertIsResource($process);

        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $errors = '';

            self::assertSame(1, $this->awaitProcessExit($process, $pipes[2], $errors));
            $process = null;
            self::assertSame("Uso: php bin/consume-events.php --once noun.verb\n", $errors);
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }

    /** @param resource $stdout @param resource $stderr */
    private function awaitReady($stdout, $stderr): void
    {
        $deadline = microtime(true) + 5;
        $output = '';
        $errors = '';

        while (microtime(true) < $deadline) {
            $output .= stream_get_contents($stdout) ?: '';
            $errors .= stream_get_contents($stderr) ?: '';
            if (str_contains($output, "READY\n")) {
                return;
            }
            usleep(10_000);
        }

        self::fail('Subscriber não sinalizou prontidão. ' . trim($errors));
    }

    /** @param resource $process @param resource $stderr */
    private function awaitProcessExit($process, $stderr, string &$errors): int
    {
        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $errors .= stream_get_contents($stderr) ?: '';
            $status = proc_get_status($process);
            if (! $status['running']) {
                $errors .= stream_get_contents($stderr) ?: '';
                proc_close($process);

                return $status['exitcode'];
            }
            usleep(10_000);
        }

        self::fail('Subscriber não encerrou dentro do prazo. ' . trim($errors));
    }

    private function assertInvalidPayloadIsRejected(string $payload, string $expectedError): void
    {
        $event = $this->eventName();
        $this->keys[] = 'ec-hub:event-store:' . $event;
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/bin/consume-events.php', '--once', $event],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4)
        );
        self::assertIsResource($process);

        try {
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $this->awaitReady($pipes[1], $pipes[2]);

            $this->client->publish('events:' . $event, $payload);
            $errors = '';
            self::assertNotSame(0, $this->awaitProcessExit($process, $pipes[2], $errors));
            $process = null;
            self::assertStringContainsString($expectedError, $errors);
            self::assertSame([], (new RedisEventStore($this->client))->getByEvent($event));
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }
    }

    private function eventName(): string
    {
        return 'test' . bin2hex(random_bytes(6)) . '.viewed' . bin2hex(random_bytes(6));
    }
}
