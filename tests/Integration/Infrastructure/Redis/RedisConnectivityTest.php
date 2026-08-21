<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class RedisConnectivityTest extends TestCase
{
    public function test_configuration_uses_defaults_without_connecting(): void
    {
        $previousHost = getenv('REDIS_HOST');
        $previousPort = getenv('REDIS_PORT');
        putenv('REDIS_HOST');
        putenv('REDIS_PORT');

        try {
            $config = require dirname(__DIR__, 4) . '/config/redis.php';
            self::assertSame(['host' => 'redis', 'port' => 6379], $config);
        } finally {
            putenv($previousHost === false ? 'REDIS_HOST' : "REDIS_HOST={$previousHost}");
            putenv($previousPort === false ? 'REDIS_PORT' : "REDIS_PORT={$previousPort}");
        }
    }

    public function test_configuration_rejects_an_invalid_port(): void
    {
        $previousPort = getenv('REDIS_PORT');
        foreach (['not-a-port', '0', '65536', '-1'] as $invalidPort) {
            putenv("REDIS_PORT={$invalidPort}");

            try {
                require dirname(__DIR__, 4) . '/config/redis.php';
                self::fail("REDIS_PORT={$invalidPort} deveria ser rejeitada.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                putenv($previousPort === false ? 'REDIS_PORT' : "REDIS_PORT={$previousPort}");
            }
        }
    }

    public function test_configuration_uses_environment_overrides(): void
    {
        $previousHost = getenv('REDIS_HOST');
        $previousPort = getenv('REDIS_PORT');
        putenv('REDIS_HOST=redis.internal');
        putenv('REDIS_PORT=6380');

        try {
            $config = require dirname(__DIR__, 4) . '/config/redis.php';
            self::assertSame(['host' => 'redis.internal', 'port' => 6380], $config);
        } finally {
            putenv($previousHost === false ? 'REDIS_HOST' : "REDIS_HOST={$previousHost}");
            putenv($previousPort === false ? 'REDIS_PORT' : "REDIS_PORT={$previousPort}");
        }
    }

    #[Group('redis')]
    public function test_predis_can_ping_and_read_and_write(): void
    {
        /** @var array{host: string, port: int} $config */
        $config = require dirname(__DIR__, 4) . '/config/redis.php';
        $client = new Client(['scheme' => 'tcp', 'host' => $config['host'], 'port' => $config['port']]);
        $key = 'ec-hub:redis-connectivity:' . bin2hex(random_bytes(8));
        $keyWritten = false;

        try {
            self::assertSame('PONG', $client->ping()->getPayload());
            $setResponse = $client->set($key, 'predis')->getPayload();
            $keyWritten = true;
            self::assertSame('OK', $setResponse);
            self::assertSame('predis', $client->get($key));
        } finally {
            if ($keyWritten) {
                try {
                    $client->del([$key]);
                } catch (\Throwable) {
                    // Preserve the primary connectivity/assertion failure.
                }
            }
        }
    }
}
