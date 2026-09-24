<?php

declare(strict_types=1);

namespace Tests\Integration\Infrastructure\Redis;

use App\Domain\Recommendation\Model\AlgorithmRequestSample;
use App\Infrastructure\Redis\RedisAlgorithmMetricsRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Predis\Client;

/**
 * Story 8.2: per-algorithm A/B aggregates against a real Redis. Uses a
 * random algorithm name so the real ec-hub:ab-metrics:{knn,collaborative}
 * keys are never touched.
 */
#[Group('redis')]
final class RedisAlgorithmMetricsRepositoryTest extends TestCase
{
    private Client $client;
    private string $algorithm;

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
        $this->algorithm = 'test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        try {
            $this->client->del([
                'ec-hub:ab-metrics:' . $this->algorithm,
                'ec-hub:ab-metrics:' . $this->algorithm . ':subjects',
            ]);
        } catch (\Throwable) {
            // Preserva a falha principal da integração Redis.
        }
    }

    public function testAlgorithmMetricsAreZeroWithoutSamples(): void
    {
        $metrics = (new RedisAlgorithmMetricsRepository($this->client))->get($this->algorithm);

        self::assertSame($this->algorithm, $metrics->algorithm);
        self::assertSame(0, $metrics->requests);
        self::assertSame(0, $metrics->uniqueSubjects);
        self::assertNull($metrics->avgScore());
    }

    public function testAlgorithmMetricsAccumulateSamplesAndCountUniqueSubjects(): void
    {
        $repository = new RedisAlgorithmMetricsRepository($this->client);

        $repository->record($this->algorithm, new AlgorithmRequestSample(5, 4, 350.5, 5, 10.25, 's1'));
        $repository->record($this->algorithm, new AlgorithmRequestSample(3, 0, 180.0, 3, 5.75, 's1'));
        $repository->record($this->algorithm, new AlgorithmRequestSample(2, 2, 150.0, 2, 4.0, 's2'));
        $repository->record($this->algorithm, new AlgorithmRequestSample(0, 0, 0.0, 0, 1.0));

        $metrics = $repository->get($this->algorithm);

        self::assertSame(4, $metrics->requests);
        self::assertSame(2, $metrics->uniqueSubjects);
        self::assertSame(10, $metrics->totalItems);
        self::assertSame(6, $metrics->mlItems);
        self::assertEqualsWithDelta(680.5, $metrics->scoreSum, 0.0001);
        self::assertSame(10, $metrics->scoredItems);
        self::assertEqualsWithDelta(21.0, $metrics->responseTimeSumMs, 0.0001);
        self::assertSame(60.0, $metrics->mlItemRate());
        self::assertSame(-1, $this->client->ttl('ec-hub:ab-metrics:' . $this->algorithm));
    }
}
