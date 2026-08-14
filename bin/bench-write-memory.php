<?php

/**
 * Writer peak-memory guard.
 *
 * Verifies that XLSX writeFile() peak PHP memory stays bounded (quasi-flat)
 * as the dataset grows — a core Baresheet property. This guards against
 * regressions in the direct ZIP write path.
 *
 * Usage:
 *   php bin/bench-write-memory.php
 */

use LeKoala\Baresheet\XlsxWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

const ROW_COUNTS = [10_000, 100_000, 500_000];
const REPS = 5;

/**
 * @return Generator<int, array<int, int|float|string>>
 */
function generateData(int $rows): Generator
{
    for ($i = 1; $i <= $rows; $i++) {
        yield [
            $i, "fname $i", $i * 1.5, "email-$i@domain.com", $i % 100,
            'dept ' . ($i % 50), $i / 3, "user-$i", $i + 7, "notes $i some extra padding",
        ];
    }
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values);
    $middle = intdiv(count($values), 2);
    return count($values) % 2 === 0
        ? ($values[$middle - 1] + $values[$middle]) / 2
        : $values[$middle];
}

// Subprocess mode: measure peak memory delta (bytes) for one isolated write.
if (isset($argv[1]) && $argv[1] === '--memory') {
    $rows = isset($argv[2]) ? (int) $argv[2] : 100_000;
    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $startMem = memory_get_usage();
    $file = sys_get_temp_dir() . '/bench-write-mem-' . getmypid() . '.xlsx';
    (new XlsxWriter())->writeFile(generateData($rows), $file);
    @unlink($file);
    printf("%.0f", memory_get_peak_usage() - $startMem);
    exit;
}

/** @return float Peak memory in MB for a single isolated write of $rows. */
function measureWriteMemory(int $rows): float
{
    $cmd = PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --memory ' . escapeshellarg((string) $rows);
    $bytes = (int) trim((string) shell_exec($cmd));
    return $bytes / 1024 / 1024;
}

echo "# XLSX writeFile peak-memory guard\n\n";
echo "| Rows | Median Time (s) | Peak PHP Memory (MB) |\n";
echo "|---|---|---|\n";

foreach (ROW_COUNTS as $rows) {
    $times = [];
    for ($i = 0; $i < REPS; $i++) {
        $file = sys_get_temp_dir() . "/bench-write-mem-$rows-$i.xlsx";
        $start = microtime(true);
        (new XlsxWriter())->writeFile(generateData($rows), $file);
        $times[] = microtime(true) - $start;
        @unlink($file);
    }
    $medianTime = median($times);
    $peak = measureWriteMemory($rows);

    printf(
        "| %d | %.4f | %.2f |\n",
        $rows,
        $medianTime,
        $peak,
    );
}

echo "\n> With generator input, total incremental PHP peak should remain approximately flat as rows grow.\n";
