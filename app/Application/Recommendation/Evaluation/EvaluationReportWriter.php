<?php

declare(strict_types=1);

namespace App\Application\Recommendation\Evaluation;

use RuntimeException;

/**
 * Story 10.2: renders an OfflineEvaluation result as a versionable report
 * (JSON + Markdown in PT-BR) and writes both files to an output directory.
 * Story 10.3: adds the coverage, diversity and concentration blocks and the
 * explicit concentration signal. Story 10.4: adds the cold_start block
 * (ColdStartEvaluation) -- fallback activation rate and the ML and fallback
 * populations side by side.
 *
 * The only non-deterministic field is measured_at: everything else comes
 * from the result and the catalog, so re-running with the same seed and
 * catalog rewrites the same bytes (except the date).
 */
final class EvaluationReportWriter
{
    public const BASENAME = 'offline-evaluation';

    /**
     * @param array<string, mixed> $result OfflineEvaluation::run() output, optionally with the
     *        key 'cold_start' (ColdStartEvaluation::run() output, Story 10.4); without it the
     *        cold-start JSON block and Markdown section are omitted
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
            'coverage' => $result['coverage'],
            'diversity' => $result['diversity'],
            'concentration' => $result['concentration'],
        ];
        if (isset($result['cold_start'])) {
            $report['cold_start'] = $result['cold_start'];
        }

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
        ]);

        $hasColdStart = isset($result['cold_start']);
        $coldStartLines = $hasColdStart ? $this->coldStartSection($result['cold_start']) : [];

        $lines = array_merge($lines, $this->catalogSection($result), $coldStartLines, [
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
                . 'é uma consulta: nas seções de resultado e de cobertura, o harness pede o top-k direto à estratégia, '
                . 'sem passar pelo fallback de cold-start.'
                . ($hasColdStart ? ' A seção de cold-start passa pelo caso de uso real.' : ''),
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
            $this->sampleSizeLimitation($result),
        ]);

        if ($hasColdStart) {
            $lines = array_merge($lines, [
                '- **Relevância por categoria favorece o fallback por categoria:** o fallback `category` recomenda '
                    . 'produtos da mesma categoria da consulta, que é justamente a definição de relevante. A precision '
                    . 'alta dele é circuito fechado, e não prova de recomendação melhor.',
                '- **"Popularidade" sem sinal de popularidade:** o fallback `popularity` pega os primeiros N produtos '
                    . 'do catálogo e os embaralha (não há contagem de visualizações no repositório). Por isso tende a '
                    . 'repetir os mesmos produtos em todas as listas. Aqui a ordem vem do repositório em memória do '
                    . 'harness, e o embaralhamento usa a seed do split.',
            ]);
        }

        $lines = array_merge($lines, [
            '- **Fora deste relatório:** `/metrics` e README (Story 10.5).',
            '',
        ]);

        return implode("\n", $lines);
    }

    /**
     * Story 10.3 section: coverage, intra-list diversity and concentration per k.
     *
     * @param array<string, mixed> $result
     * @return list<string>
     */
    private function catalogSection(array $result): array
    {
        $coverage = $result['coverage'];
        $diversity = $result['diversity'];
        $concentration = $result['concentration'];
        $ratioPercent = $this->formatPercent($concentration['top_share_ratio']);
        $thresholdPercent = $this->formatPercent($concentration['excessive_top_share']);

        $lines = [
            '## Cobertura, diversidade e concentração',
            '',
            sprintf(
                'Medido sobre as %d listas do holdout (uma por consulta, com ou sem relevante no treino). '
                    . 'Candidatos: os %d produtos do treino, os únicos que o índice pode recomendar.',
                $coverage['lists'],
                $coverage['candidate_products']
            ),
            '',
            '| k | cobertura | cobertos/candidatos | ILD | categorias distintas | Gini | top share | concentração |',
            '|---:|---:|---:|---:|---:|---:|---:|:---|',
        ];

        foreach ($coverage['catalog_coverage_at_k'] as $k => $value) {
            $excessive = $concentration['excessive_at_k'][$k] ?? null;
            $lines[] = sprintf(
                '| %d | %s | %d/%d | %s | %s | %s | %s | %s |',
                $k,
                $this->formatMetric($value),
                $coverage['covered_products_at_k'][$k] ?? 0,
                $coverage['candidate_products'],
                $this->formatMetric($diversity['intra_list_diversity_at_k'][$k] ?? null),
                $this->formatMetric($diversity['distinct_categories_at_k'][$k] ?? null),
                $this->formatMetric($concentration['gini_at_k'][$k] ?? null),
                $this->formatMetric($concentration['top_share_at_k'][$k] ?? null),
                $excessive === null ? 'n/a' : ($excessive ? 'excessiva' : 'ok')
            );
        }

        $lines[] = '';
        $lines[] = '**Sinal de concentração:** ' . self::concentrationSignal($concentration['excessive_at_k']) . '.';
        $lines[] = '';

        $maxK = $coverage['catalog_coverage_at_k'] === [] ? null : max(array_keys($coverage['catalog_coverage_at_k']));
        if ($concentration['most_recommended'] === [] || $maxK === null) {
            $lines[] = 'Mais recomendados: nenhum produto recomendado.';
        } else {
            $lines[] = sprintf('Mais recomendados (k = %d):', $maxK);
            $lines[] = '';
            foreach ($concentration['most_recommended'] as $item) {
                $lines[] = sprintf(
                    '- produto %d: %d %s',
                    $item['product_id'],
                    $item['appearances'],
                    $item['appearances'] === 1 ? 'aparição' : 'aparições'
                );
            }
        }

        return array_merge($lines, [
            '',
            '### Definições',
            '',
            '- **Cobertura de catálogo@k:** produtos distintos do treino que aparecem em pelo menos uma lista@k, '
                . 'divididos pelo total de candidatos. A coluna cobertos/candidatos traz a contagem e o denominador, '
                . 'para quem quiser dividir pelo catálogo inteiro.',
            '- **Diversidade intra-lista@k (ILD):** distância por categoria (0 se igual, 1 se diferente). Numa lista '
                . 'com n ≥ 2 itens, ILD = pares de categorias diferentes / (n·(n−1)/2). O valor é a média sobre as listas '
                . 'com n ≥ 2, e fica n/a quando não há nenhuma (sempre em k = 1). Categorias distintas = média de '
                . 'categorias diferentes por lista, com lista vazia contando 0. Para cobertura, diversidade e concentração, '
                . 'um id repetido dentro da mesma lista conta uma vez (a primeira ocorrência).',
            '- **Leitura esperada da ILD:** a categoria domina a distância do KNN, então a ILD tende a ficar perto de 0 '
                . 'enquanto a categoria da consulta tem itens suficientes no treino, e sobe quando eles acabam. '
                . 'É consequência do modelo, e o número não foi ajustado.',
            '- **Gini@k:** Σᵢ(2i − n − 1)·cᵢ / (n·Σc) sobre as aparições de cada candidato nas listas@k, incluindo os '
                . 'que aparecem 0 vezes (contagens em ordem crescente). 0 = recomendações espalhadas por igual; perto '
                . 'de 1 = poucas recomendações dominam.',
            sprintf(
                '- **Top share@k:** fração das aparições que fica com os `ceil(%s × candidatos)` candidatos mais '
                    . 'recomendados (os %s%% do catálogo recomendável mais recomendados): aqui, os %d candidatos mais '
                    . 'recomendados (`top_products`).',
                $this->formatRatio($concentration['top_share_ratio']),
                $ratioPercent,
                $concentration['top_products']
            ),
            sprintf(
                '- **Concentração excessiva:** top share ≥ %s, ou seja, %s%% do catálogo recomendável fica com %s%% '
                    . 'ou mais das recomendações. O limiar foi fixado antes da medição.',
                $this->formatRatio($concentration['excessive_top_share']),
                $ratioPercent,
                $thresholdPercent
            ),
            '',
        ]);
    }

