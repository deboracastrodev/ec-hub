<?php

declare(strict_types=1);

namespace Tests\Integration\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * bin/compare-knn-rewrite.php: before/after da reescrita do KNN citado no
 * LEARNING_JOURNAL.md (Desafio 2). Os cenários que dependem de 4a3f37d^ são
 * pulados em clone raso (o checkout padrão do CI).
 */
final class CompareKnnRewriteScriptTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const SCRIPT = self::ROOT . '/bin/compare-knn-rewrite.php';
    private const JOURNAL = self::ROOT . '/LEARNING_JOURNAL.md';
    private const LEGACY_REVISION = '4a3f37d39dcd03f23abe56a9c275d01d4a24f76d^';

    /** Números publicados no LEARNING_JOURNAL.md, seção Before / After do Desafio 2. */
    private const EXPECTED_ROWS = [
        '| 20 | 20 | 20/20 | 19/20 | 0 | 4 → 10 |',
        '| 100 | 100 | 98/100 | 74/100 | 2.84e-14 | 4 → 10 |',
    ];

    /** @var array{0: int, 1: string, 2: string}|null saída da execução com histórico completo */
    private static ?array $fullRun = null;

    public function test_prints_before_after_table_with_full_history(): void
    {
        [$exitCode, $stdout] = $this->fullRun();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('| Produtos | Alvos | Mesmo conjunto (limit=4) |', $stdout);
        foreach (self::EXPECTED_ROWS as $row) {
            $this->assertStringContainsString($row, $stdout);
        }
        $this->assertSame(27, substr_count($stdout, 'explicada por empate de distância: sim'));
        $this->assertStringNotContainsString('explicada por empate de distância: não', $stdout);
    }

    public function test_journal_publishes_the_rows_the_script_prints(): void
    {
        [, $stdout] = $this->fullRun();

        $journal = (string) file_get_contents(self::JOURNAL);
        preg_match_all('/^\| \d+ \| \d+ \| .+\|$/m', $stdout, $rows);
        $this->assertCount(2, $rows[0]);
        foreach ($rows[0] as $row) {
            $this->assertStringContainsString($row, $journal);
        }
    }

    public function test_output_has_no_rubix_deprecation_noise(): void
    {
        [, $stdout, $stderr] = $this->fullRun();

        $this->assertStringNotContainsString('Deprecated', $stdout . $stderr);
    }

    public function test_fails_loudly_when_legacy_revision_is_missing(): void
    {
        exec('command -v git >/dev/null 2>&1', $ignored, $hasGit);
        if ($hasGit !== 0) {
            $this->markTestSkipped('git ausente no PATH.');
        }

        // Um repositório git vazio no lugar do real simula o histórico sem 4a3f37d^.
        $emptyGitDir = sys_get_temp_dir() . '/knn-empty-git-' . bin2hex(random_bytes(4));
        exec('git init --bare -q ' . escapeshellarg($emptyGitDir), $ignored, $initStatus);
        $this->assertSame(0, $initStatus, 'git init falhou');

        try {
            [$exitCode, $stdout, $stderr] = $this->runScript(['GIT_DIR' => $emptyGitDir]);
        } finally {
            exec('rm -rf ' . escapeshellarg($emptyGitDir));
        }

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('git fetch --unshallow', $stderr);
        $this->assertStringNotContainsString('| Produtos |', $stdout);
    }

    /**
     * Roda o script uma vez só para os testes que dependem do histórico.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function fullRun(): array
    {
        exec('git -C ' . escapeshellarg(self::ROOT) . ' cat-file -e ' . self::LEGACY_REVISION . ' 2>/dev/null', $ignored, $status);
        if ($status !== 0) {
            $this->markTestSkipped('4a3f37d^ ausente (clone raso).');
        }

        return self::$fullRun ??= $this->runScript();
    }

    /**
     * @param array<string, string> $extraEnv
     * @return array{0: int, 1: string, 2: string}
     */
    private function runScript(array $extraEnv = []): array
    {
        $process = proc_open(
            [PHP_BINARY, self::SCRIPT],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            self::ROOT,
            array_merge(getenv(), $extraEnv)
        );
        $this->assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
