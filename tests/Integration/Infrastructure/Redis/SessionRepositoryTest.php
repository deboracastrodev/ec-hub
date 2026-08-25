<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Domain\Session\Repository\SessionRepositoryInterface;
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
        if ($this->keys === []) {
            return;
        }

        try {
            $this->client->del($this->keys);
        } catch (\Throwable) {
            // Preserva a falha principal da integração Redis.
        }
    }

    public function test_it_persists_literal_dot_notation_fields_and_renews_ttl(): void
    {
        [$repository, $sessionId, $key] = $this->repository(4);

        $repository->save($sessionId, 'cart.items', [['product_id' => 42, 'quantity' => 2]]);
        $repository->save($sessionId, 'user.id', 7);
        $ttlAfterFirstWrites = $this->client->ttl($key);
        $deadline = microtime(true) + 2;
        do {
            usleep(100_000);
            $ttlBeforeRenewal = $this->client->ttl($key);
        } while ($ttlBeforeRenewal >= $ttlAfterFirstWrites && microtime(true) < $deadline);
        $repository->save($sessionId, 'cart.items', [['product_id' => 42, 'quantity' => 3]]);

        self::assertSame([['product_id' => 42, 'quantity' => 3]], $repository->get($sessionId, 'cart.items'));
        self::assertSame(7, $repository->get($sessionId, 'user.id'));
        self::assertSame(1, $this->client->hexists($key, 'cart.items'));
        self::assertSame(1, $this->client->hexists($key, 'user.id'));
        self::assertGreaterThan(0, $ttlAfterFirstWrites);
        self::assertLessThan($ttlAfterFirstWrites, $ttlBeforeRenewal);
        self::assertGreaterThan($ttlBeforeRenewal, $this->client->ttl($key));
    }

    public function test_it_preserves_float_values_during_a_json_round_trip(): void
    {
        [$repository, $sessionId] = $this->repository(30);
        $repository->save($sessionId, 'metrics.score', 1.0);

        $value = $repository->get($sessionId, 'metrics.score');

        self::assertIsFloat($value);
        self::assertSame(1.0, $value);
    }

    public function test_it_returns_null_after_a_session_expires(): void
    {
        [$repository, $sessionId, $key] = $this->repository(1);
        $repository->save($sessionId, 'user.id', 7);

        self::assertSame(7, $repository->get($sessionId, 'user.id'));
        $deadline = microtime(true) + 3;
        do {
            usleep(100_000);
        } while ($this->client->exists($key) === 1 && microtime(true) < $deadline);

        self::assertSame(0, $this->client->exists($key));
        self::assertNull($repository->get($sessionId, 'user.id'));
    }

    public function test_it_supports_more_than_fifty_consecutive_writes_with_a_positive_ttl(): void
    {
        [$repository, $sessionId, $key] = $this->repository(30);

        for ($index = 0; $index < 55; ++$index) {
            $repository->save($sessionId, "interaction.{$index}", ['index' => $index]);
        }

        for ($index = 0; $index < 55; ++$index) {
            self::assertSame(['index' => $index], $repository->get($sessionId, "interaction.{$index}"));
        }

        self::assertGreaterThan(0, $this->client->ttl($key));
    }

    public function test_container_injects_the_configured_non_default_session_ttl(): void
    {
        $previousTtl = getenv('SESSION_TTL');
        $sessionId = 'test-' . bin2hex(random_bytes(8));
        $key = 'ec-hub:session:' . $sessionId;
        $this->keys[] = $key;
        putenv('SESSION_TTL=30');

        try {
            $container = require dirname(__DIR__, 4) . '/config/bootstrap.php';
            /** @var SessionRepositoryInterface $repository */
            $repository = $container->get(SessionRepositoryInterface::class);
            $repository->save($sessionId, 'user.id', 7);

            $observedTtl = $this->client->ttl($key);
            self::assertGreaterThanOrEqual(28, $observedTtl);
            self::assertLessThanOrEqual(30, $observedTtl);
        } finally {
            putenv($previousTtl === false ? 'SESSION_TTL' : "SESSION_TTL={$previousTtl}");
        }
    }

    public function test_it_returns_null_for_an_absent_session_and_field(): void
    {
        [$repository, $sessionId] = $this->repository(30);

        self::assertNull($repository->get($sessionId, 'user.id'));
    }

    public function test_it_compare_and_swaps_a_single_json_value_and_renews_ttl(): void
    {
        [$repository, $sessionId, $key] = $this->repository(30);
        $initial = ['current' => ['product_ids' => [1]]];
        $next = ['current' => ['product_ids' => [2]], 'previous' => ['product_ids' => [1]]];
        $repository->save($sessionId, 'recommendation.snapshot', $initial);

        self::assertTrue($repository->compareAndSwap($sessionId, 'recommendation.snapshot', $initial, $next));
        self::assertSame($next, $repository->get($sessionId, 'recommendation.snapshot'));
        self::assertFalse($repository->compareAndSwap($sessionId, 'recommendation.snapshot', $initial, $initial));
        self::assertGreaterThan(0, $this->client->ttl($key));
    }

    public function test_it_compare_and_swaps_an_absent_field(): void
    {
        [$repository, $sessionId] = $this->repository(30);
        $value = ['current' => ['product_ids' => [2]]];

        self::assertTrue($repository->compareAndSwap($sessionId, 'recommendation.snapshot', null, $value));
        self::assertSame($value, $repository->get($sessionId, 'recommendation.snapshot'));
    }

    public function test_it_isolates_the_same_field_between_sessions(): void
    {
        [$firstRepository, $firstSessionId] = $this->repository(30);
        [$secondRepository, $secondSessionId] = $this->repository(30);

        $firstRepository->save($firstSessionId, 'user.id', 7);
        $secondRepository->save($secondSessionId, 'user.id', 11);

        self::assertSame(7, $firstRepository->get($firstSessionId, 'user.id'));
        self::assertSame(11, $secondRepository->get($secondSessionId, 'user.id'));
    }

    public function test_it_rejects_stored_json_that_violates_the_value_contract(): void
    {
        [$repository, $sessionId, $key] = $this->repository(30);
        $this->client->hset($key, 'metrics.score', '1e400');

        $this->expectException(\UnexpectedValueException::class);
        $repository->get($sessionId, 'metrics.score');
    }

    public function test_it_rejects_malformed_or_null_json_stored_in_redis(): void
    {
        foreach (['{invalid', 'null'] as $storedValue) {
            [$repository, $sessionId, $key] = $this->repository(30);
            $this->client->hset($key, 'corrupt.value', $storedValue);

            try {
                $repository->get($sessionId, 'corrupt.value');
                self::fail('JSON Redis corrompido ou nulo deve ser rejeitado explicitamente.');
            } catch (\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_it_propagates_redis_connection_failures_without_fallback(): void
    {
        $unavailableClient = new Client([
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 0.1,
            'read_write_timeout' => 0.1,
        ]);
        $repository = new SessionRepository($unavailableClient, 30);

        $this->expectException(\Predis\PredisException::class);
        $repository->get('unavailable-session', 'user.id');
    }

    public function test_it_rejects_absent_or_non_native_json_values_before_writing(): void
    {
        [$repository, $sessionId] = $this->repository(30);

        foreach ([null, new \stdClass(), fopen('php://memory', 'r')] as $invalidValue) {
            try {
                $repository->save($sessionId, 'cart.items', $invalidValue);
                self::fail('Valores não nativos de JSON devem ser rejeitados.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                if (is_resource($invalidValue)) {
                    fclose($invalidValue);
                }
            }
        }
    }

    public function test_it_rejects_invalid_values_nested_inside_json_structures(): void
    {
        [$repository, $sessionId] = $this->repository(30);

        foreach ([[null], ['nested' => new \stdClass()], ['score' => INF]] as $invalidValue) {
            try {
                $repository->save($sessionId, 'cart.items', $invalidValue);
                self::fail('Valores JSON inválidos aninhados devem ser rejeitados.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_it_rejects_values_deeper_than_the_safe_json_nesting_limit_before_writing(): void
    {
        [$repository, $sessionId, $key] = $this->repository(30);
        $value = 'leaf';

        for ($index = 0; $index < 101; ++$index) {
            $value = [$value];
        }

        $this->expectException(\InvalidArgumentException::class);

        try {
            $repository->save($sessionId, 'cart.items', $value);
        } finally {
            self::assertSame(0, $this->client->exists($key));
        }
    }

    /** @return array{SessionRepository, string, string} */
    private function repository(int $ttl): array
    {
        $sessionId = 'test-' . bin2hex(random_bytes(8));
        $key = 'ec-hub:session:' . $sessionId;
        $this->keys[] = $key;

        return [new SessionRepository($this->client, $ttl), $sessionId, $key];
    }
}
