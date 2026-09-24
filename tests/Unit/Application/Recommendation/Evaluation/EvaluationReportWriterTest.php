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

    public function test_catalog_section_without_excess_and_n_a_ild_at_k_1(): void
    {
        $writer = new EvaluationReportWriter();
        $result = $this->buildResult(0.2, [1 => 1.0, 5 => 0.5], [1 => 0.1, 5 => 0.2], 2, 0);

        $markdown = $writer->renderMarkdown($result, '2026-09-24', self::CATALOG);

        $this->assertStringContainsString('## Cobertura, diversidade e concentração', $markdown);
        $this->assertStringContainsString('| 1 | 0.2500 | 2/8 | n/a | 1.0000 | 0.7500 | 0.5000 | excessiva |', $markdown);
        $this->assertStringContainsString('| 5 | 0.6250 | 5/8 | 0.4000 | 2.0000 | 0.3000 | 0.2500 | ok |', $markdown);
        $this->assertStringContainsString('**Sinal de concentração:** excessiva em k = 1.', $markdown);
        $this->assertStringContainsString('- produto 3: 4 aparições', $markdown);
        $this->assertStringContainsString('- produto 7: 1 aparição', $markdown);
        $this->assertStringContainsString('top share ≥ 0.5', $markdown);
        $this->assertStringContainsString('`ceil(0.1 × candidatos)`', $markdown);
        $this->assertStringContainsString('aqui, os 1 candidatos mais recomendados (`top_products`)', $markdown);
        // 2 listas × k=1 = 2 aparições; top 1 → piso de 1/2.
        $this->assertStringContainsString('`top_products / aparições`', $markdown);
        $this->assertStringContainsString('Aqui, em k = 1: 1/2 = 0.5000.', $markdown);
        $this->assertStringContainsString('Isso não torna o sinal inevitável', $markdown);
        $this->assertStringContainsString('- **Fora deste relatório:** cold-start/fallback (Story 10.4).', $markdown);
        $this->assertStringNotContainsString('Story 10.3)', $markdown);

        $result['concentration']['excessive_at_k'] = [1 => false, 5 => false];
        $markdown = $writer->renderMarkdown($result, '2026-09-24', self::CATALOG);
        $this->assertStringContainsString('**Sinal de concentração:** nenhuma concentração excessiva.', $markdown);
        $this->assertStringNotContainsString('excessiva em k', $markdown);
    }

    public function test_catalog_section_without_any_recommendation(): void
    {
        $writer = new EvaluationReportWriter();
        $result = $this->buildResult(0.2, [1 => null], [1 => null], 0, 2);
        $result['coverage']['covered_products_at_k'] = [1 => 0];
        $result['coverage']['catalog_coverage_at_k'] = [1 => 0.0];
        $result['diversity']['intra_list_diversity_at_k'] = [1 => null];
        $result['diversity']['distinct_categories_at_k'] = [1 => 0.0];
        $result['concentration'] = array_merge($result['concentration'], [
            'gini_at_k' => [1 => null],
            'top_share_at_k' => [1 => null],
            'excessive_at_k' => [1 => null],
            'most_recommended' => [],
        ]);

        $markdown = $writer->renderMarkdown($result, '2026-09-24', self::CATALOG);

        $this->assertStringContainsString('| 1 | 0.0000 | 0/8 | n/a | 0.0000 | n/a | n/a | n/a |', $markdown);
        $this->assertStringContainsString('**Sinal de concentração:** n/a (nenhuma recomendação para medir).', $markdown);
        $this->assertStringContainsString('Mais recomendados: nenhum produto recomendado.', $markdown);
    }

    public function test_json_carries_the_new_blocks_after_metrics(): void
    {
        $writer = new EvaluationReportWriter();
        $result = $this->buildResult(0.2, [1 => 1.0, 5 => 0.5], [1 => 0.1, 5 => 0.2], 2, 0);

        $json = json_decode($writer->renderJson($result, '2026-09-24', self::CATALOG), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['algorithm', 'measured_at', 'catalog', 'split', 'relevance', 'queries', 'metrics', 'coverage', 'diversity', 'concentration'],
            array_keys($json)
        );
        $this->assertSame(8, $json['coverage']['candidate_products']);
        $this->assertSame(0.25, $json['coverage']['catalog_coverage_at_k']['1']);
        $this->assertNull($json['diversity']['intra_list_diversity_at_k']['1']);
        $this->assertSame('category', $json['diversity']['distance']);
        $this->assertSame(0.1, $json['concentration']['top_share_ratio']);
        $this->assertSame(0.5, $json['concentration']['excessive_top_share']);
        $this->assertSame(1, $json['concentration']['top_products']);
        $this->assertTrue($json['concentration']['excessive_at_k']['1']);
        $this->assertSame(['product_id' => 3, 'appearances' => 4], $json['concentration']['most_recommended'][0]);
    }

    public function test_concentration_signal_helper(): void
    {
        $this->assertSame('excessiva em k = 1, 10', EvaluationReportWriter::concentrationSignal([1 => true, 5 => false, 10 => true]));
        $this->assertSame('nenhuma concentração excessiva', EvaluationReportWriter::concentrationSignal([1 => false, 5 => null]));
        $this->assertSame('n/a (nenhuma recomendação para medir)', EvaluationReportWriter::concentrationSignal([1 => null]));
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
            'coverage' => [
                'candidate_products' => 8,
                'lists' => 2,
                'covered_products_at_k' => [1 => 2, 5 => 5],
                'catalog_coverage_at_k' => [1 => 0.25, 5 => 0.625],
            ],
            'diversity' => [
                'distance' => 'category',
                'intra_list_diversity_at_k' => [1 => null, 5 => 0.4],
                'distinct_categories_at_k' => [1 => 1.0, 5 => 2.0],
            ],
            'concentration' => [
                'top_share_ratio' => 0.1,
                'excessive_top_share' => 0.5,
                'top_products' => 1,
                'gini_at_k' => [1 => 0.75, 5 => 0.3],
                'top_share_at_k' => [1 => 0.5, 5 => 0.25],
                'excessive_at_k' => [1 => true, 5 => false],
                'most_recommended' => [
                    ['product_id' => 3, 'appearances' => 4],
                    ['product_id' => 7, 'appearances' => 1],
                ],
            ],
        ];
    }
}
