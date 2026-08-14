<?php

/**
 * Benchmark: Baresheet vs xlswriter (native C extension).
 *
 * Compares streaming XLSX reads and writes on a 100k x 10 dataset across
 * three read workloads (numeric, mixed, string-heavy / shared strings).
 *
 * Usage:
 *   php bin/bench-xlswriter.php
 *
 * Fixtures are generated and cached under .temp/ (git-ignored).
 */

use LeKoala\Baresheet\Value\DurationValue;
use LeKoala\Baresheet\Value\TimeValue;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

const ROW_COUNT = 100000;

const REPS = 5;

$tempDir = dirname(__DIR__) . '/.temp';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

/**
 * Build a 10-column row for a given scenario.
 *
 * @return array<int, int|float|string>
 */
function makeRow(string $scenario, int $i): array
{
    switch ($scenario) {
        case 'numeric':
            return [
                $i,
                $i * 2,
                $i * 0.5,
                $i + 1000,
                $i * 3.14,
                $i - 500,
                $i * 1.1,
                $i % 7,
                $i + 1,
                $i * 42,
            ];
        case 'mixed':
            return [
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
        case 'strings':
        default:
            return [
                "unique string $i col 0 with padding",
                "unique string $i col 1 with padding",
                "unique string $i col 2 with padding",
                "unique string $i col 3 with padding",
                "unique string $i col 4 with padding",
                "unique string $i col 5 with padding",
                "unique string $i col 6 with padding",
                "unique string $i col 7 with padding",
                "unique string $i col 8 with padding",
                "unique string $i col 9 with padding",
            ];
    }
}

/**
 * @return \Generator<int, array<int, int|float|string>>
 */
function rowGenerator(string $scenario, int $rows): Generator
{
    for ($i = 1; $i <= $rows; $i++) {
        yield makeRow($scenario, $i);
    }
}

// Subprocess mode: measure peak memory delta (bytes) for a single library in isolation.
if (isset($argv[1]) && $argv[1] === '--memory') {
    $key = $argv[2];
    $file = $argv[3];
    $rows = isset($argv[4]) ? (int) $argv[4] : ROW_COUNT;

    $isWrite = str_starts_with($key, 'write-');
    if ($isWrite) {
        $data = [];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = makeRow('mixed', $i);
        }
    }

    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }
    $startMem = memory_get_usage();

    switch ($key) {
        case 'read-baresheet':
            $reader = new XlsxReader();
            foreach ($reader->readFile($file) as $row) {
            }
            break;
        case 'read-baresheet-native':
            $reader = new XlsxReader();
            $reader->stringifyValues = false;
            foreach ($reader->readFile($file) as $row) {
            }
            break;
        case 'read-xlswriter':
            $excel = new \Vtiful\Kernel\Excel(['path' => dirname($file)]);
            $excel->openFile(basename($file))->openSheet();
            while ($excel->nextRow() !== null) {
            }
            break;
        case 'write-baresheet':
            $writer = new XlsxWriter();
            $writer->writeFile($data, $file);
            break;
        case 'write-xlswriter':
            $excel = new \Vtiful\Kernel\Excel(['path' => dirname($file)]);
            $excel->fileName(basename($file))->data($data)->output();
            break;
        case 'write-xlswriter-const':
            $excel = new \Vtiful\Kernel\Excel(['path' => dirname($file)]);
            $excel = $excel->constMemory(basename($file));
            foreach ($data as $row) {
                $excel->data([$row]);
            }
            $excel->output();
            break;
    }

    if ($isWrite && file_exists($file)) {
        unlink($file);
    }

    printf('%.0f', memory_get_peak_usage() - $startMem);
    exit();
}

/** Measure peak read memory (MB) in an isolated subprocess. */
function measureReadMemory(string $key, string $file): float
{
    $cmd =
        PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --memory ' . escapeshellarg($key) . ' ' . escapeshellarg($file);
    $bytes = (int) trim((string) shell_exec($cmd));
    return ($bytes / 1024) / 1024;
}

/** Measure peak write memory (MB) in an isolated subprocess. */
function measureWriteMemory(string $key, int $rows, string $tempDir): float
{
    $base = tempnam($tempDir, 'bench_mem_');
    $tmp = $base . '.xlsx';
    @unlink($base);

    $cmd =
        PHP_BINARY
        . ' '
        . escapeshellarg(__FILE__)
        . ' --memory '
        . escapeshellarg($key)
        . ' '
        . escapeshellarg($tmp)
        . ' '
        . escapeshellarg((string) $rows);
    $bytes = (int) trim((string) shell_exec($cmd));
    return ($bytes / 1024) / 1024;
}

