<?php

declare(strict_types=1);

namespace Tests\Integration\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * bin/ci/check-readme-paths.php (R6.1, job "Composer + style"): links do
 * README para arquivos do repositório precisam existir e, com #fragmento,
 * apontar para um título que o GitHub gera no Markdown de destino. Roda o
 * script de verdade: no README do repositório e numa cópia com fixtures.
 */
final class CheckReadmePathsScriptTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const SCRIPT = 'bin/ci/check-readme-paths.php';

    private const GUIDE = <<<'MD'
        # Guia

        ### Métricas (`GET /api/metrics`)

        ## Repetido

        ## Repetido

        ```bash
        # comentario no bloco
        ```

        ## 11. Limitações conhecidas
        MD;

    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            foreach (['README.md', '.env.example', 'docs/guide.md', self::SCRIPT] as $file) {
                @unlink($this->fixtureRoot . '/' . $file);
            }
            foreach (['bin/ci', 'bin', 'docs', ''] as $dir) {
                @rmdir($this->fixtureRoot . '/' . $dir);
            }
        }
    }

    public function test_repository_readme_links_resolve(): void
    {
        [$exitCode, $output] = $this->runScript(self::ROOT);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('file paths and sections exist', $output);
    }

    public function test_links_with_fragments_resolve_to_the_file_and_to_a_github_heading_slug(): void
    {
        $root = $this->fixture(<<<'MD'
            # Projeto

            ### Painel admin

            [métricas](docs/guide.md#métricas-get-apimetrics)
            [limitações](docs/guide.md#11-limitações-conhecidas)
            [segundo repetido](docs/guide.md#repetido-1)
            [interno](#painel-admin)
            [topo](./docs/guide.md)
            [dotfile](.env.example)
            [site](https://example.com/nada.md#nada)
            MD);

        [$exitCode, $output] = $this->runScript($root);

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('All 7 README.md links checked', $output);
    }

    public function test_missing_files_and_missing_sections_fail_the_build(): void
    {
        $root = $this->fixture(<<<'MD'
            [ok](docs/guide.md#repetido)
            [sem arquivo](docs/nao-existe.md#repetido)
            [sem seção](docs/guide.md#secao-inexistente)
            [comentário de shell não é título](docs/guide.md#comentario-no-bloco)
            [terceiro repetido](docs/guide.md#repetido-2)
            MD);

        [$exitCode, $output] = $this->runScript($root);

        self::assertSame(1, $exitCode, $output);
        self::assertStringContainsString("README.md links to paths that don't exist: docs/nao-existe.md#repetido\n", $output);
        self::assertStringContainsString(
            "README.md links to sections that don't exist: docs/guide.md#secao-inexistente, docs/guide.md#comentario-no-bloco, docs/guide.md#repetido-2\n",
            $output
        );
    }

    /** Cópia do script numa raiz temporária: ele resolve os links a partir de dirname(__DIR__, 2). */
    private function fixture(string $readme): string
    {
        $root = sys_get_temp_dir() . '/ec-hub-readme-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/bin/ci', 0777, true) && mkdir($root . '/docs'));
        $this->fixtureRoot = $root;

        copy(self::ROOT . '/' . self::SCRIPT, $root . '/' . self::SCRIPT);
        file_put_contents($root . '/README.md', $readme . "\n");
        file_put_contents($root . '/docs/guide.md', self::GUIDE . "\n");
        file_put_contents($root . '/.env.example', "APP_ENV=local\n");

        return $root;
    }

    /**
     * stderr é redirecionado para o stdout: um único pipe, lido até o fim,
     * não trava se o processo encher o buffer de um dos dois.
     *
     * @return array{0: int, 1: string}
     */
    private function runScript(string $root): array
    {
        $process = proc_open(
            [PHP_BINARY, (string) realpath($root . '/' . self::SCRIPT)],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            (string) realpath($root)
        );
        self::assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