    /**
     * Story 10.4 section: fallback activation and the ML x fallback populations.
     *
     * @param array<string, mixed> $coldStart
     * @return list<string>
     */
    private function coldStartSection(array $coldStart): array
    {
        $activation = $coldStart['activation'];
        $ml = $coldStart['populations']['ml'];
        $fallback = $coldStart['populations']['fallback'];

        $lines = [
            '## Cold-start e fallback',
            '',
            sprintf(
                'Cenário `%s`: cada consulta do holdout passa pelo caso de uso real (`GenerateRecommendations`), '
                    . 'com o catálogo = treino + a própria consulta e o modelo treinado só com o treino. '
                    . 'Cada execução pede `limit` = %d itens (o maior k).',
                $coldStart['scenario'],
                $coldStart['limit']
            ),
            '',
            self::activationLine($activation),
            '',
            sprintf(
                'População ML: %d listas (%d consultas avaliadas, %d sem relevante). '
                    . 'População fallback: %d listas (%d consultas avaliadas, %d sem relevante).',
                $ml['coverage']['lists'],
                $ml['queries']['evaluated'],
                $ml['queries']['without_relevant'],
                $fallback['coverage']['lists'],
                $fallback['queries']['evaluated'],
                $fallback['queries']['without_relevant']
            ),
            '',
            '| k | precision ML | precision fallback | recall ML | recall fallback | cobertura ML | cobertura fallback '
                . '| ILD ML | ILD fallback |',
            '|---:|---:|---:|---:|---:|---:|---:|---:|---:|',
        ];

        foreach ($ml['metrics']['precision_at_k'] as $k => $precision) {
            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s | %s | %s | %s | %s |',
                $k,
                $this->formatMetric($precision),
                $this->formatMetric($fallback['metrics']['precision_at_k'][$k] ?? null),
                $this->formatMetric($ml['metrics']['recall_at_k'][$k] ?? null),
                $this->formatMetric($fallback['metrics']['recall_at_k'][$k] ?? null),
                $this->formatMetric($ml['coverage']['catalog_coverage_at_k'][$k] ?? null),
                $this->formatMetric($fallback['coverage']['catalog_coverage_at_k'][$k] ?? null),
                $this->formatMetric($ml['diversity']['intra_list_diversity_at_k'][$k] ?? null),
                $this->formatMetric($fallback['diversity']['intra_list_diversity_at_k'][$k] ?? null)
            );
        }