/** @param list<float> $values */
function median(array $values): float
{
    sort($values);
    $middle = intdiv(count($values), 2);
    return (count($values) % 2) === 0
        ? ($values[$middle - 1] + $values[$middle]) / 2
        : $values[$middle];
}

/** Ensure a fixture exists, generating it with Baresheet if missing. */
function ensureFixture(string $file, string $scenario, bool $shared, int $rows): void
{
    if (is_file($file)) {
        return;
    }
    echo "Generating fixture: $file" . PHP_EOL;
    $writer = new XlsxWriter();
    $writer->sharedStrings = $shared;
    $writer->writeFile(rowGenerator($scenario, $rows), $file);
}

$readScenarios = [
    'numeric' => ['label' => 'Numeric', 'file' => "$tempDir/bench-xls-numeric.xlsx", 'shared' => false],
    'mixed' => ['label' => 'Mixed', 'file' => "$tempDir/bench-xls-mixed.xlsx", 'shared' => false],
    'strings' => ['label' => 'String-heavy', 'file' => "$tempDir/bench-xls-strings.xlsx", 'shared' => true],
];

echo "# Read Benchmark: XLSX ({$tempDir})\n\n";
echo "> Value semantics differ per mode/library:\n";
echo ">   Baresheet legacy (stringifyValues=true)   -> CSV-like strings (current default)\n";
echo ">   Baresheet native (stringifyValues=false)  -> int|float|bool, DateTimeImmutable\n";
echo ">       for dates, canonical strings for time/duration\n";
echo ">   xlswriter -> typed int/float, but dates/times/durations come back as raw\n";
echo ">       Excel serial floats, booleans as int 1/0, and numeric-looking text is\n";
echo ">       coerced to a number on read\n";
echo "> Memory caveat: memory_get_peak_usage() only tracks PHP allocations;\n";
echo "> xlswriter's C-side memory (workbook, shared strings) is not included.\n\n";

foreach ($readScenarios as $key => $cfg) {
    ensureFixture($cfg['file'], $key, $cfg['shared'], ROW_COUNT);

    echo "## Read: {$cfg['label']}\n\n";
    echo "| Library | Median Time (s) | Peak PHP Memory (MB) | vs fastest |\n";
    echo "|---|---|---|---|\n";

    $results = [];

    foreach ([
        ['Baresheet (legacy)', false],
        ['Baresheet (native)', true],
    ] as [$label, $native]) {
        $modeKey = $native ? 'read-baresheet-native' : 'read-baresheet';
        $times = [];
        for ($i = 0; $i < REPS; $i++) {
            $start = microtime(true);
            $reader = new XlsxReader();
            $reader->stringifyValues = !$native;
            foreach ($reader->readFile($cfg['file']) as $row) {
            }
            $times[] = microtime(true) - $start;
        }
        $results[$label] = [
            'time' => median($times),
            'memory' => measureReadMemory($modeKey, $cfg['file']),
        ];
    }

    $times = [];
    for ($i = 0; $i < REPS; $i++) {
        $start = microtime(true);
        $excel = new \Vtiful\Kernel\Excel(['path' => dirname($cfg['file'])]);
        $excel->openFile(basename($cfg['file']))->openSheet();
        while ($excel->nextRow() !== null) {
        }
        $times[] = microtime(true) - $start;
    }
    $results['xlswriter'] = [
        'time' => median($times),
        'memory' => measureReadMemory('read-xlswriter', $cfg['file']),
    ];

    uasort($results, fn($a, $b) => $a['time'] <=> $b['time']);
    $fastest = min(array_column($results, 'time'));

    foreach ($results as $label => $stats) {
        $ratio = sprintf('%.2fx', $stats['time'] / $fastest);
        printf('| %s | %.4f | %.2f | %s |' . PHP_EOL, $label, $stats['time'], $stats['memory'], $ratio);
    }

    echo PHP_EOL;
}

echo "# Write Benchmark: XLSX ({$tempDir})\n\n";
echo "> Memory caveat: memory_get_peak_usage() only tracks PHP allocations;\n";
echo "> xlswriter's C-side workbook memory is not included.\n\n";
echo "| Library | Median Time (s) | Peak PHP Memory (MB) | vs fastest |\n";
echo "|---|---|---|---|\n";

