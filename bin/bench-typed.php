<?php

/**
 * Benchmark: native typed values vs stringified (legacy) values.
 *
 * Compares Baresheet reads across temporal workloads:
 *   scalar      - no temporal cells
 *   dates-light - 1 temporal / 10 columns
 *   dates-heavy - 5 temporal / 10 columns
 *   dates-only  - 10 temporal / 10 columns
 *
 * For each workload and format it measures:
 *   stringify = true  -> legacy CSV-like string output (the pre-evolution contract)
 *   stringify = false -> native typed output (int|float|bool|DateTimeImmutable,
 *                        time/duration as canonical strings)
 *
 * A compact write section compares inferNumericStrings=true vs false on a
 * string-heavy dataset (the regex guess is skipped when false).
 *
 * Usage:
 *   php bin/bench-typed.php [rows]   (default: 100000)
 *
 * Fixtures are generated and cached under .temp/ (git-ignored).
 */

use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Value\DurationValue;
use LeKoala\Baresheet\Value\TimeValue;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

const DEFAULT_ROWS = 100000;
const REPS = 5;
const BASE_TS = 1700000000;

$tempDir = dirname(__DIR__) . '/.temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

$rows = isset($argv[1]) ? (int) $argv[1] : DEFAULT_ROWS;

/**
 * Build a 10-column row for a given read scenario.
 *
 * @return array<int, mixed>
 */
function makeRow(string $scenario, int $i): array
{
    switch ($scenario) {
        case 'scalar':
            return [
                $i, $i * 1.5, $i % 2 === 0, "name $i", $i + 100,
                "email-$i@example.com", $i % 7, 'dept ' . ($i % 5), $i * 3, "note $i",
            ];
        case 'dates-light':
            return [
                $i, "name $i", $i % 2 === 0, $i * 0.5, "email-$i@example.com",
                $i + 1, 'dept ' . ($i % 3), $i % 11, "note $i", dateValue($i),
            ];
        case 'dates-heavy':
            return [
                $i, "name $i", dateValue($i), $i * 1.5, dateValue($i + 1),
                "email-$i@example.com", dateValue($i + 2), $i % 5, dateValue($i + 3), dateValue($i + 4),
            ];
        case 'times':
            return [
                timeValue($i), durationValue($i), $i, timeValue($i + 1), durationValue($i + 1),
                "note $i", $i * 1.5, timeValue($i + 2), $i % 2 === 0, durationValue($i + 2),
            ];
        case 'dates-only':
        default:
            return [
                dateValue($i), dateValue($i + 1), dateValue($i + 2), dateValue($i + 3), dateValue($i + 4),
                dateValue($i + 5), dateValue($i + 6), dateValue($i + 7), dateValue($i + 8), dateValue($i + 9),
            ];
    }
}

function timeValue(int $i): TimeValue
{
    return new TimeValue(($i * 7) % 24, ($i * 13) % 60, $i % 60);
}

function durationValue(int $i): DurationValue
{
    return DurationValue::fromTime(
        hours: 36 + ($i % 40),
        minutes: ($i * 5) % 60,
        seconds: $i % 60,
    );
}

/**
 * A string-heavy row for the write benchmark: most cells are text, so the
 * inferNumericStrings regex guess is exercised (or skipped) at scale.
 *
 * @return array<int, int|float|bool|string>
 */
function makeWriteRow(int $i): array
{
    return [
        "alpha $i", $i, "bravo $i padding text", $i * 1.5, "charlie $i",
        $i % 2 === 0, "delta $i value here", "echo $i", $i + 5, "foxtrot $i ending",
    ];
}

function dateValue(int $i): DateTimeImmutable
{
    return new DateTimeImmutable('@' . (BASE_TS + $i * 60));
}

/**
 * @return \Generator<int, array<int, mixed>>
 */
function rowGenerator(string $scenario, int $rows): Generator
{
    for ($i = 1; $i <= $rows; $i++) {
        yield makeRow($scenario, $i);
    }
}

/**
 * @return \Generator<int, array<int, int|float|bool|string>>
 */
