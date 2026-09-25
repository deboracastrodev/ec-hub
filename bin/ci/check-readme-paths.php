<?php

declare(strict_types=1);

/**
 * Fails the build if README.md links to a file path that doesn't exist
 * (R6.1), or to a #section that no heading of the target Markdown file
 * generates (README.md itself for a bare "#section").
 * Usage: php bin/ci/check-readme-paths.php
 *
 * Route liveness ("toda rota citada responde") isn't checked here -- the
 * full PHPUnit suite already exercises every route in this README via
 * HTTP-level integration tests, which is a stronger check than a curl
 * loop in CI would be.
 */

$root = dirname(__DIR__, 2);
$readme = file_get_contents($root . '/README.md');
if ($readme === false) {
    fwrite(STDERR, "README.md not found\n");
    exit(2);
}

preg_match_all('/\[[^\]]*\]\(([^)]+)\)/', $readme, $matches);

$missingPaths = [];
$missingSections = [];
foreach ($matches[1] as $link) {
    // Skip URLs and mailto links -- only files in this repository (and their sections) matter here.
    if (preg_match('~^([a-z]+://|mailto:)~i', $link)) {
        continue;
    }

    // "docs/X.md#section": the fragment names a heading, it isn't part of the file name.
    [$target, $fragment] = array_pad(explode('#', $link, 2), 2, '');
    $path = $target === ''
        ? $root . '/README.md'
        : $root . '/' . preg_replace('~^(\./|/)+~', '', rawurldecode($target));
    if (! is_file($path)) {
        $missingPaths[] = $link;

        continue;
    }

    // Only Markdown sections are checked; other fragments (e.g. #L10 on a source file) pass.
    if ($fragment !== '' && str_ends_with(strtolower($path), '.md')
        && ! isset(markdownAnchors($path)[mb_strtolower(rawurldecode($fragment))])) {
        $missingSections[] = $link;
    }
}

if ($missingPaths !== []) {
    fwrite(STDERR, "README.md links to paths that don't exist: " . implode(', ', $missingPaths) . "\n");
}
if ($missingSections !== []) {
    fwrite(STDERR, "README.md links to sections that don't exist: " . implode(', ', $missingSections) . "\n");
}
if ($missingPaths !== [] || $missingSections !== []) {
    exit(1);
}

echo "All " . count($matches[1]) . " README.md links checked; file paths and sections exist.\n";
exit(0);

/**
 * The anchors GitHub renders for a Markdown file: one per ATX or setext
 * heading outside fenced code blocks (a "# comment" in a shell block is not
 * a heading), a repeated slug suffixed -1, -2, ... as github-slugger does,
 * plus explicit id/name attributes.
 *
 * @return array<string, true>
 */
function markdownAnchors(string $path): array
{
    static $cache = [];
    if (isset($cache[$path])) {
        return $cache[$path];
    }

    $contents = (string) file_get_contents($path);
    $anchors = [];
    $occurrences = [];
    $fence = null;
    $previous = '';
    foreach (preg_split('/\R/', $contents) ?: [] as $line) {
        if ($fence !== null) {
            if (preg_match('/^ {0,3}(`{3,}|~{3,})[ \t]*$/', $line, $close) === 1
                && $close[1][0] === $fence[0] && strlen($close[1]) >= strlen($fence)) {
                $fence = null;
            }

            continue;
        }
        if (preg_match('/^ {0,3}(`{3,}|~{3,})/', $line, $open) === 1) {
            $fence = $open[1];
            $previous = '';

            continue;
        }

        $heading = null;
        if (preg_match('/^ {0,3}#{1,6}(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/u', $line, $atx) === 1) {
            $heading = $atx[1] ?? '';
        } elseif (trim($previous) !== '' && preg_match('/^ {0,3}(?:=+|-+)[ \t]*$/', $line) === 1) {
            // Setext: the line above, underlined with === or ---.
            $heading = trim($previous);
        }
        $previous = $heading === null ? $line : '';
        if ($heading === null) {
            continue;
        }

        $slug = githubSlug($heading);
        $anchor = $slug;
        while (isset($anchors[$anchor])) {
            $occurrences[$slug] = ($occurrences[$slug] ?? 0) + 1;
            $anchor = $slug . '-' . $occurrences[$slug];
        }
        $anchors[$anchor] = true;
    }

    preg_match_all('/<[a-z][^>]*\s(?:id|name)\s*=\s*["\']([^"\']+)["\']/i', $contents, $explicit);
    foreach ($explicit[1] as $id) {
        $anchors[mb_strtolower($id)] = true;
    }

    return $cache[$path] = $anchors;
}

/**
 * GitHub's heading slug: the heading text (a link counts by its text),
 * lowercased, keeping only letters, marks, numbers, "_", "-" and spaces,
 * then each space turned into "-". "### Métricas (`GET /api/metrics`)"
 * becomes "métricas-get-apimetrics".
 */
function githubSlug(string $heading): string
{
    $text = (string) preg_replace('/!?\[([^\]]*)\]\([^)]*\)/u', '$1', $heading);
    $text = (string) preg_replace('/[^\p{L}\p{M}\p{N}_ -]/u', '', mb_strtolower($text));

    return str_replace(' ', '-', $text);
}
