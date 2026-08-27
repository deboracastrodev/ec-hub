<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\HealthCheck;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use RuntimeException;

final class HealthCheckTest extends TestCase
{
    public function testItReportsBothServicesUpAfterExecutingBothChecks(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('query')->with('SELECT 1')->willReturn($this->createMock(\PDOStatement::class));
        $redis = new HealthCheckRedisClient();

        $status = (new HealthCheck(fn (): PDO => $pdo, fn (): Client => $redis))->check();

        self::assertSame([
            'status' => 'healthy',
            'services' => [
                'mysql' => ['status' => 'up'],
                'redis' => ['status' => 'up'],
            ],
        ], $status->toArray());
        self::assertSame(1, $redis->pings);
    }

    public function testItChecksRedisWhenMysqlFails(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willThrowException(new RuntimeException('mysql connection details'));
        $redis = new HealthCheckRedisClient();

        $payload = (new HealthCheck(fn (): PDO => $pdo, fn (): Client => $redis))->check()->toArray();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame('down', $payload['services']['mysql']['status']);
        self::assertSame('up', $payload['services']['redis']['status']);
        self::assertSame(1, $redis->pings);
        self::assertStringNotContainsString('mysql connection details', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testItChecksMysqlWhenRedisFails(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('query')->with('SELECT 1')->willReturn($this->createMock(\PDOStatement::class));
        $redis = new HealthCheckRedisClient(new RuntimeException('redis connection details'));

        $payload = (new HealthCheck(fn (): PDO => $pdo, fn (): Client => $redis))->check()->toArray();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame('up', $payload['services']['mysql']['status']);
        self::assertSame('down', $payload['services']['redis']['status']);
        self::assertSame(1, $redis->pings);
        self::assertStringNotContainsString('redis connection details', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testItReportsBothServicesDownWithoutLeakingFailureDetails(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willThrowException(new RuntimeException('mysql secret'));
        $redis = new HealthCheckRedisClient(new RuntimeException('redis secret'));

        $payload = (new HealthCheck(fn (): PDO => $pdo, fn (): Client => $redis))->check()->toArray();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame('down', $payload['services']['mysql']['status']);
        self::assertSame('down', $payload['services']['redis']['status']);
        self::assertStringNotContainsString('secret', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function testItReportsMysqlDownWhenTheLightweightQueryReturnsFalse(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects(self::once())->method('query')->with('SELECT 1')->willReturn(false);
        $redis = new HealthCheckRedisClient();

        $payload = (new HealthCheck(fn (): PDO => $pdo, fn (): Client => $redis))->check()->toArray();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame('down', $payload['services']['mysql']['status']);
        self::assertSame('up', $payload['services']['redis']['status']);
        self::assertSame(1, $redis->pings);
    }

    public function testItReportsRedisDownWhenPingReturnsAnUnexpectedResponse(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('query')->willReturn($this->createMock(\PDOStatement::class));
        $redis = new HealthCheckRedisClient(response: 'unexpected');

        $payload = (new HealthCheck(fn (): PDO => $pdo, fn (): Client => $redis))->check()->toArray();

        self::assertSame('unhealthy', $payload['status']);
        self::assertSame('up', $payload['services']['mysql']['status']);
        self::assertSame('down', $payload['services']['redis']['status']);
        self::assertSame(1, $redis->pings);
    }
}

final class HealthCheckRedisClient extends Client
{
    public int $pings = 0;

    public function __construct(
        private readonly ?\Throwable $failure = null,
        private readonly string $response = 'PONG',
    ) {
    }

    public function ping(): string
    {
        ++$this->pings;

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->response;
    }
}