function writeRowGenerator(int $rows): Generator
{
    for ($i = 1; $i <= $rows; $i++) {
        yield makeWriteRow($i);
    }
}

// Subprocess mode: measure peak memory delta (bytes) for a single library in isolation.
if (isset($argv[1]) && $argv[1] === '--memory') {
    $key = $argv[2];
    $file = $argv[3];
    $rows = isset($argv[4]) ? (int) $argv[4] : DEFAULT_ROWS;

    $isWrite = str_starts_with($key, 'write-');

    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $startMem = memory_get_usage();

    if ($isWrite) {
        // Stream via the generator so the peak is the writer's, not a
        // pre-built data array (memory_reset_peak_usage is PHP 8.2+ only).
        runWrite($key, writeRowGenerator($rows), $file);
    } else {
        runRead($key, $file);
    }

    if ($isWrite && is_file($file)) {
        unlink($file);
    }

    printf("%.0f", memory_get_peak_usage() - $startMem);
    exit;
}

// Subprocess mode: measure wall time (seconds) for a single isolated read/write.
if (isset($argv[1]) && $argv[1] === '--time') {
    $key = $argv[2];
    $file = $argv[3];
    $rows = isset($argv[4]) ? (int) $argv[4] : DEFAULT_ROWS;

    $isWrite = str_starts_with($key, 'write-');
    if ($isWrite) {
        $data = [];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = makeWriteRow($i);
        }
        $start = microtime(true);
        runWrite($key, $data, $file);
        if (is_file($file)) {
            unlink($file);
        }
        printf("%.6f", microtime(true) - $start);
        exit;
    }

    $start = microtime(true);
    runRead($key, $file);
    printf("%.6f", microtime(true) - $start);
    exit;
}

/** Run a read key ('read-xlsx-stringify', 'read-ods-native', ...). */
function runRead(string $key, string $file): void
{
    $reader = str_contains($key, '-ods-') ? new OdsReader() : new XlsxReader();
    $reader->stringifyValues = str_ends_with($key, '-stringify');
    foreach ($reader->readFile($file) as $row) {
    }
}

/** Run a write key ('write-xlsx-stringify', 'write-ods-native', ...). */
function runWrite(string $key, iterable $data, string $file): void
{
    $writer = str_contains($key, '-ods-') ? new OdsWriter() : new XlsxWriter();
    $writer->inferNumericStrings = str_ends_with($key, '-stringify');
    $writer->writeFile($data, $file);
}

/** Measure peak memory (MB) in an isolated subprocess. */
function measureMemory(string $key, string $file, int $rows): float
{
    $cmd = PHP_BINARY
        . ' ' . escapeshellarg(__FILE__)
        . ' --memory ' . escapeshellarg($key) . ' ' . escapeshellarg($file)
        . ' ' . escapeshellarg((string) $rows);
    $bytes = (int) trim((string) shell_exec($cmd));
    return $bytes / 1024 / 1024;
}

