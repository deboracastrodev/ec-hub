<?php

declare(strict_types=1);

namespace Tests\Integration\Tooling;

use App\Application\Recommendation\Evaluation\EvaluationReportWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/evaluate.php (make eval, Story 10.2): roda o harness de avaliação
 * offline de verdade (KNN + Rubix), sem Docker e sem banco, e confere que o
 * relatório commitado em docs/evaluation/ bate com uma execução nova.
 */
final class EvaluateScriptTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const SCRIPT = self::ROOT . '/bin/evaluate.php';
    private const COMMITTED_JSON = self::ROOT . '/docs/evaluation/offline-evaluation.json';

    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            self::removeRecursively($dir);
        }
    }

    private static function removeRecursively(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeRecursively($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    public function test_writes_markdown_and_json_with_k_1_5_10(): void
    {
        $dir = $this->tempDir();

        [$exitCode, $stdout, $stderr] = $this->runScript(['--output-dir=' . $dir]);

        $this->assertSame(0, $exitCode, $stderr);
        $this->assertFileExists($dir . '/offline-evaluation.md');
        $this->assertFileExists($dir . '/offline-evaluation.json');
        $this->assertStringContainsString(realpath($dir) . '/offline-evaluation.json', $stdout);
        $this->assertStringNotContainsString('Deprecated', $stdout . $stderr);

        $report = $this->readJson($dir . '/offline-evaluation.json');
        $this->assertSame('knn', $report['algorithm']);
        $this->assertSame('same_category', $report['relevance']);
        $this->assertSame(42, $report['split']['seed']);
        $this->assertSame(['1', '5', '10'], array_map('strval', array_keys($report['metrics']['precision_at_k'])));
        $this->assertSame(['1', '5', '10'], array_map('strval', array_keys($report['metrics']['recall_at_k'])));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $report['measured_at']);
        foreach (
            [
                $report['coverage']['catalog_coverage_at_k'],
                $report['coverage']['covered_products_at_k'],
                $report['diversity']['intra_list_diversity_at_k'],
                $report['diversity']['distinct_categories_at_k'],
                $report['concentration']['gini_at_k'],
                $report['concentration']['top_share_at_k'],
                $report['concentration']['excessive_at_k'],
            ] as $byK
        ) {
            $this->assertSame(['1', '5', '10'], array_map('strval', array_keys($byK)));
        }
        $this->assertSame(64, $report['coverage']['candidate_products']);
        $this->assertSame(16, $report['coverage']['lists']);
        $this->assertNull($report['diversity']['intra_list_diversity_at_k']['1']);
        $this->assertSame(7, $report['concentration']['top_products']);

        // O resumo do stdout vem dos mesmos números que o JSON gravado.
        $coverage = $report['coverage'];
        $this->assertStringContainsString(sprintf(
            "Cobertura, diversidade e concentração (%d listas, %d candidatos do treino):\n",
            $coverage['lists'],
            $coverage['candidate_products']
        ), $stdout);
        $fmt = static fn (mixed $v): string => $v === null ? 'n/a' : sprintf('%.4f', $v);
        foreach ($coverage['catalog_coverage_at_k'] as $k => $value) {
            $this->assertStringContainsString(sprintf(
                "| %3d | %9s | %9s | %6s | %9s |\n",
                $k,
                $fmt($value),
                $coverage['covered_products_at_k'][$k] . '/' . $coverage['candidate_products'],
                $fmt($report['diversity']['intra_list_diversity_at_k'][$k]),
                $fmt($report['concentration']['top_share_at_k'][$k])
            ), $stdout);
        }
        $this->assertStringContainsString(
            "\nSinal de concentração: "
                . EvaluationReportWriter::concentrationSignal($report['concentration']['excessive_at_k']) . "\n",
            $stdout
        );

        $markdown = (string) file_get_contents($dir . '/offline-evaluation.md');
        $this->assertStringContainsString('| k | precision@k | recall@k |', $markdown);
        $this->assertStringContainsString('## Cobertura, diversidade e concentração', $markdown);
        $this->assertMatchesRegularExpression('/^\*\*Sinal de concentração:\*\* /m', $markdown);
        foreach ([1, 5, 10] as $k) {
            $this->assertMatchesRegularExpression('/^\| ' . $k . ' \| \d\.\d{4} \| \d\.\d{4} \|$/m', $markdown);
        }
    }

    public function test_same_seed_gives_identical_report_except_measured_at(): void
    {
        $first = $this->tempDir();
        $second = $this->tempDir();

        $this->assertSame(0, $this->runScript(['--output-dir=' . $first])[0]);
        $this->assertSame(0, $this->runScript(['--output-dir=' . $second])[0]);

        $a = $this->readJson($first . '/offline-evaluation.json');
        $b = $this->readJson($second . '/offline-evaluation.json');
        unset($a['measured_at'], $b['measured_at']);
        $this->assertSame($a, $b);
    }

    public function test_different_seed_changes_the_holdout(): void
    {
        $default = $this->tempDir();
        $seven = $this->tempDir();

        $this->assertSame(0, $this->runScript(['--output-dir=' . $default])[0]);
        $this->assertSame(0, $this->runScript(['--output-dir=' . $seven, '--seed=7'])[0]);

        $this->assertNotSame(
            $this->readJson($default . '/offline-evaluation.json')['split']['holdout_ids'],
            $this->readJson($seven . '/offline-evaluation.json')['split']['holdout_ids']
        );
    }

    public function test_committed_report_matches_a_fresh_run(): void
    {
        $dir = $this->tempDir();
        $this->assertSame(0, $this->runScript(['--output-dir=' . $dir])[0]);

        $fresh = $this->readJson($dir . '/offline-evaluation.json');
        $committed = $this->readJson(self::COMMITTED_JSON);

        $keys = ['algorithm', 'catalog', 'split', 'relevance', 'queries', 'metrics', 'coverage', 'diversity', 'concentration'];
        foreach ($keys as $key) {
            $this->assertSame($fresh[$key], $committed[$key], "docs/evaluation diverge em '{$key}': rode make eval");
        }

        $committedMarkdown = (string) file_get_contents(self::ROOT . '/docs/evaluation/offline-evaluation.md');
        $freshMarkdown = (string) file_get_contents($dir . '/offline-evaluation.md');
        $withoutDate = static fn (string $md): string => (string) preg_replace('/^- \*\*Medido em:\*\* .*$/m', '', $md);
        $this->assertSame($withoutDate($freshMarkdown), $withoutDate($committedMarkdown));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidCatalogs(): iterable
    {
        yield 'inexistente' => [null];
        yield 'JSON inválido' => ['{"id": 1,'];
        yield 'lista vazia' => ['[]'];
        yield 'menos de 3 produtos' => ['[{"id":1,"name":"A","price":10,"category":"X"},{"id":2,"name":"B","price":20,"category":"X"}]'];
        yield 'não é lista' => ['{"id":1,"name":"A","price":10,"category":"X"}'];
        yield 'produto sem categoria' => [self::catalogJson([['category' => null]])];
        yield 'preço não numérico' => [self::catalogJson([['price' => 'dez']])];
        yield 'preço negativo' => [self::catalogJson([['price' => -1.5]])];
        yield 'id não inteiro' => [self::catalogJson([['id' => '1']])];
        yield 'nome vazio' => [self::catalogJson([['name' => '']])];
        yield 'id duplicado' => [self::catalogJson([[], ['id' => 1]])];
    }

    /**
     * Três produtos válidos; $overrides[i] troca campos do produto i (null remove o campo).
     *
     * @param list<array<string, mixed>> $overrides
     */
    private static function catalogJson(array $overrides): string
    {
        $rows = [];
        for ($i = 0; $i < 3; $i++) {
            $row = ['id' => $i + 1, 'name' => 'Produto ' . ($i + 1), 'price' => 10.0 * ($i + 1), 'category' => 'X'];
            foreach ($overrides[$i] ?? [] as $field => $value) {
                if ($value === null) {
                    unset($row[$field]);
                } else {
                    $row[$field] = $value;
                }
            }
            $rows[] = $row;
        }

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    #[DataProvider('invalidCatalogs')]
    public function test_invalid_catalog_exits_1_and_writes_nothing(?string $contents): void
    {
        $workDir = $this->tempDir();
        mkdir($workDir);
        $catalog = $workDir . '/catalog.json';
        if ($contents !== null) {
            file_put_contents($catalog, $contents);
        }
        $outputDir = $workDir . '/out';

        [$exitCode, $stdout, $stderr] = $this->runScript(['--catalog=' . $catalog, '--output-dir=' . $outputDir]);

        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith('Erro: ', $stderr);
        $this->assertSame('', $stdout);
        $this->assertDirectoryDoesNotExist($outputDir);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'seed não numérica' => ['--seed=abc', '--seed precisa ser um inteiro entre 0 e 4294967295'];
        yield 'seed negativa' => ['--seed=-5', '--seed precisa ser um inteiro entre 0 e 4294967295'];
        yield 'seed acima de 32 bits' => ['--seed=4294967296', '--seed precisa ser um inteiro entre 0 e 4294967295'];
        yield 'opção desconhecida' => ['--foo=1', 'opção desconhecida: --foo=1'];
        yield 'opção sem valor' => ['--seed', 'a opção --seed precisa de um valor.'];
        yield 'opção com valor vazio' => ['--seed=', 'a opção --seed precisa de um valor.'];
        yield 'catálogo sem valor' => ['--catalog', 'a opção --catalog precisa de um valor.'];
    }

    #[DataProvider('invalidOptions')]
    public function test_invalid_option_prints_usage_and_writes_nothing(string $option, string $message): void
    {
        $outputDir = $this->tempDir();

        [$exitCode, $stdout, $stderr] = $this->runScript([$option, '--output-dir=' . $outputDir]);

        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith('Erro: ' . $message, $stderr);
        $this->assertStringContainsString('Uso: php bin/evaluate.php', $stderr);
        $this->assertSame('', $stdout);
        $this->assertDirectoryDoesNotExist($outputDir);
    }

    public function test_runs_from_another_cwd_with_default_catalog_and_relative_output(): void
    {
        $cwd = $this->tempDir();
        mkdir($cwd);

        [$exitCode, $stdout, $stderr] = $this->runScript(['--output-dir=relatorio'], $cwd);

        $this->assertSame(0, $exitCode, $stderr);
        // O catálogo padrão é relativo à raiz do projeto, não ao cwd.
        $this->assertStringContainsString('Catálogo: database/fixtures/evaluation-catalog.json (80 produtos)', $stdout);
        // Um --output-dir relativo é relativo ao cwd.
        $this->assertFileExists($cwd . '/relatorio/offline-evaluation.json');
        $this->assertFileExists($cwd . '/relatorio/offline-evaluation.md');
        $this->assertSame(
            $this->readJson(self::COMMITTED_JSON)['metrics'],
            $this->readJson($cwd . '/relatorio/offline-evaluation.json')['metrics']
        );
    }

    public function test_output_dir_pointing_at_a_regular_file_fails(): void
    {
        $file = $this->tempDir();
        file_put_contents($file, 'não é diretório');

        [$exitCode, $stdout, $stderr] = $this->runScript(['--output-dir=' . $file]);

        $this->assertSame(1, $exitCode);
        $this->assertStringStartsWith('Erro: ', $stderr);
        $this->assertSame('não é diretório', file_get_contents($file));
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/ec-hub-eval-' . bin2hex(random_bytes(6));
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * @param list<string> $arguments
     * @param string $cwd working directory of the script process
     * @return array{0: int, 1: string, 2: string}
     */
    private function runScript(array $arguments, string $cwd = self::ROOT): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, (string) realpath(self::SCRIPT)], $arguments),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd
        );
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
