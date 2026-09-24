<?php

declare(strict_types=1);

namespace Tests\Integration\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Story 10.5: a seção "Qualidade da recomendação (medida)" do README precisa
 * repetir os números do relatório commitado de `make eval`, sem digitação à
 * mão. Se o relatório for regenerado com outros valores ou outra data, este
 * teste falha até o README ser atualizado.
 */
final class ReadmeQualityMetricsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testReadmeQualitySectionMatchesTheCommittedReport(): void
    {
        $report = json_decode((string) file_get_contents(self::ROOT . '/docs/evaluation/offline-evaluation.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($report);

        $section = $this->qualitySection((string) file_get_contents(self::ROOT . '/README.md'));
        $measuredAt = $report['measured_at'];
        $precision = number_format((float) $report['metrics']['precision_at_k']['5'], 2, ',', '.');
        $coverage = number_format((float) $report['coverage']['catalog_coverage_at_k']['5'] * 100, 2, ',', '.');
        self::assertArrayHasKey('cold_start', $report, 'Relatório commitado sem o bloco cold_start (rode make eval).');
        self::assertIsArray($report['cold_start'], 'cold_start do relatório commitado não é um objeto.');
        self::assertArrayHasKey('activation', $report['cold_start'], 'Relatório commitado sem cold_start.activation.');
        $activation = $report['cold_start']['activation'];
        self::assertIsArray($activation, 'cold_start.activation do relatório commitado não é um objeto.');
        self::assertArrayHasKey('activated', $activation);
        self::assertArrayHasKey('queries', $activation);

        self::assertStringContainsString(sprintf('precision@5: %s · medido em %s', $precision, $measuredAt), $section);
        self::assertStringContainsString(sprintf('Cobertura de catálogo@5: %s%% · medido em %s', $coverage, $measuredAt), $section);
        self::assertStringContainsString(sprintf('fallback ativado em %d de %d consultas', $activation['activated'], $activation['queries']), $section);
        self::assertStringContainsString(sprintf('medidos em %s sobre um holdout de %d produtos', $measuredAt, $report['split']['holdout_size']), $section);
        self::assertStringContainsString('(docs/evaluation/offline-evaluation.md)', $section);
        self::assertStringContainsString('same_category', $section);
        self::assertStringContainsString('/metrics', $section);
    }

    private function qualitySection(string $readme): string
    {
        $start = strpos($readme, "\n## Qualidade da recomendação (medida)\n");
        self::assertNotFalse($start, 'README sem a seção "## Qualidade da recomendação (medida)".');
        $end = strpos($readme, "\n## ", $start + 1);

        return $end === false ? substr($readme, $start) : substr($readme, $start, $end - $start);
    }
}
