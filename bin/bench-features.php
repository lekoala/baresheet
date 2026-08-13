<?php

/**
 * Baresheet 0.6 feature regression benchmark.
 *
 * Measures the cost of new abstractions (HeaderSchema, headerRows, etc.)
 * relative to the classic flat-assoc CSV read path. Baresheet vs Baresheet only.
 *
 * Usage:
 *   php bin/bench-features.php
 */

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\XlsxWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

$rowCount = 50000;
$colCount = 10;
$reps = 5;
$warmupEnabled = true;

// ─── Helpers ────────────────────────────────────────────────

function median(array $values): float
{
    sort($values);
    return $values[intdiv(count($values), 2)];
}

function generateFlatData(int $rows, int $cols): array
{
    $data = [];
    for ($i = 1; $i <= $rows; $i++) {
        $row = [];
        for ($c = 0; $c < $cols; $c++) {
            $row[] = "col{$c}_val{$i}";
        }
        $data[] = $row;
    }
    return $data;
}

function generateNestedData(int $rows): array
{
    $data = [];
    for ($i = 1; $i <= $rows; $i++) {
        $data[] = [
            'Identity' => [
                'id' => $i,
                'first_name' => "First{$i}",
                'last_name' => "Last{$i}",
            ],
            'Contact' => [
                'email' => "user{$i}@example.com",
                'phone' => "+1-555-" . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
            ],
            'Meta' => [
                'status' => 'active',
                'level' => 'senior',
                'department' => 'Engineering',
            ],
        ];
    }
    return $data;
}

function timeIt(callable $fn, int $reps, string $label, ?int $expectedRows = null): float
{
    global $warmupEnabled;

    if ($warmupEnabled) {
        $fn(); // warmup, not counted
    }

    $times = [];
    for ($i = 0; $i < $reps; $i++) {
        $start = microtime(true);
        $actualRows = $fn();
        $times[] = microtime(true) - $start;

        // Sanity check: ensure we actually consumed the expected number of rows
        if ($expectedRows !== null && $actualRows !== $expectedRows) {
            throw new RuntimeException(
                "Sanity check failed for '$label': expected {$expectedRows} rows, got {$actualRows}"
            );
        }
    }

    return median($times);
}

function makeTempCsv(string $content): string
{
    $file = tempnam(sys_get_temp_dir(), 'bench_feat_') . '.csv';
    file_put_contents($file, $content);
    return $file;
}

function cleanup(string ...$files): void
{
    foreach ($files as $f) {
        if (file_exists($f)) {
            unlink($f);
        }
    }
}

// ─── Generate test data ─────────────────────────────────────

echo "Generating {$rowCount} rows × {$colCount} columns test data..." . PHP_EOL;

// Flat CSV with header
$flatHeader = implode(',', array_map(fn(int $c) => "col{$c}", range(0, $colCount - 1)));
$flatRows = implode("\n", array_map(
    fn(int $i) => implode(',', array_map(
        fn(int $c) => "col{$c}_val{$i}",
        range(0, $colCount - 1),
    )),
    range(1, $rowCount),
));
$flatCsv = "{$flatHeader}\n{$flatRows}\n";

// Nested CSV (2-level header)
$nestedHeader = "Identity" . str_repeat(',', 3) . "Contact" . str_repeat(',', 2) . "Meta" . str_repeat(',', 2) . "\n";
$nestedHeader .= "id,first_name,last_name,role,email,phone,type,status,level,department\n";
$nestedRows = implode("\n", array_map(
    fn(int $i) => implode(',', [
        $i,
        "First{$i}",
        "Last{$i}",
        "Developer",
        "user{$i}@example.com",
        "+1-555-" . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
        "full-time",
        "active",
        "senior",
        "Engineering",
    ]),
    range(1, $rowCount),
));
$nestedCsv = "{$nestedHeader}{$nestedRows}\n";

// CSV with preamble (10 lines before header)
$preamble = implode("\n", array_map(fn(int $i) => "Preamble line {$i}", range(1, 10)));
$preambleCsv = "{$preamble}\n{$flatHeader}\n{$flatRows}\n";

// Write flat + nested temp files
$flatFile = makeTempCsv($flatCsv);
$nestedFile = makeTempCsv($nestedCsv);
$preambleFile = makeTempCsv($preambleCsv);

register_shutdown_function(static function () use ($flatFile, $nestedFile, $preambleFile) {
    cleanup($flatFile, $nestedFile, $preambleFile);
});

echo "Files ready." . PHP_EOL . PHP_EOL;

// ─── Benchmarks ─────────────────────────────────────────────

