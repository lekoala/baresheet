<?php

/**
 * Compare seekable writeFile() and non-seekable output() ODS paths.
 *
 * Usage:
 *   php bin/bench-ods-stream.php [rows]
 */

use LeKoala\Baresheet\OdsWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

const ODS_STREAM_REPS = 5;

/** @return Generator<int, array<int, int|float|string>> */
function odsStreamRows(int $rows): Generator
{
    for ($i = 1; $i <= $rows; $i++) {
        yield [
            $i,
            "fname $i",
            $i * 1.5,
            "email-$i@domain.com",
            $i % 100,
            'dept ' . ($i % 50),
            $i / 3,
            "user-$i",
            $i + 7,
            "notes $i some extra padding",
        ];
    }
}

/** @param list<float> $values */
function odsStreamMedian(array $values): float
{
    sort($values);
    return $values[intdiv(count($values), 2)];
}

if (($argv[1] ?? '') === '--worker') {
    $mode = $argv[2] ?? '';
    $rows = isset($argv[3]) ? (int) $argv[3] : 100_000;
    $output = $argv[4] ?? '';

    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $baseline = memory_get_usage();
    $start = hrtime(true);

    $writer = new OdsWriter();
    if ($mode === 'seekable') {
        $writer->writeFile(odsStreamRows($rows), $output);
    } elseif ($mode === 'non-seekable') {
        $writer->output(odsStreamRows($rows), 'streamed.ods');
    } else {
        throw new RuntimeException("Unknown mode: {$mode}");
    }

    fwrite(STDERR, json_encode([
        'seconds' => (hrtime(true) - $start) / 1e9,
        'peakMemory' => memory_get_peak_usage() - $baseline,
    ], JSON_THROW_ON_ERROR)
        . PHP_EOL);
    exit();
}

$rows = isset($argv[1]) ? (int) $argv[1] : 100_000;
$results = ['seekable' => [], 'non-seekable' => []];
$sizes = [];
$nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

for ($rep = 0; $rep < ODS_STREAM_REPS; $rep++) {
    $order = array_keys($results);
    shuffle($order);

    foreach ($order as $mode) {
        $base = tempnam(sys_get_temp_dir(), 'bench_ods_stream_');
        if ($base === false) {
            throw new RuntimeException('Unable to create benchmark output file');
        }
        $output = $base . '.ods';
        unlink($base);

        $command = [PHP_BINARY, __FILE__, '--worker', $mode, (string) $rows, $output];
        $descriptors = [
            0 => ['file', $nullDevice, 'rb'],
            1 => ['file', $mode === 'non-seekable' ? $output : $nullDevice, 'wb'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start benchmark worker');
        }

        $statsJson = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || $statsJson === false) {
            throw new RuntimeException("Benchmark worker failed for {$mode}: {$statsJson}");
        }

        /** @var array{seconds:float, peakMemory:int} $stats */
        $stats = json_decode($statsJson, true, flags: JSON_THROW_ON_ERROR);
        $results[$mode][] = $stats;
        $sizes[$mode] = filesize($output);

        $zip = new ZipArchive();
        $open = $zip->open($output, ZipArchive::CHECKCONS);
        if ($open !== true || $zip->getNameIndex(0) !== 'mimetype') {
            throw new RuntimeException("Invalid {$mode} ODS archive, ZipArchive code: {$open}");
        }
        $zip->close();
        unlink($output);
    }
}

echo "# ODS seekable vs non-seekable ({$rows} rows × 10 columns)\n\n";
echo "| Mode | Median Time (s) | Peak PHP Memory (MB) | Size (MB) |\n";
echo "|---|---|---|---|\n";
foreach ($results as $mode => $values) {
    printf(
        "| %s | %.4f | %.2f | %.2f |\n",
        $mode,
        odsStreamMedian(array_column($values, 'seconds')),
        (odsStreamMedian(array_column($values, 'peakMemory')) / 1024) / 1024,
        ((int) $sizes[$mode] / 1024) / 1024,
    );
}
