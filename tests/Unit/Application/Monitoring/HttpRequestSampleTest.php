<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\HttpRequestSample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpRequestSampleTest extends TestCase
{
    public function testItKeepsAValidSample(): void
    {
        $sample = new HttpRequestSample('get', '/products/{param}', 404, 0.25);

        self::assertSame('GET', $sample->method);
        self::assertSame('/products/{param}', $sample->route);
        self::assertSame(404, $sample->status);
        self::assertSame(0.25, $sample->durationSeconds);
    }

    /** @return iterable<string, array{string, string}> */
    public static function methods(): iterable
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            yield $method => [strtolower($method), $method];
        }
        yield 'unknown' => ['FOO', 'OTHER'];
        yield 'empty' => ['', 'OTHER'];
        yield 'connect' => ['CONNECT', 'OTHER'];
    }

    #[DataProvider('methods')]
    public function testMethodIsUppercasedAndBounded(string $method, string $expected): void
    {
        self::assertSame($expected, (new HttpRequestSample($method, '/', 200, 0.0))->method);
    }

    /** @return iterable<string, array{int, int}> */
    public static function statuses(): iterable
    {
        yield 'lower bound' => [100, 100];
        yield 'upper bound' => [599, 599];
        yield 'below' => [99, 500];
        yield 'above' => [600, 500];
        yield 'zero' => [0, 500];
    }

    #[DataProvider('statuses')]
    public function testStatusOutsideTheValidRangeBecomes500(int $status, int $expected): void
    {
        self::assertSame($expected, (new HttpRequestSample('GET', '/', $status, 0.0))->status);
    }

    public function testNegativeOrNonFiniteDurationBecomesZero(): void
    {
        self::assertSame(0.0, (new HttpRequestSample('GET', '/', 200, -1.5))->durationSeconds);
        self::assertSame(0.0, (new HttpRequestSample('GET', '/', 200, NAN))->durationSeconds);
        self::assertSame(0.0, (new HttpRequestSample('GET', '/', 200, INF))->durationSeconds);
    }
}