/** Measure wall time (s) in an isolated subprocess. */
function measureTime(string $key, string $file, int $rows): float
{
    $cmd = PHP_BINARY
        . ' ' . escapeshellarg(__FILE__)
        . ' --time ' . escapeshellarg($key) . ' ' . escapeshellarg($file)
        . ' ' . escapeshellarg((string) $rows);
    return (float) trim((string) shell_exec($cmd));
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

/** Ensure a fixture exists, generating it with Baresheet if missing. */
function ensureFixture(string $file, string $scenario, string $format, int $rows): void
{
    if (is_file($file)) {
        return;
    }
    echo "Generating fixture: {$file}" . PHP_EOL;
    $writer = $format === 'ods' ? new OdsWriter() : new XlsxWriter();
    $writer->inferNumericStrings = false;
    $writer->writeFile(rowGenerator($scenario, $rows), $file);
}

function readBenchmark(string $format, string $scenario, string $label, string $tempDir, int $rows): void
{
    $file = "{$tempDir}/bench-typed-{$scenario}-{$rows}.{$format}";
    ensureFixture($file, $scenario, $format, $rows);

    $results = [];
    $times = ['stringify' => [], 'native' => []];
    for ($i = 0; $i < REPS; $i++) {
        // Alternate the modes across runs to be robust to OS/CPU/AV drift.
        $order = ['stringify', 'native'];
        shuffle($order);
        foreach ($order as $mode) {
            $times[$mode][] = measureTime("read-{$format}-{$mode}", $file, $rows);
        }
    }
    foreach (['stringify', 'native'] as $mode) {
        $results[$mode] = [
            'time' => median($times[$mode]),
            'memory' => measureMemory("read-{$format}-{$mode}", $file, $rows),
        ];
    }

    $stringifyTime = $results['stringify']['time'];
    $nativeTime = $results['native']['time'];
    $delta = $stringifyTime > 0 ? (($nativeTime - $stringifyTime) / $stringifyTime) * 100 : 0.0;

    echo "## {$label}" . PHP_EOL . PHP_EOL;
    echo "| Mode | Median Time (s) | Peak PHP Memory (MB) | Δ time vs stringify |" . PHP_EOL;
    echo "|---|---|---|---|" . PHP_EOL;
    printf(
        "| stringify (legacy strings) | %.4f | %.2f | — |" . PHP_EOL,
        $stringifyTime,
        $results['stringify']['memory'],
    );
    printf(
        "| native (typed)             | %.4f | %.2f | %+.1f%% |" . PHP_EOL,
        $nativeTime,
        $results['native']['memory'],
        $delta,
    );
    echo PHP_EOL;
}

$scenarios = [
    'scalar' => 'Scalar (int/float/bool/string)',
    'dates-light' => 'Dates: 1 temporal / 10 columns',
    'dates-heavy' => 'Dates: 5 temporal / 10 columns',
    'dates-only' => 'Dates: 10 temporal / 10 columns',
    'times' => 'Times/Durations: 6 temporal / 10 columns',
];

foreach (['xlsx', 'ods'] as $format) {
    echo "# Read Benchmark: " . strtoupper($format) . " ({$rows} rows × 10 cols, cached in {$tempDir})" . PHP_EOL . PHP_EOL;
    foreach ($scenarios as $scenario => $label) {
        readBenchmark($format, $scenario, $label, $tempDir, $rows);
    }
    echo PHP_EOL;
}

// --- Write benchmark: string-heavy dataset, inferNumericStrings on vs off ---

echo "# Write Benchmark: inferNumericStrings true vs false ({$rows} rows × 10 cols)" . PHP_EOL . PHP_EOL;
echo "> Dataset is string-heavy: with `true`, every text cell runs the numeric-string regex." . PHP_EOL . PHP_EOL;
echo "| Format / Mode | Median Time (s) | Peak PHP Memory (MB) | Δ time vs infer=true |" . PHP_EOL;
echo "|---|---|---|---|" . PHP_EOL;

foreach (['xlsx', 'ods'] as $format) {
    $writeResults = [];
    $times = ['stringify' => [], 'native' => []];
    for ($i = 0; $i < REPS; $i++) {
        $order = ['stringify', 'native'];
        shuffle($order);
        foreach ($order as $mode) {
            $times[$mode][] = measureTime("write-{$format}-{$mode}", "{$tempDir}/bench-typed-write-tmp.{$format}", $rows);
        }
    }
    foreach (['stringify', 'native'] as $mode) {
        $writeResults[$mode] = [
            'time' => median($times[$mode]),
            'memory' => measureMemory("write-{$format}-{$mode}", "{$tempDir}/bench-typed-write-tmp.{$format}", $rows),
        ];
    }

    $inferTrue = $writeResults['stringify']['time'];
    $inferFalse = $writeResults['native']['time'];
    $delta = $inferTrue > 0 ? (($inferFalse - $inferTrue) / $inferTrue) * 100 : 0.0;

    printf(
        "| %s / infer=true  | %.4f | %.2f | — |" . PHP_EOL,
        strtoupper($format),
        $inferTrue,
        $writeResults['stringify']['memory'],
    );
    printf(
        "| %s / infer=false | %.4f | %.2f | %+.1f%% |" . PHP_EOL,
        strtoupper($format),
        $inferFalse,
        $writeResults['native']['memory'],
        $delta,
    );
}

echo PHP_EOL;
