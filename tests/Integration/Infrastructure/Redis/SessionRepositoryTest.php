<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\SessionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

#[Group('redis')]
final class SessionRepositoryTest extends TestCase
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
        foreach ($this->keys as $key) {
            try {
                $this->client->del([$key]);
            } catch (\Throwable) {
                // Preserve the primary test failure when Redis is unavailable.
            }
        }
    }

    public function test_persists_literal_dot_notation_fields_and_renews_ttl(): void
    {
        [$repository, $sessionId, $key] = $this->repository(3);

        $repository->put($sessionId, 'cart.items', [['product_id' => 10, 'quantity' => 2]]);
        self::assertSame([['product_id' => 10, 'quantity' => 2]], $repository->get($sessionId, 'cart.items'));
        $initialTtl = $this->client->ttl($key);
        self::assertGreaterThan(0, $initialTtl);

        $deadline = microtime(true) + 2.5;
        do {
            $ttlBeforeRenewal = $this->client->ttl($key);
            if ($ttlBeforeRenewal < $initialTtl) {
                break;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::assertLessThan($initialTtl, $ttlBeforeRenewal);

        $repository->put($sessionId, 'user.id', 42);
        self::assertSame(42, $repository->get($sessionId, 'user.id'));
        self::assertGreaterThan($ttlBeforeRenewal, $this->client->ttl($key));
    }

    public function test_expired_session_returns_null(): void
    {
        [$repository, $sessionId] = $this->repository(1);
        $repository->put($sessionId, 'cart.items', ['product_id' => 10]);

        $deadline = microtime(true) + 3.0;
        do {
            if ($repository->get($sessionId, 'cart.items') === null) {
                self::addToAssertionCount(1);

                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail('A sessão não expirou dentro do tempo limite.');
    }

    public function test_supports_more_than_fifty_consecutive_writes_with_positive_ttl(): void
    {
        [$repository, $sessionId, $key] = $this->repository(30);

        for ($index = 0; $index < 51; $index++) {
            $repository->put($sessionId, "interaction.{$index}", ['sequence' => $index]);
        }

        for ($index = 0; $index < 51; $index++) {
            self::assertSame(['sequence' => $index], $repository->get($sessionId, "interaction.{$index}"));
        }

        self::assertGreaterThan(0, $this->client->ttl($key));
    }

    /**
     * @return array{0: SessionRepository, 1: string, 2: string}
     */
    private function repository(int $ttl): array
    {
        $sessionId = bin2hex(random_bytes(12));
        $key = 'ec-hub:session:' . $sessionId;
        $this->keys[] = $key;

        return [new SessionRepository($this->client, $ttl), $sessionId, $key];
    }
}
