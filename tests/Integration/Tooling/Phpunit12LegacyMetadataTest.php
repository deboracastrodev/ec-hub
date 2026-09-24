<?php

declare(strict_types=1);

namespace Tests\Integration\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Fixa a saída real de tests/Fixtures/Phpunit12/LegacyMetadataExample.php no
 * PHPUnit 12, citada no LEARNING_JOURNAL.md (Desafio 3, "O que NÃO fazer"):
 * metadados em docblock ignorados, provider não-static e assertRegExp removido.
 */
final class Phpunit12LegacyMetadataTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const FIXTURE = 'tests/Fixtures/Phpunit12/LegacyMetadataExample.php';
    private const JOURNAL = self::ROOT . '/LEARNING_JOURNAL.md';

    /** Mensagens publicadas no journal, uma por hábito legado. */
    private const EXPECTED_MESSAGES = [
        'ArgumentCountError: Too few arguments to function Tests\Fixtures\Phpunit12\LegacyMetadataExample::test_docblock_data_provider(), 0 passed',
        'Data Provider method Tests\Fixtures\Phpunit12\LegacyMetadataExample::naoStatic() is not static',
        'Error: Call to undefined method Tests\Fixtures\Phpunit12\LegacyMetadataExample::assertRegExp()',
    ];

    private const EXPECTED_SUMMARY = 'Tests: 3, Assertions: 1, Errors: 3.';

    /** Resumo de --exclude-group db --filter test_docblock_group_db, também publicado no journal. */
    private const GROUP_SUMMARY = 'Tests: 1, Assertions: 1, Errors: 1.';

    /** @var array{0: int, 1: string}|null */
    private static ?array $fixtureRun = null;

    public function test_fixture_fails_with_the_three_legacy_errors(): void
    {
        [$exitCode, $output] = $this->fixtureRun();

        $this->assertSame(2, $exitCode);
        foreach (self::EXPECTED_MESSAGES as $message) {
            $this->assertStringContainsString($message, $output);
        }
        $this->assertStringContainsString(self::EXPECTED_SUMMARY, $output);
    }

    public function test_docblock_group_is_ignored_by_exclude_group(): void
    {
        [$exitCode, $output] = $this->runPhpunit([
            '--no-configuration',
            '--bootstrap', 'vendor/autoload.php',
            '--exclude-group', 'db',
            '--filter', 'test_docblock_group_db',
            self::FIXTURE,
        ]);

        // O teste marcado "@group db" roda mesmo com --exclude-group db. O erro
        // (e o exit 2) vem do provider não-static, que dispara com qualquer --filter.
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString(self::EXPECTED_MESSAGES[1], $output);
        $this->assertStringContainsString(self::GROUP_SUMMARY, $output);
        $this->assertMatchesRegularExpression('/^\.\s+1 \/ 1 \(100%\)$/m', $output);
    }

    public function test_fixture_is_outside_the_default_suites(): void
    {
        [$exitCode, $output] = $this->runPhpunit(['--list-tests']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Phpunit12LegacyMetadataTest::', $output);
        $this->assertStringNotContainsString('LegacyMetadataExample', $output);
    }

    public function test_journal_publishes_the_real_messages(): void
    {
        [, $output] = $this->fixtureRun();
        $journal = (string) file_get_contents(self::JOURNAL);

        foreach ([...self::EXPECTED_MESSAGES, self::EXPECTED_SUMMARY] as $line) {
            $this->assertStringContainsString($line, $output);
            $this->assertStringContainsString($line, $journal);
        }
        // Conferido contra a saída real em test_docblock_group_is_ignored_by_exclude_group.
        $this->assertStringContainsString(self::GROUP_SUMMARY, $journal);
    }

    /** @return array{0: int, 1: string} */
    private function fixtureRun(): array
    {
        return self::$fixtureRun ??= $this->runPhpunit([
            '--no-configuration',
            '--bootstrap', 'vendor/autoload.php',
            self::FIXTURE,
        ]);
    }

    /**
     * stderr é redirecionado para o stdout: um único pipe, lido até o fim,
     * não trava se o processo encher o buffer de um dos dois.
     *
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function runPhpunit(array $args): array
    {
        $process = proc_open(
            [PHP_BINARY, 'vendor/bin/phpunit', ...$args],
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            self::ROOT
        );
        $this->assertIsResource($process);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }
}
