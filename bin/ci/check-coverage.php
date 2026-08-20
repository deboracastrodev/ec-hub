<?php

declare(strict_types=1);

/**
 * Fails the build if line coverage in a Clover XML report drops below a
 * threshold. Usage: php bin/ci/check-coverage.php coverage.xml 65
 *
 * The threshold starts at the measured baseline (R7.4), not an aspirational
 * number nobody hits -- raise it as coverage genuinely improves.
 */

[, $cloverPath, $minPercent] = $argv + [null, null, null];

if ($cloverPath === null || $minPercent === null) {
    fwrite(STDERR, "Usage: check-coverage.php <clover.xml> <min-percent>\n");
    exit(2);
}

if (!is_file($cloverPath)) {
    fwrite(STDERR, "Coverage file not found: {$cloverPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "Could not parse Clover XML: {$cloverPath}\n");
    exit(2);
}

$metrics = $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "No <metrics> found in Clover report.\n");
    exit(2);
}

$statements = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($statements === 0) {
    fwrite(STDERR, "No statements found in coverage report.\n");
    exit(2);
}

$percent = ($covered / $statements) * 100;
$min = (float) $minPercent;

printf("Line coverage: %.2f%% (%d/%d) -- minimum required: %.2f%%\n", $percent, $covered, $statements, $min);

if ($percent < $min) {
    fwrite(STDERR, "Coverage dropped below the minimum.\n");
    exit(1);
}

exit(0);
