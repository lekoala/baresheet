<?php

/**
 * Benchmark: Column Selection Performance
 * 
 * Compares reading all columns vs selecting specific columns from wide files.
 * Tests the optimizations in XlsxReader and OdsReader.
 */

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\XlsxWriter;
use LeKoala\Baresheet\OdsWriter;

require dirname(__DIR__) . '/vendor/autoload.php';

$rowCount = 50000;
$allColumns = [
    'id', 'first_name', 'last_name', 'email', 'phone', 
    'address', 'city', 'country', 'postal_code', 'company',
    'department', 'job_title', 'salary', 'start_date', 'status',
    'manager_id', 'office_location', 'employee_type', 'level', 'notes'
];

$selectedColumns = ['id', 'first_name', 'last_name', 'email', 'salary'];

echo "=== Column Selection Performance Benchmark ===\n";
echo "Rows: $rowCount\n";
echo "Total columns: " . count($allColumns) . "\n";
echo "Selected columns: " . count($selectedColumns) . "\n\n";

// Generate test data
function generateWideData($count, $columns) {
    $data = [];
    for ($i = 1; $i <= $count; $i++) {
        $row = [];
        foreach ($columns as $col) {
            $row[$col] = match ($col) {
                'id' => $i,
                'first_name' => "First$i",
                'last_name' => "Last$i",
                'email' => "user$i@example.com",
                'phone' => "+1-555-" . str_pad((string)$i, 4, '0', STR_PAD_LEFT),
                'address' => "$i Main Street",
                'city' => 'New York',
                'country' => 'USA',
                'postal_code' => '1000' . ($i % 10),
                'company' => 'Acme Corp',
                'department' => 'Engineering',
                'job_title' => 'Developer',
                'salary' => 50000 + ($i * 100),
                'start_date' => '2020-01-15',
                'status' => 'active',
                'manager_id' => (int)($i / 10),
                'office_location' => 'HQ',
                'employee_type' => 'full-time',
                'level' => 'senior',
                'notes' => "Notes for employee $i with some additional text here",
                default => "value_$i"
            };
        }
        $data[] = $row;
    }
    return $data;
}

// Generate test files
$testFiles = [
    'csv' => sys_get_temp_dir() . '/bench_columns_wide.csv',
    'xlsx' => sys_get_temp_dir() . '/bench_columns_wide.xlsx',
    'ods' => sys_get_temp_dir() . '/bench_columns_wide.ods',
];

echo "Generating test files...\n";
$data = generateWideData($rowCount, $allColumns);

foreach ($testFiles as $format => $file) {
    if (!file_exists($file)) {
        echo "  Creating $format file...\n";
        $writer = match ($format) {
            'csv' => new CsvWriter(new Options(headers: $allColumns)),
            'xlsx' => new XlsxWriter(new Options(headers: $allColumns)),
            'ods' => new OdsWriter(new Options(headers: $allColumns)),
        };
        $writer->writeFile($data, $file);
    }
}
echo "Files ready.\n\n";

// Cleanup function
function cleanupFiles($files) {
    foreach ($files as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
}

// Register cleanup on exit
register_shutdown_function(function() use ($testFiles) {
    cleanupFiles($testFiles);
});

$reps = 3;

function benchmarkRead(string $label, callable $fn, string $file, int $reps): array {
    $times = [];
    $memories = [];
    
    for ($i = 0; $i < $reps; $i++) {
        gc_collect_cycles();
        $startMem = memory_get_usage();
        $start = microtime(true);
        $fn($file);
        $times[] = microtime(true) - $start;
        $memories[] = (memory_get_peak_usage() - $startMem) / 1024 / 1024;
    }
    
    return [
        'time' => array_sum($times) / count($times),
        'memory' => array_sum($memories) / count($memories)
    ];
}

// Main benchmark
echo "Running benchmarks ($reps repetitions each)...\n\n";

$benchmarks = [
    'csv' => [
        'All 20 columns' => ['fn' => function($file) {
            $reader = new CsvReader(new Options(assoc: true));
            foreach ($reader->readFile($file) as $row) {}
        }],
        '5 selected columns' => ['fn' => function($file) {
            $reader = new CsvReader(new Options(assoc: true, columns: ['id', 'first_name', 'last_name', 'email', 'salary']));
            foreach ($reader->readFile($file) as $row) {}
        }],
    ],
    'xlsx' => [
        'All 20 columns' => ['fn' => function($file) {
            $reader = new XlsxReader(new Options(assoc: true));
            foreach ($reader->readFile($file) as $row) {}
        }],
        '5 selected columns' => ['fn' => function($file) {
            $reader = new XlsxReader(new Options(assoc: true, columns: ['id', 'first_name', 'last_name', 'email', 'salary']));
            foreach ($reader->readFile($file) as $row) {}
        }],
    ],
    'ods' => [
        'All 20 columns' => ['fn' => function($file) {
            $reader = new OdsReader(new Options(assoc: true));
            foreach ($reader->readFile($file) as $row) {}
        }],
        '5 selected columns' => ['fn' => function($file) {
            $reader = new OdsReader(new Options(assoc: true, columns: ['id', 'first_name', 'last_name', 'email', 'salary']));
            foreach ($reader->readFile($file) as $row) {}
        }],
    ],
];

foreach ($benchmarks as $format => $tests) {
    $file = $testFiles[$format];
    
    echo "## $format (" . strtoupper($format) . ")\n\n";
    echo "| Mode | Avg Time (s) | Peak Memory (MB) | Speedup |\n";
    echo "|---|---|---|---|\n";
    
    $results = [];
    foreach ($tests as $label => $config) {
        echo "  Benchmarking: $label...\n";
        $results[$label] = benchmarkRead($label, $config['fn'], $file, $reps);
    }
    
    // Calculate speedup
    $allTime = $results['All 20 columns']['time'];
    $allMem = $results['All 20 columns']['memory'];
    $colTime = $results['5 selected columns']['time'];
    $colMem = $results['5 selected columns']['memory'];
    
    $timeSpeedup = round($allTime / $colTime, 2) . 'x';
    $memSavings = round((($allMem - $colMem) / $allMem) * 100, 1) . '%';
    
    foreach ($results as $label => $stats) {
        $speedup = ($label === '5 selected columns') ? "$timeSpeedup / $memSavings saved" : 'baseline';
        printf("| %s | %.4f | %.2f | %s |\n", $label, $stats['time'], $stats['memory'], $speedup);
    }
    
    echo "\n";
}

echo "=== Summary ===\n";
echo "With optimizations:\n";
echo "- XLSX/ODS: Should show 15-25% speedup for 5/20 column selection\n";
echo "- CSV: Minimal overhead (~0-2%), no cell-level skipping possible\n";
echo "- Real value: Convenience, cleaner output, validation\n";
