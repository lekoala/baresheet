<?php

/**
 * Stress benchmark for shared-strings reading.
 *
 * Measures peak memory and time for XlsxReader across shared-strings cardinality
 * (numbers only, few repeated strings, many unique strings) and row counts, to
 * observe how memory scales with the sharedStrings.xml table.
 *
 * Usage:
 *   php bin/bench-shared.php
 *
 * Fixtures are generated under .temp/ (git-ignored).
 */

use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

$tempDir = dirname(__DIR__) . '/.temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// Subprocess mode: read a single file in isolation and print peak memory delta (bytes).
if (isset($argv[1]) && $argv[1] === '--memory') {
    $file = $argv[2];
    gc_collect_cycles();
    $startMem = memory_get_usage();

    $reader = new XlsxReader();
    foreach ($reader->readFile($file) as $row) {
    }

    printf("%.0f", memory_get_peak_usage() - $startMem);
    exit;
}

/** @return iterable<int, array<int|string>> */
function generateScenario(string $scenario, int $rowCount): iterable
{
    $categories = ['alpha', 'beta', 'gamma', 'delta', 'epsilon', 'zeta', 'eta', 'theta', 'iota', 'kappa'];

    for ($i = 1; $i <= $rowCount; $i++) {
        switch ($scenario) {
            case 'numbers':
                yield [$i, $i * 2];
                break;
            case 'few-repeated':
                yield [$i, $categories[$i % 10]];
                break;
            case 'many-unique':
            default:
                yield [$i, "unique string {$i} with some padding"];
                break;
        }
    }
}

function measureReadMemory(string $file): float
{
    $cmd = PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --memory ' . escapeshellarg($file);
    $bytes = (int) trim((string) shell_exec($cmd));
    return $bytes / 1024 / 1024;
}

$scenarios = [
    'numbers' => 'Numbers only',
    'few-repeated' => 'Few repeated shared strings',
    'many-unique' => 'Many unique shared strings',
];

$rowCounts = [100_000, 500_000];
$reps = 3;

foreach ($rowCounts as $rowCount) {
    echo "# Shared-strings benchmark: {$rowCount} rows\n\n";
    echo "| Scenario | sharedStrings.xml | Avg Time (s) | Peak Memory (MB) |\n";
    echo "|---|---|---|---|\n";

    foreach ($scenarios as $key => $label) {
        $file = "{$tempDir}/bench-shared-{$key}-{$rowCount}.xlsx";

        if (!is_file($file)) {
            $writer = new XlsxWriter();
            $writer->sharedStrings = true;
            $writer->writeFile(generateScenario($key, $rowCount), $file);
        }

        // Report the shared-strings table size.
        $zip = new ZipArchive();
        $zip->open($file);
        $ssIdx = $zip->locateName('xl/sharedStrings.xml');
        $ssSize = $ssIdx !== false ? (int) $zip->statIndex($ssIdx)['size'] : 0;
        $zip->close();

        $times = [];
        for ($i = 0; $i < $reps; $i++) {
            $start = microtime(true);
            $reader = new XlsxReader();
            foreach ($reader->readFile($file) as $row) {
            }
            $times[] = microtime(true) - $start;
        }

        $memory = measureReadMemory($file);
        $avgTime = array_sum($times) / count($times);

        printf(
            "| %s | %.2f MB | %.4f | %.2f |\n",
            $label,
            $ssSize / 1024 / 1024,
            $avgTime,
            $memory,
        );
    }

    echo "\n";
}