        return array_merge($lines, [
            '',
            'Nota: a relevância é a mesma categoria da consulta, e o fallback `category` recomenda exatamente essa '
                . 'categoria. A precision do fallback é circuito fechado, e não prova de recomendação melhor que a do ML.',
            '',
            '**Sinal de concentração (ML):** ' . self::concentrationSignal($ml['concentration']['excessive_at_k']) . '.',
            '',
            '**Sinal de concentração (fallback):** '
                . self::concentrationSignal($fallback['concentration']['excessive_at_k']) . '.',
            '',
            '### Definições do cold-start',
            '',
            '- **Cenário `new_product`:** o produto da consulta está no catálogo, como na produção (a consulta vem '
                . 'de uma página de produto), mas o modelo foi treinado sem ele: é o cold-start de item de um modelo '
                . 'treinado em batch. A chamada não tem sessão nem usuário, então não há personalização.',
            sprintf(
                '- **Ativação do fallback:** consultas em que a resposta servida (pedindo `limit` = %d) tem pelo menos '
                    . 'um item com `source` diferente de `ml`, sobre o total de consultas. Itens de fallback = itens '
                    . '`rules` ou `popular` sobre o total de itens servidos. A ativação depende do `limit`: com um '
                    . 'limite maior, uma lista ML curta fica mais provável.',
                $coldStart['limit']
            ),
            '- **População ML (`served_ml_items`):** a lista servida de cada consulta, filtrada para os itens `ml`, '
                . 'na ordem. Consultas sem nenhum item ML ficam fora.',
            sprintf(
                '- **População fallback (`forced_fallback`):** a lista que o caso de uso devolve com o fallback forçado '
                    . '(`insufficientData: true`), para todas as consultas, com a estratégia de fallback padrão (`%s`). '
                    . 'É contrafactual: mostra o que seria servido se o fallback ativasse, e não tráfego real. '
                    . 'É o ramo de fallback completo (a lista inteira vem das regras), e não o completamento de uma '
                    . 'lista ML curta.',
                $coldStart['fallback_strategy']
            ),
            '- **Por que a ativação no cenário `new_product` é rara ou nula:** o KNN por conteúdo responde a qualquer '
                . 'produto que tenha features, mesmo sem tê-lo visto no treino. O fallback só entra quando a lista ML '
                . 'vem mais curta que o pedido, quando o id é desconhecido, quando o ML falha ou quando o catálogo é '
                . 'pequeno demais para o ML. Por isso a população de fallback é medida forçando o ramo.',
            '- **Mesmas métricas:** as duas populações usam as mesmas fórmulas, a mesma relevância (`same_category` '
                . 'do treino), os mesmos candidatos (o treino) e o mesmo limiar de concentração das seções acima.',
            '',
        ]);
    }

    /**
     * The activation line, shared by the Markdown report and (in plain text) the CLI summary.
     *
     * @param array{queries: int, activated: int, activation_rate: float|null, items: int, fallback_items: int} $activation
     */
    public static function activationLine(array $activation): string
    {
        return sprintf(
            '**Ativação do fallback:** %d de %d consultas (%s) · itens de fallback: %d/%d',
            $activation['activated'],
            $activation['queries'],
            self::formatRate($activation['activation_rate']),
            $activation['fallback_items'],
            $activation['items']
        );
    }

    /** A 0..1 rate as a percentage without trailing zeros (0.0625 → 6.25%), or n/a. */
    public static function formatRate(?float $rate): string
    {
        return $rate === null
            ? 'n/a'
            : rtrim(rtrim(number_format($rate * 100, 2, '.', ''), '0'), '.') . '%';
    }

    /**
     * Limitation bullet: with few appearances (lists × k) relative to the candidates,
     * the minimum possible top share is top_products / appearances.
     *
     * @param array<string, mixed> $result
     */
    private function sampleSizeLimitation(array $result): string
    {
        $text = '- **Top share sensível ao tamanho da amostra:** com listas cheias há listas × k aparições. Quando esse '
            . 'número é pequeno perto do número de candidatos, o menor top share possível (cada aparição num produto '
            . 'diferente) é `top_products / aparições`, então um k pequeno infla o top share e o Gini.';

        $ks = array_keys($result['coverage']['catalog_coverage_at_k']);
        $appearances = $ks === [] ? 0 : $result['coverage']['lists'] * min($ks);
        if ($appearances > 0) {
            $top = min($result['concentration']['top_products'], $appearances);
            $text .= sprintf(
                ' Aqui, em k = %d: %d/%d = %s.',
                min($ks),
                $top,
                $appearances,
                $this->formatMetric($top / $appearances)
            );
        }

        return $text . ' Isso não torna o sinal inevitável: ele depende de quanto os mesmos produtos se repetem. '
            . 'O limiar não muda por causa disso: ele foi fixado antes da medição.';
    }

    /**
     * The explicit signal line, shared by the Markdown report and the CLI summary.
     *
     * @param array<int, bool|null> $excessiveAtK
     */
    public static function concentrationSignal(array $excessiveAtK): string
    {
        $excessiveKs = array_keys(array_filter($excessiveAtK, static fn (?bool $v): bool => $v === true));
        $measured = array_filter($excessiveAtK, static fn (?bool $v): bool => $v !== null) !== [];

        if ($excessiveKs !== []) {
            return 'excessiva em k = ' . implode(', ', $excessiveKs);
        }

        return $measured ? 'nenhuma concentração excessiva' : 'n/a (nenhuma recomendação para medir)';
    }

    private function formatPercent(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio * 100, 2, '.', ''), '0'), '.');
    }

    private function formatRatio(float $ratio): string
    {
        return rtrim(rtrim(number_format($ratio, 4, '.', ''), '0'), '.');
    }

    private function formatMetric(mixed $value): string
    {
        return $value === null ? 'n/a' : sprintf('%.4f', (float) $value);
    }
}
