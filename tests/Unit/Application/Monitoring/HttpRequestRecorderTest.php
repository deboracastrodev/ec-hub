<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\HttpRequestRecorder;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Tests\Support\InMemoryHttpMetricsRepository;

final class HttpRequestRecorderTest extends TestCase
{
    public function testItRecordsANormalizedSample(): void
    {
        $repository = new InMemoryHttpMetricsRepository();

        (new HttpRequestRecorder($repository, new RecordingLogger()))->record('post', '/api/events', 201, 0.012);

        self::assertCount(1, $repository->recorded);
        self::assertSame('POST', $repository->recorded[0]->method);
        self::assertSame('/api/events', $repository->recorded[0]->route);
        self::assertSame(201, $repository->recorded[0]->status);
        self::assertSame(0.012, $repository->recorded[0]->durationSeconds);
    }

    public function testARepositoryFailureIsSwallowedAndLoggedAsWarning(): void
    {
        $logger = new RecordingLogger();

        (new HttpRequestRecorder(new InMemoryHttpMetricsRepository(failOnRecord: true), $logger))
            ->record('GET', '/', 200, 0.01);

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('Redis indisponível.', $logger->records[0]['context']['error']);
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
