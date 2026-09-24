<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Recommendation\Evaluation;

use App\Application\Recommendation\Evaluation\EvaluationReportWriter;
use PHPUnit\Framework\TestCase;

final class EvaluationReportWriterTest extends TestCase
{
    private const CATALOG = ['path' => 'database/fixtures/x.json', 'products' => 10, 'sha256' => 'abcdef123456'];

    public function test_null_metrics_render_as_n_a_in_markdown_and_null_in_json(): void
    {
        $writer = new EvaluationReportWriter();
        $result = $this->buildResult(0.125, [1 => null, 5 => null], [1 => null, 5 => null], 0, 3);

        $markdown = $writer->renderMarkdown($result, '2026-09-24', self::CATALOG);
        $json = json_decode($writer->renderJson($result, '2026-09-24', self::CATALOG), true, 512, JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('| 1 | n/a | n/a |', $markdown);
        $this->assertStringContainsString('| 5 | n/a | n/a |', $markdown);
        $this->assertStringNotContainsString('0.0000', $markdown);
        $this->assertStringContainsString('12.5%', $markdown);
        $this->assertNull($json['metrics']['precision_at_k']['1']);
        $this->assertNull($json['metrics']['recall_at_k']['5']);
    }

    public function test_values_use_four_decimals_with_dot_and_ratio_has_no_trailing_zeros(): void
    {
        $writer = new EvaluationReportWriter();
        $result = $this->buildResult(0.2, [1 => 1.0, 5 => 0.5], [1 => 0.1155, 5 => 0.25], 2, 0);

        $markdown = $writer->renderMarkdown($result, '2026-09-24', self::CATALOG);

        $this->assertStringContainsString('| 1 | 1.0000 | 0.1155 |', $markdown);
        $this->assertStringContainsString('| 5 | 0.5000 | 0.2500 |', $markdown);
        $this->assertStringContainsString('20%', $markdown);
    }

    /**
     * @param array<int, float|null> $precision
     * @param array<int, float|null> $recall
     * @return array<string, mixed>
     */
    private function buildResult(float $ratio, array $precision, array $recall, int $evaluated, int $without): array
    {
        return [
            'algorithm' => 'fake',
            'split' => ['seed' => 42, 'holdout_ratio' => $ratio, 'train_size' => 8, 'holdout_size' => 2, 'holdout_ids' => [3, 7]],
            'relevance' => 'same_category',
            'queries' => ['evaluated' => $evaluated, 'without_relevant' => $without],
            'metrics' => ['precision_at_k' => $precision, 'recall_at_k' => $recall],
        ];
    }
}
