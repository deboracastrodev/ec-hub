<?php

declare(strict_types=1);

/**
 * Fails the build if .env.example and the code's getenv() calls drift apart
 * (R6.4). Usage: php bin/ci/check-env-vars.php
 *
 * ALLOWED_UNREAD holds vars that are a deliberate convention rather than
 * something the app reads directly -- keep this list short and justified.
 */

const ALLOWED_UNREAD = [
    // Standard environment-name convention; not read by app logic today,
    // but required by tests/docker/validate-env.sh and integration-test.sh.
    'APP_ENV',
];

$root = dirname(__DIR__, 2);

$envExample = file_get_contents($root . '/.env.example');
if ($envExample === false) {
    fwrite(STDERR, ".env.example not found\n");
    exit(2);
}

preg_match_all('/^([A-Z_][A-Z0-9_]*)=/m', $envExample, $matches);
$declaredVars = array_unique($matches[1]);

$codeVars = [];
$paths = [$root . '/app', $root . '/config', $root . '/public', $root . '/bin'];
foreach ($paths as $path) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());
        if ($contents === false) {
            continue;
        }

        preg_match_all('/getenv\(\s*[\'"]([A-Z_][A-Z0-9_]*)[\'"]/', $contents, $fileMatches);
        foreach ($fileMatches[1] as $var) {
            $codeVars[$var] = true;
        }
    }
}
$codeVars = array_keys($codeVars);

$declaredMissing = array_values(array_diff($codeVars, $declaredVars));
$unreadInExample = array_values(array_diff($declaredVars, $codeVars, ALLOWED_UNREAD));

$errors = 0;

if ($declaredMissing !== []) {
    fwrite(STDERR, "Vars read by getenv() but missing from .env.example: " . implode(', ', $declaredMissing) . "\n");
    $errors++;
}

if ($unreadInExample !== []) {
    fwrite(STDERR, ".env.example vars never read by getenv() (add to ALLOWED_UNREAD if intentional): " . implode(', ', $unreadInExample) . "\n");
    $errors++;
}

if ($errors > 0) {
    exit(1);
}

echo ".env.example and getenv() calls are in sync (" . count($declaredVars) . " vars).\n";
exit(0);
