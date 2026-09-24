<?php

declare(strict_types=1);

namespace App\Application\Recommendation\Evaluation;

use RuntimeException;

/**
 * Story 10.2: renders an OfflineEvaluation result as a versionable report
 * (JSON + Markdown in PT-BR) and writes both files to an output directory.
 *
 * The only non-deterministic field is measured_at: everything else comes
 * from the result and the catalog, so re-running with the same seed and
 * catalog rewrites the same bytes (except the date).
 */
final class EvaluationReportWriter
{
    public const BASENAME = 'offline-evaluation';

    /**
     * @param array<string, mixed> $result OfflineEvaluation::run() output
     * @param array{path: string, products: int, sha256: string} $catalog
     * @return array{json: string, markdown: string} paths written
     */
    public function write(array $result, string $measuredAt, array $catalog, string $outputDir): array
    {
        $json = $this->renderJson($result, $measuredAt, $catalog);
        $markdown = $this->renderMarkdown($result, $measuredAt, $catalog);

        if (! is_dir($outputDir) && ! @mkdir($outputDir, 0775, true) && ! is_dir($outputDir)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório de saída: %s', $outputDir));
        }

        $base = rtrim($outputDir, '/') . '/' . self::BASENAME;
        $paths = ['json' => $base . '.json', 'markdown' => $base . '.md'];

        foreach ([$paths['json'] => $json, $paths['markdown'] => $markdown] as $path => $contents) {
            if (@file_put_contents($path, $contents) === false) {
                throw new RuntimeException(sprintf('Não foi possível gravar o relatório: %s', $path));
            }
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $result
     * @param array{path: string, products: int, sha256: string} $catalog
     */
    public function renderJson(array $result, string $measuredAt, array $catalog): string
    {
        $report = [
            'algorithm' => $result['algorithm'],
            'measured_at' => $measuredAt,
            'catalog' => [
                'path' => $catalog['path'],
                'products' => $catalog['products'],
                'sha256' => substr($catalog['sha256'], 0, 12),
            ],
            'split' => $result['split'],
            'relevance' => $result['relevance'],
            'queries' => $result['queries'],
            'metrics' => $result['metrics'],
        ];

        return json_encode(
            $report,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
            | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    /**
     * @param array<string, mixed> $result
     * @param array{path: string, products: int, sha256: string} $catalog
     */
    public function renderMarkdown(array $result, string $measuredAt, array $catalog): string
    {
        $split = $result['split'];
        $queries = $result['queries'];
        $precision = $result['metrics']['precision_at_k'];
        $recall = $result['metrics']['recall_at_k'];
        $ratioPercent = rtrim(rtrim(number_format($split['holdout_ratio'] * 100, 2, '.', ''), '0'), '.');

        $lines = [
            '# Avaliação offline do recomendador',
            '',
            '<!-- Gerado por bin/evaluate.php (make eval). Não edite à mão: rode o harness de novo. -->',
            '',
            sprintf('- **Algoritmo:** `%s`', $result['algorithm']),
            sprintf('- **Medido em:** %s', $measuredAt),
            sprintf(
                '- **Catálogo:** `%s` (%d produtos, sha256 `%s`)',
                $catalog['path'],
                $catalog['products'],
                substr($catalog['sha256'], 0, 12)
            ),
            sprintf(
                '- **Split:** seed %d, holdout de %s%% → %d produtos no treino e %d no holdout',
                $split['seed'],
                $ratioPercent,
                $split['train_size'],
                $split['holdout_size']
            ),
            sprintf(
                '- **Consultas:** %d avaliadas, %d sem nenhum relevante no treino (fora das médias)',
                $queries['evaluated'],
                $queries['without_relevant']
            ),
            '',
            '## Resultado',
            '',
            '| k | precision@k | recall@k |',
            '|---:|---:|---:|',
        ];

        foreach ($precision as $k => $value) {
            $lines[] = sprintf('| %d | %s | %s |', $k, $this->formatMetric($value), $this->formatMetric($recall[$k] ?? null));
        }

        $lines = array_merge($lines, [
            '',
            'Macro-média sobre as consultas avaliadas. precision@k = acertos no top-k / k, sempre dividido por k, '
                . 'mesmo quando a lista vem menor. recall@k = acertos no top-k / total de relevantes da consulta.',
            '',
            '## Como reproduzir',
            '',
            '```bash',
            'make eval                                   # = php bin/evaluate.php (sem Docker e sem banco)',
            sprintf('php bin/evaluate.php --seed=%d --catalog=%s', $split['seed'], $catalog['path']),
            '```',
            '',
            'Mesma seed e mesmo catálogo produzem o mesmo split e as mesmas métricas; só a data muda.',
            '',
            '## Como o split é feito',
            '',
            'O catálogo é ordenado por id e embaralhado com `Random\\Randomizer(new Random\\Engine\\Mt19937($seed))`. '
                . 'Os primeiros `max(1, round(n × proporção))` produtos formam o holdout, e o resto é o treino. '
                . 'O KNN (`KNNService` + `RubixNeighborFinder`) é treinado só com o treino. Cada produto do holdout '
                . 'é uma consulta: o harness pede o top-k direto à estratégia, sem passar pelo fallback de cold-start.',
            '',
            '## Definição de relevância',
            '',
            'Para uma consulta `q` do holdout, os relevantes são os produtos **do treino** com a mesma categoria de `q` '
                . '(`same_category`). Não há rótulo de preferência de usuário no catálogo, então a categoria é o único '
                . 'rótulo disponível sem banco e sem eventos.',
            '',
            '## Limitações',
            '',
            '- **Circuito fechado:** a categoria também é feature do KNN, e pela distância a mesma categoria sempre fica '
                . 'mais perto. O número mede o quanto o modelo respeita a categoria, e não o gosto do usuário.',
            '- **precision@k cai por falta de relevantes, não por erro:** quando a categoria da consulta tem menos de k '
                . 'produtos no treino, os acertos não chegam a k e a precisão fica abaixo de 1 mesmo com o ranking perfeito.',
            '- **Catálogo fixo:** o catálogo versionado (`database/fixtures/evaluation-catalog.json`) foi gerado uma vez '
                . 'com a distribuição do `ProductSeeder`. Não é o catálogo do banco, que muda a cada seed.',
            '- **Fora deste relatório:** cobertura de catálogo, diversidade e concentração (Story 10.3) e '
                . 'cold-start/fallback (Story 10.4).',
            '',
        ]);

        return implode("\n", $lines);
    }

    private function formatMetric(mixed $value): string
    {
        return $value === null ? 'n/a' : sprintf('%.4f', (float) $value);
    }
}
