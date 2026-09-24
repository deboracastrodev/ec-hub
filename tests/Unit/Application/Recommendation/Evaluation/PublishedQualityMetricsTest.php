<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation\Evaluation;

use App\Application\Recommendation\Evaluation\PublishedQualityMetrics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PublishedQualityMetricsTest extends TestCase
{
    private const COMMITTED_JSON = __DIR__ . '/../../../../../docs/evaluation/offline-evaluation.json';

    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testItReadsTheValuesOfAValidReport(): void
    {
        $metrics = PublishedQualityMetrics::fromReport(self::report());

        self::assertNotNull($metrics);
        self::assertSame(0.95, $metrics->precisionAt5);
        self::assertSame(0.6875, $metrics->catalogCoverageAt5);
        self::assertSame('2026-09-24', $metrics->measuredAt);
        self::assertSame(16, $metrics->holdoutSize);
        self::assertSame(0, $metrics->fallbackActivated);
        self::assertSame(16, $metrics->fallbackQueries);
    }

    public function testItReadsTheCommittedReportFile(): void
    {
        $report = json_decode((string) file_get_contents(self::COMMITTED_JSON), true);
        $metrics = PublishedQualityMetrics::fromReportFile(self::COMMITTED_JSON);

        self::assertNotNull($metrics);
        self::assertSame((float) $report['metrics']['precision_at_k']['5'], $metrics->precisionAt5);
        self::assertSame((float) $report['coverage']['catalog_coverage_at_k']['5'], $metrics->catalogCoverageAt5);
        self::assertSame($report['measured_at'], $metrics->measuredAt);
        self::assertSame($report['split']['holdout_size'], $metrics->holdoutSize);
    }

    public function testActivationIsNullWithoutColdStartBlock(): void
    {
        $report = self::report();
        unset($report['cold_start']);

        $metrics = PublishedQualityMetrics::fromReport($report);

        self::assertNotNull($metrics);
        self::assertNull($metrics->fallbackActivated);
        self::assertNull($metrics->fallbackQueries);
    }

    public function testMalformedActivationIsOmittedInsteadOfShown(): void
    {
        $report = self::report();
        $report['cold_start']['activation'] = ['queries' => 16, 'activated' => 17];

        $metrics = PublishedQualityMetrics::fromReport($report);

        self::assertNotNull($metrics);
        self::assertNull($metrics->fallbackActivated);
        self::assertNull($metrics->fallbackQueries);
    }

    public function testItAcceptsIntegerBounds(): void
    {
        $report = self::report();
        $report['metrics']['precision_at_k']['5'] = 1;
        $report['coverage']['catalog_coverage_at_k']['5'] = 0;

        $metrics = PublishedQualityMetrics::fromReport($report);

        self::assertNotNull($metrics);
        self::assertSame(1.0, $metrics->precisionAt5);
        self::assertSame(0.0, $metrics->catalogCoverageAt5);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidReports')]
    public function testItReturnsNullForAnInvalidReport(callable $mutate): void
    {
        self::assertNull(PublishedQualityMetrics::fromReport($mutate(self::report())));
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>}> */
    public static function invalidReports(): iterable
    {
        yield 'sem chave "5" na precision' => [static function (array $r): array {
            unset($r['metrics']['precision_at_k']['5']);

            return $r;
        }];
        yield 'sem chave "5" na cobertura' => [static function (array $r): array {
            unset($r['coverage']['catalog_coverage_at_k']['5']);

            return $r;
        }];
        yield 'precision acima de 1' => [static function (array $r): array {
            $r['metrics']['precision_at_k']['5'] = 1.2;

            return $r;
        }];
        yield 'cobertura negativa' => [static function (array $r): array {
            $r['coverage']['catalog_coverage_at_k']['5'] = -0.1;

            return $r;
        }];
        yield 'precision nula' => [static function (array $r): array {
            $r['metrics']['precision_at_k']['5'] = null;

            return $r;
        }];
        yield 'precision como string' => [static function (array $r): array {
            $r['metrics']['precision_at_k']['5'] = '0.95';

            return $r;
        }];
        yield 'data vazia' => [static function (array $r): array {
            $r['measured_at'] = '  ';

            return $r;
        }];
        yield 'sem data' => [static function (array $r): array {
            unset($r['measured_at']);

            return $r;
        }];
        yield 'metrics não é objeto' => [static function (array $r): array {
            $r['metrics'] = 'x';

            return $r;
        }];
        yield 'holdout vazio' => [static function (array $r): array {
            $r['split']['holdout_size'] = 0;

            return $r;
        }];
        yield 'holdout negativo' => [static function (array $r): array {
            $r['split']['holdout_size'] = -1;

            return $r;
        }];
        yield 'holdout ausente' => [static function (array $r): array {
            unset($r['split']['holdout_size']);

            return $r;
        }];
    }

    public function testMissingFileReturnsNullWithoutWarning(): void
    {
        $this->assertNoWarning(static fn () => PublishedQualityMetrics::fromReportFile('/nao/existe/offline-evaluation.json'));
        $this->assertNoWarning(static fn () => PublishedQualityMetrics::fromReportFile(''));
        $this->assertNoWarning(static fn () => PublishedQualityMetrics::fromReportFile(sys_get_temp_dir()));
    }

    public function testInvalidJsonReturnsNullWithoutWarning(): void
    {
        $this->assertNoWarning(fn () => PublishedQualityMetrics::fromReportFile($this->tempFile('{"measured_at": ')));
        $this->assertNoWarning(fn () => PublishedQualityMetrics::fromReportFile($this->tempFile('"texto"')));
        $this->assertNoWarning(fn () => PublishedQualityMetrics::fromReportFile($this->tempFile('')));
    }

    public function testValidFileIsReadOnEveryCall(): void
    {
        $path = $this->tempFile((string) json_encode(self::report()));
        self::assertSame(0.95, PublishedQualityMetrics::fromReportFile($path)?->precisionAt5);

        $report = self::report();
        $report['metrics']['precision_at_k']['5'] = 0.5;
        file_put_contents($path, (string) json_encode($report));

        self::assertSame(0.5, PublishedQualityMetrics::fromReportFile($path)?->precisionAt5);
    }

    /** @param callable(): ?PublishedQualityMetrics $read */
    private function assertNoWarning(callable $read): void
    {
        $errors = [];
        set_error_handler(static function (int $errno, string $message) use (&$errors): bool {
            $errors[] = $message;

            return true;
        });

        try {
            $result = $read();
        } finally {
            restore_error_handler();
        }

        self::assertNull($result);
        self::assertSame([], $errors);
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'quality-');
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** @return array<string, mixed> */
    private static function report(): array
    {
        return [
            'algorithm' => 'knn',
            'measured_at' => '2026-09-24',
            'split' => ['seed' => 42, 'holdout_size' => 16],
            'metrics' => [
                'precision_at_k' => ['1' => 1.0, '5' => 0.95, '10' => 0.8125],
            ],
            'coverage' => [
                'catalog_coverage_at_k' => ['1' => 0.1875, '5' => 0.6875, '10' => 0.9219],
            ],
            'cold_start' => [
                'activation' => ['queries' => 16, 'activated' => 0],
            ],
        ];
    }
}
