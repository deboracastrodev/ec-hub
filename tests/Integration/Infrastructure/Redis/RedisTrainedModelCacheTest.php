<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Domain\Product\Model\Product;
use App\Infrastructure\ML\CachedNeighborFinder;
use App\Infrastructure\ML\RubixNeighborFinder;
use App\Infrastructure\Redis\RedisTrainedModelCache;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Tests\Support\RecordingLogger;

/**
 * Story 10.1: the trained-model slot against a real Redis. Uses a random key
 * so the real ec-hub:model:knn is never touched.
 */
#[Group('redis')]
final class RedisTrainedModelCacheTest extends TestCase
{
    private Client $client;
    private string $key;

    protected function setUp(): void
    {
        /** @var array{host: string, port: int} $config */
        $config = require dirname(__DIR__, 4) . '/config/redis.php';
        $this->client = new Client(['scheme' => 'tcp', ...$config]);

        try {
            $this->client->ping();
        } catch (\Throwable $exception) {
            self::markTestSkipped('Redis indisponível: ' . $exception->getMessage());
        }
        $this->key = 'test-model-knn-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            $this->client->del([$this->key]);
        } catch (\Throwable) {
            // Preserva a falha principal da integração Redis.
        }
    }

    public function testDefaultKeyIsTheSingleSlot(): void
    {
        self::assertSame('ec-hub:model:knn', RedisTrainedModelCache::KEY);
    }

    public function testStoreLoadAndInvalidate(): void
    {
        $cache = new RedisTrainedModelCache($this->client, $this->key);
        self::assertNull($cache->load());

        $payload = serialize(['binary' => "\0\xff model"]);
        $cache->store($payload);

        self::assertSame($payload, $cache->load());
        $ttl = (int) $this->client->ttl($this->key);
        self::assertGreaterThan(0, $ttl);
        self::assertLessThanOrEqual(RedisTrainedModelCache::TTL_SECONDS, $ttl);

        $cache->invalidate();
        self::assertNull($cache->load());
        self::assertSame(0, (int) $this->client->exists($this->key));
    }

    public function testTwoFreshFindersOverRealRedisTrainOnlyOnce(): void
    {
        $fits = 0;
        $logger = new RecordingLogger();
        $newFinder = function () use (&$fits, $logger): CachedNeighborFinder {
            return new CachedNeighborFinder(
                new RedisTrainedModelCache($this->client, $this->key),
                $logger,
                static function (array $products) use (&$fits): RubixNeighborFinder {
                    ++$fits;
                    $finder = new RubixNeighborFinder();
                    $finder->train($products);

                    return $finder;
                }
            );
        };

        $first = $newFinder();
        $first->train(self::catalog());
        $second = $newFinder();
        $second->train(self::catalog());

        self::assertSame(1, $fits);
        self::assertSame(['Modelo KNN treinado', 'Modelo KNN carregado do cache'], $logger->messages('info'));
        $ids = static fn (array $neighbors): array => array_map(static fn (array $n): ?int => $n['product']->getId(), $neighbors);
        self::assertSame($ids($first->nearest(self::catalog()[0], 3)), $ids($second->nearest(self::catalog()[0], 3)));
    }

    /** @return list<Product> */
    private static function catalog(): array
    {
        $products = [];
        foreach ([[1, 'Eletrônicos', 200.0], [2, 'Eletrônicos', 1200.0], [3, 'Esportes', 100.0],
            [4, 'Esportes', 400.0], [5, 'Casa', 250.0]] as [$id, $category, $price]) {
            $products[] = Product::fromArray(['id' => $id, 'name' => 'Produto ' . $id, 'slug' => 'p-' . $id,
                'price' => $price, 'category' => $category, 'created_at' => '2026-01-01 10:00:00']);
        }

        return $products;
    }
}
