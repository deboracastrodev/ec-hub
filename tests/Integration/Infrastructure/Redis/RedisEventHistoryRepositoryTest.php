<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Infrastructure\Redis\RedisEventHistoryRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

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
}
