<?php

declare(strict_types=1);

namespace Tests\docker;

use PHPUnit\Framework\TestCase;

/**
 * Testes para tests/docker/load-env-defaults.sh (load_env_defaults).
 * Cobre a matriz I/O: env exportado preservado, defaults para ausentes,
 * linhas não-atribuição ignoradas e última linha sem newline lida.
 */
class LoadEnvDefaultsScriptTest extends TestCase
{
    private const HELPER = __DIR__ . '/load-env-defaults.sh';

    private string $envFile;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'env-defaults-');
        self::assertIsString($file);
        $this->envFile = $file;
    }

    protected function tearDown(): void
    {
        if (is_file($this->envFile)) {
            unlink($this->envFile);
        }
    }

    public function test_exported_variable_is_preserved_and_missing_ones_get_defaults(): void
    {
        file_put_contents($this->envFile, "SESSION_COOKIE_SECRET=\nDB_PASSWORD=secret\nDB_HOST=mysql\n");

        $output = $this->runHelper(
            ['SESSION_COOKIE_SECRET' => 'abc'],
            ['SESSION_COOKIE_SECRET', 'DB_PASSWORD', 'DB_HOST'],
        );

        self::assertSame([
            'SESSION_COOKIE_SECRET' => 'abc',
            'DB_PASSWORD' => 'secret',
            'DB_HOST' => 'mysql',
        ], $output);
    }

    public function test_variable_defined_as_empty_in_environment_is_not_overwritten(): void
    {
        file_put_contents($this->envFile, "DB_PASSWORD=secret\n");

        $output = $this->runHelper(['DB_PASSWORD' => ''], ['DB_PASSWORD']);

        self::assertSame(['DB_PASSWORD' => ''], $output);
    }

    public function test_comments_blank_lines_and_invalid_names_are_ignored_and_last_line_without_newline_is_read(): void
    {
        file_put_contents(
            $this->envFile,
            "# comentário\n\n   # comentário indentado\nnot an assignment\n1INVALID=x\nBAD-NAME=y\nAPP_ENV=local\nLAST_LINE=ultima",
        );

        $output = $this->runHelper([], ['APP_ENV', 'LAST_LINE', '1INVALID']);

        self::assertSame([
            'APP_ENV' => 'local',
            'LAST_LINE' => 'ultima',
            '1INVALID' => '<unset>',
        ], $output);
    }

    public function test_keys_named_like_helper_internals_are_assigned(): void
    {
        file_put_contents($this->envFile, "file=f\nline=l\nkey=k\nvalue=v\n");

        $output = $this->runHelper([], ['file', 'line', 'key', 'value']);

        self::assertSame(['file' => 'f', 'line' => 'l', 'key' => 'k', 'value' => 'v'], $output);
    }

    public function test_missing_file_is_a_no_op(): void
    {
        unlink($this->envFile);

        $output = $this->runHelper(['DB_PASSWORD' => 'keep'], ['DB_PASSWORD']);

        self::assertSame(['DB_PASSWORD' => 'keep'], $output);
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $names
     * @return array<string, string>
     */
    private function runHelper(array $env, array $names): array
    {
        $printers = '';
        foreach ($names as $name) {
            // Nomes inválidos não podem ser expandidos em bash; reporta como <unset>.
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
                $printers .= sprintf("printf '%%s=<unset>\\n' %s\n", escapeshellarg($name));

                continue;
            }
            $printers .= sprintf(
                "if [ -n \"\${%1\$s+x}\" ]; then printf '%%s=%%s\\n' %1\$s \"\$%1\$s\"; else printf '%%s=<unset>\\n' %1\$s; fi\n",
                $name,
            );
        }

        // Exporta o ambiente dentro do bash: proc_open descarta variáveis com valor vazio.
        $exports = '';
        foreach ($env as $name => $value) {
            $exports .= sprintf("export %s=%s\n", $name, escapeshellarg($value));
        }

        $script = sprintf(
            "set -e\n%ssource %s\nload_env_defaults %s\n%s",
            $exports,
            escapeshellarg(self::HELPER),
            escapeshellarg($this->envFile),
            $printers,
        );

        $processEnv = ['PATH' => (string) getenv('PATH')];
        $process = proc_open(
            ['bash', '-c', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $processEnv,
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        self::assertSame(0, $exitCode, 'bash falhou: ' . $stderr);

        $result = [];
        foreach (explode("\n", trim($stdout)) as $line) {
            [$key, $value] = explode('=', $line, 2);
            $result[$key] = $value;
        }

        return $result;
    }
}
