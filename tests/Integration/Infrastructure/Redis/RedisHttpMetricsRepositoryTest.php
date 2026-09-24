<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Application\Monitoring\HttpRequestSample;
use App\Infrastructure\Redis\RedisHttpMetricsRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Story 8.4: global HTTP metrics against a real Redis. Uses a random hash key
 * so the real ec-hub:http-metrics is never touched.
 */
#[Group('redis')]
final class RedisHttpMetricsRepositoryTest extends TestCase
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
        $this->key = 'test-http-metrics-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            $this->client->del([$this->key]);
        } catch (\Throwable) {
            // Preserva a falha principal da integração Redis.
        }
    }

    public function testDefaultKeyIsTheSingleHash(): void
    {
        self::assertSame('ec-hub:http-metrics', RedisHttpMetricsRepository::KEY);
    }

    public function testEmptyHashHasNoRoutes(): void
    {
        self::assertSame([], (new RedisHttpMetricsRepository($this->client, $this->key))->routes());
    }

    public function testItRecordsAndAggregatesSamples(): void
    {
        $repository = new RedisHttpMetricsRepository($this->client, $this->key);

        $repository->record(new HttpRequestSample('GET', '/products', 200, 0.004));
        $repository->record(new HttpRequestSample('GET', '/products', 200, 0.05));
        $repository->record(new HttpRequestSample('GET', '/products', 500, 9.0));
        $repository->record(new HttpRequestSample('POST', '/products', 201, 0.2));
        $repository->record(new HttpRequestSample('GET', 'unmatched', 404, 0.001));

        $routes = $repository->routes();

        self::assertSame(
            [['GET', '/products'], ['POST', '/products'], ['GET', 'unmatched']],
            array_map(static fn ($route): array => [$route->method, $route->route], $routes)
        );
        self::assertSame([200 => 2, 500 => 1], $routes[0]->statuses);
        self::assertSame(3, $routes[0]->requests());
        self::assertSame(1, $routes[0]->errors());
        self::assertEqualsWithDelta(9.054, $routes[0]->durationSumSeconds, 0.000001);
        self::assertSame(['0.005' => 1, '0.05' => 1, '+Inf' => 1], $this->sorted($routes[0]->buckets));
        self::assertSame(['0.2' => 1], $routes[1]->buckets);
        self::assertSame([404 => 1], $routes[2]->statuses);
        self::assertSame(-1, $this->client->ttl($this->key));
        self::assertSame('1', $this->client->hget($this->key, 'duration_bucket|GET|/products|0.05'));
        self::assertSame('2', $this->client->hget($this->key, 'requests|GET|/products|200'));
    }

    public function testMalformedFieldsAreIgnored(): void
    {
        $repository = new RedisHttpMetricsRepository($this->client, $this->key);
        $repository->record(new HttpRequestSample('GET', '/ok', 200, 0.01));
        $this->client->hmset($this->key, [
            'garbage' => '1',
            'requests|GET|/ok|abc' => '1',
            'requests|GET|/ok|200x' => '1',
            'requests|BREW|/ok|200' => '1',
            'requests|GET|/ok|200|' => '1',
            'requests|GET' => '1',
            'duration_bucket|GET|/ok|0.07' => '1',
            'duration_sum|GET|/bad' => 'nope',
            'requests|GET|/negative|200' => '-3',
            "requests|GET|/caf\xE9|200" => '1',
        ]);

        $routes = $repository->routes();

        self::assertCount(1, $routes);
        self::assertSame('/ok', $routes[0]->route);
        self::assertSame([200 => 1], $routes[0]->statuses);
        self::assertSame(['0.01' => 1], $routes[0]->buckets);
    }

    /**
     * @param array<string, int> $buckets
     * @return array<string, int>
     */
    private function sorted(array $buckets): array
    {
        uksort($buckets, static fn (string $a, string $b): int => ($a === '+Inf' ? INF : (float) $a) <=> ($b === '+Inf' ? INF : (float) $b));

        return $buckets;
    }
}
