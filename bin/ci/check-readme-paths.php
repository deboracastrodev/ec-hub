<?php

declare(strict_types=1);

/**
 * Fails the build if README.md links to a file path that doesn't exist
 * (R6.1). Usage: php bin/ci/check-readme-paths.php
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

$missing = [];
foreach ($matches[1] as $link) {
    // Skip URLs, anchors, and mailto links -- only relative file paths matter here.
    if (preg_match('~^([a-z]+://|#|mailto:)~i', $link)) {
        continue;
    }

    $path = $root . '/' . ltrim($link, './');
    if (! is_file($path)) {
        $missing[] = $link;
    }
}

if ($missing !== []) {
    fwrite(STDERR, "README.md links to paths that don't exist: " . implode(', ', $missing) . "\n");
    exit(1);
}

echo "All " . count($matches[1]) . " README.md links checked; file paths exist.\n";
exit(0);
