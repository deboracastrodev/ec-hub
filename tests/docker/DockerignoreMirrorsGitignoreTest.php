<?php

declare(strict_types=1);

namespace Tests\docker;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * DW-38: o .dockerignore precisa espelhar o .gitignore (DW-7).
 *
 * Cada padrão do .gitignore tem de ser coberto por uma entrada do
 * .dockerignore com escopo igual ou mais amplo, sem negação posterior que o
 * reabra. Diferenças intencionais ficam na ALLOWLIST (padrão => motivo).
 *
 * Semântica de escopo usada:
 * - .gitignore: padrão sem `/` (fora a barra final) vale em qualquer nível;
 *   com `/` inicial ou no meio é ancorado na raiz.
 * - .dockerignore: toda entrada é ancorada na raiz; o prefixo `**` + barra vale em
 *   qualquer nível. Entrada que casa um diretório ancestral cobre o padrão.
 *
 * É uma aproximação conservadora, não uma reimplementação das duas sintaxes:
 * globs são comparados por segmento com fnmatch() (o caminho já vem
 * separado em `/`, o que equivale a FNM_PATHNAME); `**` no meio do caminho,
 * escapes (`\#`, `\!`) e negações do .gitignore não são modelados. Nesses
 * casos o teste tende a falhar (falso positivo), nunca a aprovar um vazamento:
 * registre a diferença na ALLOWLIST com o motivo.
 */
class DockerignoreMirrorsGitignoreTest extends TestCase
{
    private const GITIGNORE = __DIR__ . '/../../.gitignore';
    private const DOCKERIGNORE = __DIR__ . '/../../.dockerignore';

    /**
     * Padrões do .gitignore que, de propósito, não têm equivalente no
     * .dockerignore (padrão exatamente como está no .gitignore => motivo).
     *
     * @var array<string, string>
     */
    private const ALLOWLIST = [];

    public function test_dockerignore_covers_every_gitignore_pattern(): void
    {
        $uncovered = self::uncoveredPatterns(
            self::read(self::GITIGNORE),
            self::read(self::DOCKERIGNORE),
            self::ALLOWLIST,
        );

        $this->assertSame(
            [],
            $uncovered,
            "Padrões do .gitignore sem equivalente (mesmo escopo ou mais amplo) no .dockerignore:\n  - "
            . implode("\n  - ", $uncovered)
            . "\nAcrescente a entrada no .dockerignore (use `**/` para padrões que valem em qualquer nível)"
            . ' ou registre a diferença intencional na ALLOWLIST deste teste.',
        );
    }

    public function test_allowlist_only_cites_patterns_present_in_gitignore(): void
    {
        $stale = self::staleAllowlistEntries(self::read(self::GITIGNORE), self::ALLOWLIST);

        $this->assertSame(
            [],
            $stale,
            "A ALLOWLIST cita padrões que não existem mais no .gitignore:\n  - "
            . implode("\n  - ", $stale)
            . "\nRemova essas entradas da ALLOWLIST.",
        );
    }

    public function test_env_example_stays_in_build_context_and_env_does_not(): void
    {
        $docker = self::parse(self::read(self::DOCKERIGNORE));

        $this->assertTrue(
            self::isCovered(self::normalizeGitPattern('.env'), $docker),
            'O .env (qualquer nível) deve ficar fora do contexto de build',
        );
        $this->assertFalse(
            self::isCovered(self::normalizeGitPattern('/.env.example'), $docker),
            'O .env.example deve continuar no contexto de build',
        );
    }

    public function test_new_gitignore_pattern_without_mirror_is_reported(): void
    {
        $copy = tempnam(sys_get_temp_dir(), 'gitignore-');
        $this->assertIsString($copy);

        try {
            file_put_contents($copy, self::read(self::GITIGNORE) . "\n.dw38-sentinel/\n");

            $uncovered = self::uncoveredPatterns(
                self::read($copy),
                self::read(self::DOCKERIGNORE),
                self::ALLOWLIST,
            );
        } finally {
            unlink($copy);
        }

        $this->assertSame(['.dw38-sentinel/'], $uncovered);
    }