$results = [];

echo "=== Baresheet 0.6 Feature Benchmarks ===" . PHP_EOL;
echo "Rows: {$rowCount}, Cols: {$colCount}, Reps: {$reps}" . PHP_EOL . PHP_EOL;

// csv.read.assoc-flat — baseline
$results['csv.read.assoc-flat'] = timeIt(
    static function () use ($flatFile, $rowCount) {
        $reader = new CsvReader(new Options(assoc: true));
        $count = 0;
        foreach ($reader->readFile($flatFile) as $row) {
            $count++;
        }
        return $count;
    },
    $reps,
    'assoc-flat',
    $rowCount,
);

// csv.read.assoc-nested — headerRows: 2
$results['csv.read.assoc-nested'] = timeIt(
    static function () use ($nestedFile, $rowCount) {
        $reader = new CsvReader(new Options(assoc: true, headerRows: 2));
        $count = 0;
        foreach ($reader->readFile($nestedFile) as $row) {
            $count++;
        }
        return $count;
    },
    $reps,
    'assoc-nested',
    $rowCount,
);

// csv.read.header-offset — headerOffset: 10
$results['csv.read.header-offset'] = timeIt(
    static function () use ($preambleFile, $rowCount) {
        $reader = new CsvReader(new Options(assoc: true, headerOffset: 10));
        $count = 0;
        foreach ($reader->readFile($preambleFile) as $row) {
            $count++;
        }
        return $count;
    },
    $reps,
    'header-offset',
    $rowCount,
);

// csv.read.header-auto — headerOffset: 'auto' with requiredColumns
$results['csv.read.header-auto'] = timeIt(
    static function () use ($preambleFile, $rowCount) {
        $reader = new CsvReader(new Options(
            assoc: true,
            headerOffset: 'auto',
            requiredColumns: ['col0', 'col1', 'col9'],
        ));
        $count = 0;
        foreach ($reader->readFile($preambleFile) as $row) {
            $count++;
        }
        return $count;
    },
    $reps,
    'header-auto',
    $rowCount,
);

// csv.read.strict — strict: true on valid data
$results['csv.read.strict'] = timeIt(
    static function () use ($flatFile, $rowCount) {
        $reader = new CsvReader(new Options(assoc: true, strict: true));
        $count = 0;
        foreach ($reader->readFile($flatFile) as $row) {
            $count++;
        }
        return $count;
    },
    $reps,
    'strict-read',
    $rowCount,
);

// csv.read.columns — select 4 of 10 columns
$results['csv.read.columns'] = timeIt(
    static function () use ($flatFile, $rowCount) {
        $reader = new CsvReader(new Options(
            assoc: true,
            columns: ['col0', 'col1', 'col5', 'col9'],
        ));
        $count = 0;
        foreach ($reader->readFile($flatFile) as $row) {
            $count++;
        }
        return $count;
    },
    $reps,
    'columns',
    $rowCount,
);

// csv.write.plain — baseline write
$flatData = generateFlatData($rowCount, $colCount);
$results['csv.write.plain'] = timeIt(
    static function () use ($flatData, $rowCount) {
        $writer = new CsvWriter();
        $writer->bom = false;
        $file = tempnam(sys_get_temp_dir(), 'bench_write_') . '.csv';
        $writer->writeFile($flatData, $file);
        $size = filesize($file);
        @unlink($file);
        if ($size <= 0) {
            throw new RuntimeException('Write produced empty file');
        }
        return $rowCount;
    },
    $reps,
    'write-plain',
    $rowCount,
);

// csv.write.strict — strict: true
$results['csv.write.strict'] = timeIt(
    static function () use ($flatData, $rowCount, $colCount) {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->strict = true;
        $writer->headers = array_map(fn(int $c) => "col{$c}", range(0, $colCount - 1));
        $file = tempnam(sys_get_temp_dir(), 'bench_write_') . '.csv';
        $writer->writeFile($flatData, $file);
        $size = filesize($file);
        @unlink($file);
        if ($size <= 0) {
            throw new RuntimeException('Write produced empty file');
        }
        return $rowCount;
    },
    $reps,
    'write-strict',
    $rowCount,
);

// csv.write.nested — hierarchical write
$nestedData = generateNestedData($rowCount);
$results['csv.write.nested'] = timeIt(
    static function () use ($nestedData, $rowCount) {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->headers = [
            'Identity' => ['id', 'first_name', 'last_name', 'role'],
            'Contact' => ['email', 'phone', 'type'],
            'Meta' => ['status', 'level', 'department'],
        ];
        $file = tempnam(sys_get_temp_dir(), 'bench_write_') . '.csv';
        $writer->writeFile($nestedData, $file);
        $size = filesize($file);
        @unlink($file);
        if ($size <= 0) {
            throw new RuntimeException('Write produced empty file');
        }
        return $rowCount;
    },
    $reps,
    'write-nested',
    $rowCount,
);