$data = [];
for ($i = 1; $i <= ROW_COUNT; $i++) {
    $data[] = makeRow('mixed', $i);
}

$writeResults = [];

$times = [];
for ($i = 0; $i < REPS; $i++) {
    $tmp = $tempDir . '/bench-write-baresheet.xlsx';
    $start = microtime(true);
    $writer = new XlsxWriter();
    $writer->writeFile($data, $tmp);
    $times[] = microtime(true) - $start;
    @unlink($tmp);
}
$writeResults['Baresheet'] = [
    'time' => median($times),
    'memory' => measureWriteMemory('write-baresheet', ROW_COUNT, $tempDir),
];

$times = [];
for ($i = 0; $i < REPS; $i++) {
    $tmp = $tempDir . '/bench-write-xlswriter.xlsx';
    $start = microtime(true);
    $excel = new \Vtiful\Kernel\Excel(['path' => $tempDir]);
    $excel->fileName(basename($tmp))->data($data)->output();
    $times[] = microtime(true) - $start;
    @unlink($tmp);
}
$writeResults['xlswriter'] = [
    'time' => median($times),
    'memory' => measureWriteMemory('write-xlswriter', ROW_COUNT, $tempDir),
];

$times = [];
for ($i = 0; $i < REPS; $i++) {
    $tmp = $tempDir . '/bench-write-xlswriter-const.xlsx';
    $start = microtime(true);
    $excel = new \Vtiful\Kernel\Excel(['path' => $tempDir]);
    $excel = $excel->constMemory(basename($tmp));
    foreach ($data as $row) {
        $excel->data([$row]);
    }
    $excel->output();
    $times[] = microtime(true) - $start;
    @unlink($tmp);
}
$writeResults['xlswriter (const memory)'] = [
    'time' => median($times),
    'memory' => measureWriteMemory('write-xlswriter-const', ROW_COUNT, $tempDir),
];

uasort($writeResults, fn($a, $b) => $a['time'] <=> $b['time']);
$fastest = min(array_column($writeResults, 'time'));

foreach ($writeResults as $label => $stats) {
    $ratio = sprintf('%.2fx', $stats['time'] / $fastest);
    printf('| %s | %.4f | %.2f | %s |' . PHP_EOL, $label, $stats['time'], $stats['memory'], $ratio);
}

echo PHP_EOL;

// --- Read type parity: what PHP values each reader returns on the same file ---

echo "# Read Type Parity (Baresheet native vs xlswriter)\n\n";
echo "> Same XLSX written by Baresheet (native markers), read back by both readers.\n\n";

$parityFile = "$tempDir/bench-types.xlsx";
$parityRows = [
    ['int', 42],
    ['float', 12.5],
    ['bool', true],
    ['text', '00123'],
    ['date', new \DateTimeImmutable('2026-08-13 14:30:15', new \DateTimeZone('UTC'))],
    ['time', new TimeValue(14, 30, 15, 500000)],
    ['duration', new DurationValue(36, 30, 15)],
];
$pw = new XlsxWriter();
$pw->inferNumericStrings = false;
$pw->writeFile($parityRows, $parityFile);

/** Describe a value with its PHP type. */
function describeValue(mixed $v): string
{
    if ($v instanceof DateTimeImmutable) {
        return 'DateTimeImmutable(' . $v->format('Y-m-d H:i:s.u') . ')';
    }
    if (is_bool($v)) {
        return 'bool(' . ($v ? 'true' : 'false') . ')';
    }
    return get_debug_type($v) . '(' . var_export($v, true) . ')';
}

$pr = new XlsxReader();
$pr->stringifyValues = false;
$baresheetRows = [];
foreach ($pr->readFile($parityFile) as $row) {
    $baresheetRows[] = $row;
}

$xr = new \Vtiful\Kernel\Excel(['path' => $tempDir]);
$xr->openFile(basename($parityFile))->openSheet();
$xlswriterRows = [];
while (($row = $xr->nextRow()) !== null) {
    $xlswriterRows[] = $row;
}

echo "| Cell | Baresheet native | xlswriter |\n";
echo "|---|---|---|\n";
for ($i = 0; $i < count($parityRows); $i++) {
    $label = $baresheetRows[$i][0] ?? '';
    $baresheet = describeValue($baresheetRows[$i][1] ?? null);
    $xlswriter = describeValue($xlswriterRows[$i][1] ?? null);
    printf("| %s | %s | %s |\n", $label, $baresheet, $xlswriter);
}

echo PHP_EOL;
@unlink($parityFile);