    /**
     * @param list<string> $expectedUncovered
     */
    #[DataProvider('scenarios')]
    public function test_coverage_scenarios(string $gitignore, string $dockerignore, array $expectedUncovered): void
    {
        $this->assertSame($expectedUncovered, self::uncoveredPatterns($gitignore, $dockerignore, []));
    }

    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function scenarios(): iterable
    {
        yield 'padrão novo sem espelho' => [".claude/\n.cursor/\n", "**/.claude/\n", ['.cursor/']];
        yield 'escopo mais estreito (raiz x qualquer nível)' => ["coverage.xml\n", "coverage.xml\n", ['coverage.xml']];
        yield 'mesmo escopo em qualquer nível' => ["coverage.xml\n", "**/coverage.xml\n", []];
        yield 'ancorado coberto por entrada ancorada' => ["/vendor/\n", "vendor/\n", []];
        yield 'ancorado coberto por **/' => ["/runtime/\n", "**/runtime/\n", []];
        yield 'ancorado não coberto por outro diretório' => ["/runtime/\n", "var/\n", ['/runtime/']];
        yield 'ancestral cobre' => [".bmad-loop/runs/\n", "**/.bmad-loop/\n", []];
        yield 'ancestral ancorado cobre conteúdo com /*' => ["/app/storage/*\n", "app/storage/\n", []];
        yield 'glob cobre arquivo ancorado' => ["/npm-debug.log\n", "**/*.log\n", []];
        yield 'glob cobre glob' => ["*.log\n.env.*.local\n", "**/*.log\n**/.env*\n", []];
        yield 'glob só na raiz não cobre qualquer nível' => ["*.log\n", "*.log\n", ['*.log']];
        yield 'negação posterior reabre' => [".claude/\n", "**/.claude/\n!**/.claude/settings.json\n", ['.claude/']];
        yield 'negação anterior não reabre' => [".claude/\n", "!**/.claude/settings.json\n**/.claude/\n", []];
        yield 'negação de outro arquivo não reabre' => [".env\n", "**/.env*\n!.env.example\n", []];
        yield 'negação **/ mais funda que o padrão reabre' => [".claude/\n", "**/.claude/\n!**/settings.json\n", ['.claude/']];
        yield 'negação **/ reabre dentro de diretório ancorado' => ["/vendor/\n", "vendor/\n!**/.env.example\n", ['/vendor/']];
        yield 'negação ancorada dentro do padrão reabre' => [".claude/\n", "**/.claude/\n!.claude/settings.json\n", ['.claude/']];
        yield '**/ cobre caminho ancorado aninhado' => ["/app/node_modules/\n", "**/node_modules/\n", []];
        yield 'glob mais estreito não cobre glob' => ["*.log\n", "**/?.log\n", ['*.log']];
        yield '**/ no .gitignore vale em qualquer nível' => ["**/.cursor/\n", "**/.cursor/\n", []];
        yield '**/ no .gitignore não é coberto por entrada da raiz' => ["**/.cursor/\n", ".cursor/\n", ['**/.cursor/']];
        yield '**/ seguido de glob no .gitignore mantém o glob' => ["**/*.log\n", "**/.log\n", ['**/*.log']];
        yield '**/ seguido de glob coberto por glob' => ["**/*.log\n", "**/*.log\n", []];
        yield 'barra no meio ancora o padrão' => ["foo/bar\n", "foo/bar\n", []];
        yield 'comentários e linhas vazias ignorados' => ["# comentário\n\n.idea/\n", "# x\n\n**/.idea/\n", []];
    }

    public function test_allowlisted_pattern_is_not_reported(): void
    {
        $uncovered = self::uncoveredPatterns(".cursor/\n.idea/\n", "", ['.cursor/' => 'motivo']);

        $this->assertSame(['.idea/'], $uncovered);
    }

    public function test_stale_allowlist_entry_is_reported(): void
    {
        $stale = self::staleAllowlistEntries(".idea/\n", [
            '.idea/' => 'motivo',
            '.cursor/' => 'padrão removido do .gitignore',
        ]);

        $this->assertSame(['.cursor/'], $stale);
    }

    /**
     * @param array<string, string> $allowlist
     *
     * @return list<string> padrões do .gitignore (texto original) não cobertos
     */
    private static function uncoveredPatterns(string $gitignore, string $dockerignore, array $allowlist): array
    {
        $docker = self::parse($dockerignore);
        $uncovered = [];

        foreach (self::parse($gitignore) as $entry) {
            if ($entry['negated'] || isset($allowlist[$entry['raw']])) {
                continue;
            }

            if (! self::isCovered(self::normalizeGitPattern($entry['raw']), $docker)) {
                $uncovered[] = $entry['raw'];
            }
        }

        return array_values(array_unique($uncovered));
    }

    /**
     * @param array<string, string> $allowlist
     *
     * @return list<string>
     */
    private static function staleAllowlistEntries(string $gitignore, array $allowlist): array
    {
        $patterns = array_column(self::parse($gitignore), 'raw');

        return array_values(array_filter(
            array_keys($allowlist),
            static fn (string $pattern): bool => ! in_array($pattern, $patterns, true),
        ));
    }

