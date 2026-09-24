<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisEventHistoryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Transaction\MultiExec;

#[Group('redis')]
final class RedisEventHistoryRepositoryTest extends TestCase
{
    private Client $client;
    /** @var list<string> */
    private array $keys = [];

    protected function setUp(): void
    {
        $config = require dirname(__DIR__, 4) . '/config/redis.php';
        $this->client = new Client(['scheme' => 'tcp', ...$config]);
    }

    protected function tearDown(): void
    {
        if ($this->keys !== []) {
            $this->client->del($this->keys);
        }
    }

    public function testIndexesBySessionAndUserKeepsLastFiftyInOrderAndRenewsTtl(): void
    {
        $sessionId = 'test-' . bin2hex(random_bytes(8));
        $userId = 'user-' . bin2hex(random_bytes(8));
        $sessionKey = 'ec-hub:event-history:session:' . $sessionId;
        $userKey = 'ec-hub:event-history:user:' . $userId;
        $this->keys = [$sessionKey, $userKey];
        $repository = new RedisEventHistoryRepository($this->client, 4);

        for ($index = 1; $index <= 51; ++$index) {
            $repository->append($sessionId, $userId, ['event' => 'product.viewed', 'product_id' => $index]);
        }
        $ttlBefore = $this->client->ttl($sessionKey);
        usleep(1_100_000);
        $ttlAged = $this->client->ttl($sessionKey);
        $repository->append($sessionId, $userId, ['event' => 'product.clicked', 'product_id' => 52]);

        $bySession = $repository->getBySession($sessionId);
        $byUser = $repository->getByUserId($userId);
        self::assertCount(50, $bySession);
        self::assertSame(3, $bySession[0]['product_id']);
        self::assertSame(52, $bySession[49]['product_id']);
        self::assertSame($bySession, $byUser);
        self::assertGreaterThan(0, $ttlBefore);
        self::assertLessThan($ttlBefore, $ttlAged);
        self::assertGreaterThan($ttlAged, $this->client->ttl($sessionKey));
        self::assertGreaterThan($ttlAged, $this->client->ttl($userKey));
    }

    public function testAppendWithUserIdWritesBothIndexesInASingleTransaction(): void
    {
        $sessionId = 'test-' . bin2hex(random_bytes(8));
        $userId = 'user-' . bin2hex(random_bytes(8));
        $sessionKey = 'ec-hub:event-history:session:' . $sessionId;
        $userKey = 'ec-hub:event-history:user:' . $userId;
        $this->keys = [$sessionKey, $userKey];
        $client = $this->countingClient();
        $repository = new RedisEventHistoryRepository($client, 60);

        $repository->append($sessionId, $userId, ['event' => 'product.viewed', 'product_id' => 1]);

        self::assertSame(1, $client->transactions);
        self::assertSame([[$sessionKey, $userKey]], $client->transactionKeys);
        self::assertSame([['event' => 'product.viewed', 'product_id' => 1]], $repository->getBySession($sessionId));
        self::assertSame([['event' => 'product.viewed', 'product_id' => 1]], $repository->getByUserId($userId));
        self::assertGreaterThan(0, $this->client->ttl($sessionKey));
        self::assertGreaterThan(0, $this->client->ttl($userKey));
    }

    public function testAppendWithoutUserIdWritesOnlyTheSessionIndexInASingleTransaction(): void
    {
        $sessionId = 'test-' . bin2hex(random_bytes(8));
        $sessionKey = 'ec-hub:event-history:session:' . $sessionId;
        $this->keys = [$sessionKey];
        $client = $this->countingClient();
        $repository = new RedisEventHistoryRepository($client, 60);

        $repository->append($sessionId, null, ['event' => 'product.viewed', 'product_id' => 1]);
        $repository->append($sessionId, '   ', ['event' => 'product.clicked', 'product_id' => 2]);

        self::assertSame(2, $client->transactions);
        self::assertSame([[$sessionKey], [$sessionKey]], $client->transactionKeys);
        self::assertCount(2, $repository->getBySession($sessionId));
    }

    private function countingClient(): TransactionCountingClient
    {
        $config = require dirname(__DIR__, 4) . '/config/redis.php';

        return new TransactionCountingClient(['scheme' => 'tcp', ...$config]);
    }
}

/**
 * Real Predis client that counts MULTI/EXEC blocks opened through
 * transaction() and records, per block, the distinct keys its commands touch.
 */
final class TransactionCountingClient extends Client
{
    public int $transactions = 0;
    /** @var list<list<string>> */
    public array $transactionKeys = [];

    public function transaction(...$arguments)
    {
        ++$this->transactions;
        $index = count($this->transactionKeys);
        $this->transactionKeys[] = [];

        $callable = $arguments[0] ?? null;
        if (is_callable($callable)) {
            $arguments[0] = function (MultiExec $transaction) use ($callable, $index): mixed {
                return $callable(new KeyRecordingTransaction($transaction, function (string $key) use ($index): void {
                    if (! in_array($key, $this->transactionKeys[$index], true)) {
                        $this->transactionKeys[$index][] = $key;
                    }
                }));
            };
        }

        return parent::transaction(...$arguments);
    }
}

/** Forwards every command to the real MultiExec, reporting the key argument first. */
final class KeyRecordingTransaction extends MultiExec
{
    /** @param \Closure(string): void $record */
    public function __construct(private readonly MultiExec $inner, private readonly \Closure $record)
    {
    }

    public function __call($method, $arguments)
    {
        if (isset($arguments[0]) && is_string($arguments[0])) {
            ($this->record)($arguments[0]);
        }

        return $this->inner->__call($method, $arguments);
    }
}