// Numeric-mixed dataset to exercise Spread::isNumericCellValue on the write path.
// Same shape as $flatData (10 columns) so xlsx.write.numeric is comparable to xlsx.write.plain.
$numericData = [];
for ($i = 1; $i <= $rowCount; $i++) {
    $numericData[] = match ($i % 4) {
        0 => ['00123', '42', '3.14', '-0.5', '1e3', '007', '12345678901234567890', 'name', 'user', 'col'],
        1 => ["col{$i}_value", "name{$i}", '007.5', '0', '+42', '3', 'id', 'text', 'x', 'y'],
        2 => ['12345678901234567890', '1e3', '-0.5', '3.14', '42', '0', '007', 'lead', 'zero', 'str'],
        default => ["id_{$i}", '0', '+42', '007', '1e3', '-0.5', '3.14', '42', '00123', 'long'],
    };
}

// xlsx.write.plain — binary writer, baseline for the extraction overhead question
$results['xlsx.write.plain'] = timeIt(
    static function () use ($flatData, $rowCount) {
        $writer = new XlsxWriter();
        $file = tempnam(sys_get_temp_dir(), 'bench_write_') . '.xlsx';
        $writer->writeFile($flatData, $file);
        $size = filesize($file);
        @unlink($file);
        if ($size <= 0) {
            throw new RuntimeException('Write produced empty file');
        }
        return $rowCount;
    },
    $reps,
    'xlsx-write-plain',
    $rowCount,
);

// xlsx.write.numeric — stresses the numeric-vs-text cell classification
$results['xlsx.write.numeric'] = timeIt(
    static function () use ($numericData, $rowCount) {
        $writer = new XlsxWriter();
        $file = tempnam(sys_get_temp_dir(), 'bench_write_') . '.xlsx';
        $writer->writeFile($numericData, $file);
        $size = filesize($file);
        @unlink($file);
        if ($size <= 0) {
            throw new RuntimeException('Write produced empty file');
        }
        return $rowCount;
    },
    $reps,
    'xlsx-write-numeric',
    $rowCount,
);

// ods.write.plain — ODS writer
$results['ods.write.plain'] = timeIt(
    static function () use ($flatData, $rowCount) {
        $writer = new OdsWriter();
        $file = tempnam(sys_get_temp_dir(), 'bench_write_') . '.ods';
        $writer->writeFile($flatData, $file);
        $size = filesize($file);
        @unlink($file);
        if ($size <= 0) {
            throw new RuntimeException('Write produced empty file');
        }
        return $rowCount;
    },
    $reps,
    'ods-write-plain',
    $rowCount,
);

// ─── Output ─────────────────────────────────────────────────

$readBaseline  = $results['csv.read.assoc-flat'];
$writeBaseline = $results['csv.write.plain'];

echo "┌─────────────────────────────┬──────────┬───────────┐" . PHP_EOL;
echo "│ Scenario                    │ Time (s) │ vs base   │" . PHP_EOL;
echo "├─────────────────────────────┼──────────┼───────────┤" . PHP_EOL;

foreach ($results as $name => $time) {
    $baseline = match (true) {
        str_starts_with($name, 'xlsx.write') => $results['xlsx.write.plain'],
        str_starts_with($name, 'ods.write') => $results['ods.write.plain'],
        str_contains($name, '.write.') => $writeBaseline,
        default => $readBaseline,
    };
    $ratio = $time / $baseline;
    $flag = '';
    if ($ratio > 1.30) {
        $flag = ' ! SUSPICIOUS';
    } elseif ($ratio > 1.15) {
        $flag = ' ! warning';
    }

    printf(
        "│ %-27s │ %8.4f │ %+6.1f%%%s │" . PHP_EOL,
        $name,
        $time,
        ($ratio - 1) * 100,
        $flag,
    );
}

echo "└─────────────────────────────┴──────────┴───────────┘" . PHP_EOL;
echo PHP_EOL;
echo "READ  baseline: csv.read.assoc-flat  = {$readBaseline}s" . PHP_EOL;
echo "WRITE baseline: csv.write.plain      = {$writeBaseline}s" . PHP_EOL;
echo "> 15% = warning, > 30% = suspicious" . PHP_EOL;
