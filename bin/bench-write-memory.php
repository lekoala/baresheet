<?php

/**
 * Writer peak-memory guard.
 *
 * Verifies that XLSX writeFile() peak PHP memory stays bounded (quasi-flat)
 * as the dataset grows — a core Baresheet property. This guards against
 * regressions from ZipStream upgrades or future changes to the write path.
 *
 * Usage:
 *   php bin/bench-write-memory.php
 */

use LeKoala\Baresheet\XlsxWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

const ROW_COUNTS = [10_000, 100_000, 500_000];
const REPS = 3;

/**
 * @return array<int, array<int, int|float|string>>
 */
function buildData(int $rows): array
{
    $data = [];
    for ($i = 1; $i <= $rows; $i++) {
        $data[] = [
            $i, "fname $i", $i * 1.5, "email-$i@domain.com", $i % 100,
            'dept ' . ($i % 50), $i / 3, "user-$i", $i + 7, "notes $i some extra padding",
        ];
    }
    return $data;
}

// Subprocess mode: measure peak memory delta (bytes) for one isolated write.
if (isset($argv[1]) && $argv[1] === '--memory') {
    $rows = isset($argv[2]) ? (int) $argv[2] : 100_000;
    $data = buildData($rows);
    gc_collect_cycles();
    $startMem = memory_get_usage();
    $file = sys_get_temp_dir() . '/bench-write-mem.xlsx';
    (new XlsxWriter())->writeFile($data, $file);
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
echo "| Rows | Avg Time (s) | Peak Memory (MB) | MB per 1k rows |\n";
echo "|---|---|---|---|\n";

foreach (ROW_COUNTS as $rows) {
    $data = buildData($rows);

    $times = [];
    for ($i = 0; $i < REPS; $i++) {
        $file = sys_get_temp_dir() . "/bench-write-mem-$rows-$i.xlsx";
        $start = microtime(true);
        (new XlsxWriter())->writeFile($data, $file);
        $times[] = microtime(true) - $start;
        @unlink($file);
    }
    $avgTime = array_sum($times) / count($times);
    $peak = measureWriteMemory($rows);

    printf(
        "| %d | %.4f | %.2f | %.3f |\n",
        $rows,
        $avgTime,
        $peak,
        $peak / ($rows / 1000),
    );
}

echo "\n> Peak should stay bounded as rows grow (flat MB/1k rows, not linear).\n";