    /**
     * Linhas relevantes de um arquivo de ignore, na ordem, com a negação separada.
     *
     * @return list<array{raw: string, negated: bool}>
     */
    private static function parse(string $content): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $negated = str_starts_with($line, '!');
            $entries[] = ['raw' => $negated ? ltrim(substr($line, 1)) : $line, 'negated' => $negated];
        }

        return $entries;
    }

    /**
     * @return array{segments: list<string>, anyLevel: bool}
     */
    private static function normalizeGitPattern(string $pattern): array
    {
        $path = rtrim($pattern, '/');

        if (str_starts_with($path, '**/')) {
            return ['segments' => self::segments((string) preg_replace('#^(\*\*/)+#', '', $path)), 'anyLevel' => true];
        }

        return ['segments' => self::segments(ltrim($path, '/')), 'anyLevel' => ! str_contains($path, '/')];
    }

    /**
     * @return array{segments: list<string>, anyLevel: bool}
     */
    private static function normalizeDockerPattern(string $pattern): array
    {
        $path = trim($pattern, '/');
        $anyLevel = false;

        while (str_starts_with($path, '**/')) {
            $anyLevel = true;
            $path = substr($path, 3);
        }

        return ['segments' => self::segments($path), 'anyLevel' => $anyLevel];
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param array{segments: list<string>, anyLevel: bool} $git
     * @param list<array{raw: string, negated: bool}> $docker
     */
    private static function isCovered(array $git, array $docker): bool
    {
        foreach ($docker as $index => $entry) {
            if ($entry['negated']) {
                continue;
            }

            $candidate = self::normalizeDockerPattern($entry['raw']);
            if (! self::entryCovers($candidate, $git)) {
                continue;
            }

            if (! self::reopenedAfter($git, $docker, $index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A entrada do .dockerignore casa o padrão do .gitignore ou um diretório
     * ancestral dele, com escopo igual ou mais amplo.
     *
     * @param array{segments: list<string>, anyLevel: bool} $entry
     * @param array{segments: list<string>, anyLevel: bool} $git
     */
    private static function entryCovers(array $entry, array $git): bool
    {
        if ($git['anyLevel'] && ! $entry['anyLevel']) {
            return false;
        }

        $e = $entry['segments'];
        $g = $git['segments'];
        // Entrada `**/` pode começar em qualquer segmento do padrão; ancorada, só na raiz.
        $lastOffset = $entry['anyLevel'] ? count($g) - count($e) : 0;

        for ($offset = 0; $offset <= $lastOffset; $offset++) {
            $match = $e !== [] && $offset + count($e) <= count($g);
            for ($i = 0; $match && $i < count($e); $i++) {
                $match = self::segmentCovers($e[$i], $g[$offset + $i]);
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    /**
     * O segmento da entrada casa tudo o que o segmento do .gitignore casa. Se o
     * segmento do .gitignore é glob, só `*` na entrada é seguro: `*` absorve os
     * curingas dele, enquanto `?` ou `[...]` casariam o curinga como caractere.
     */
    private static function segmentCovers(string $entry, string $git): bool
    {
        if (strpbrk($git, '*?[') !== false && strpbrk($entry, '?[') !== false) {
            return $entry === $git;
        }

        return fnmatch($entry, $git);
    }

    /**
     * Alguma negação depois de $index reabre algo dentro do escopo do padrão
     * (um arquivo sob ele, ele mesmo ou um diretório ancestral).
     *
     * @param array{segments: list<string>, anyLevel: bool} $git
     * @param list<array{raw: string, negated: bool}> $docker
     */
    private static function reopenedAfter(array $git, array $docker, int $index): bool
    {
        foreach (array_slice($docker, $index + 1) as $entry) {
            if ($entry['negated'] && self::overlaps($git, self::normalizeDockerPattern($entry['raw']))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{segments: list<string>, anyLevel: bool} $git
     * @param array{segments: list<string>, anyLevel: bool} $negation
     */
    private static function overlaps(array $git, array $negation): bool
    {
        // Negação `**/` pode reabrir um descendente em qualquer profundidade,
        // abaixo de qualquer diretório excluído pelo padrão.
        if ($negation['anyLevel']) {
            return true;
        }

        $g = $git['segments'];
        $n = $negation['segments'];

        // Alinhamentos possíveis: o que vale em qualquer nível pode começar em
        // qualquer segmento do outro caminho.
        $alignments = [[$g, $n, 0]];
        if ($git['anyLevel']) {
            for ($o = 1; $o < count($n); $o++) {
                $alignments[] = [$g, $n, $o];
            }
        }

        foreach ($alignments as [$inner, $outer, $offset]) {
            $length = min(count($inner), count($outer) - $offset);
            $match = $length > 0;
            for ($i = 0; $i < $length && $match; $i++) {
                $match = self::segmentsOverlap($inner[$i], $outer[$offset + $i]);
            }
            if ($match) {
                return true;
            }
        }

        return false;
    }

    private static function segmentsOverlap(string $a, string $b): bool
    {
        return fnmatch($a, $b) || fnmatch($b, $a);
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, "Não foi possível ler {$path}");

        return $content;
    }
}
