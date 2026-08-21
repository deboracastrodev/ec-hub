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
        putenv('REDIS_PORT=not-a-port');

        try {
            $this->expectException(\InvalidArgumentException::class);
            require dirname(__DIR__, 4) . '/config/redis.php';
        } finally {
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

        try {
            self::assertSame('PONG', $client->ping()->getPayload());
            self::assertSame('OK', $client->set($key, 'predis')->getPayload());
            self::assertSame('predis', $client->get($key));
        } finally {
            $client->del([$key]);
        }
    }
}
