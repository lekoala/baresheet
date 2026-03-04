<?php

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\OdsReader;
use League\Csv\Reader as LeagueCsvReader;
use OpenSpout\Reader\CSV\Reader as SpoutCsvReader;
use OpenSpout\Reader\XLSX\Reader as SpoutXlsxReader;
use OpenSpout\Reader\ODS\Reader as SpoutOdsReader;
use Shuchkin\SimpleXLSX;

require dirname(__DIR__) . '/vendor/autoload.php';

$largeCsv = dirname(__DIR__) . '/tests/data/large.csv';
$largeXlsx = dirname(__DIR__) . '/tests/data/large.xlsx';
$largeOds = dirname(__DIR__) . '/tests/data/large.ods';

// Ensure testing files exist, or generate them if they don't
function ensureFileExists($file, $format)
{
    if (!file_exists($file)) {
        echo "Generating dummy $format file: $file" . PHP_EOL;
        $data = [];
        for ($i = 0; $i < 50000; $i++) {
            $data[] = [$i, "fname $i", "sname $i", "email-$i@domain.com"];
        }
        if ($format === 'csv') {
            $writer = new \LeKoala\Baresheet\CsvWriter();
            $writer->writeFile($data, $file);
        } elseif ($format === 'xlsx') {
            $writer = new \LeKoala\Baresheet\XlsxWriter();
            $writer->writeFile($data, $file);
        } elseif ($format === 'ods') {
            $writer = new \LeKoala\Baresheet\OdsWriter();
            $writer->writeFile($data, $file);
        }
    }
}

// Subprocess mode: measure memory for a single library in isolation
if (isset($argv[1]) && $argv[1] === '--memory') {
    $key = $argv[2];
    $file = $argv[3];
    gc_collect_cycles();
    $startMem = memory_get_usage();

    switch ($key) {
        case 'baresheet-csv':
            $reader = new CsvReader();
            foreach ($reader->readFile($file) as $row) {
            }
            break;
        case 'league-csv':
            $reader = LeagueCsvReader::createFromPath($file, 'r');
            foreach ($reader as $row) {
            }
            break;
        case 'openspout-csv':
            $reader = new SpoutCsvReader();
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                }
            }
            $reader->close();
            break;
        case 'baresheet-xlsx':
            $reader = new XlsxReader();
            foreach ($reader->readFile($file) as $row) {
            }
            break;
        case 'simplexlsx-xlsx':
            $xlsx = SimpleXLSX::parse($file);
            foreach ($xlsx->rows() as $row) {
            }
            break;
        case 'openspout-xlsx':
            $reader = new SpoutXlsxReader();
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                }
            }
            $reader->close();
            break;
        case 'baresheet-ods':
            $reader = new OdsReader();
            foreach ($reader->readFile($file) as $row) {
            }
            break;
        case 'openspout-ods':
            $reader = new SpoutOdsReader();
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                }
            }
            $reader->close();
            break;
    }

    // Output peak memory delta in bytes for precision
    printf("%.0f", memory_get_peak_usage() - $startMem);
    exit;
}

ensureFileExists($largeCsv, 'csv');
ensureFileExists($largeXlsx, 'xlsx');
ensureFileExists($largeOds, 'ods');

/**
 * Measure peak memory in an isolated subprocess so memory_get_peak_usage() is accurate.
 */
function measureMemory(string $key, string $file): float
{
    $cmd = PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --memory ' . $key . ' ' . escapeshellarg($file);
    $bytes = (int) trim((string) shell_exec($cmd));
    return $bytes / 1024 / 1024;
}

$reps = 3;

$libraries = [
    'csv' => [
        'Baresheet (CSV)' => ['key' => 'baresheet-csv', 'fn' => static function ($file) {
            $reader = new CsvReader();
            foreach ($reader->readFile($file) as $row) {
            }
        }],
        'League (CSV)' => ['key' => 'league-csv', 'fn' => static function ($file) {
            $reader = LeagueCsvReader::createFromPath($file, 'r');
            foreach ($reader as $row) {
            }
        }],
        'OpenSpout (CSV)' => ['key' => 'openspout-csv', 'fn' => static function ($file) {
            $reader = new SpoutCsvReader();
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                }
            }
            $reader->close();
        }],
    ],
    'xlsx' => [
        'Baresheet (XLSX)' => ['key' => 'baresheet-xlsx', 'fn' => static function ($file) {
            $reader = new XlsxReader();
            foreach ($reader->readFile($file) as $row) {
            }
        }],
        'SimpleXLSX (XLSX)' => ['key' => 'simplexlsx-xlsx', 'fn' => static function ($file) {
            $xlsx = SimpleXLSX::parse($file);
            foreach ($xlsx->rows() as $row) {
            }
        }],
        'OpenSpout (XLSX)' => ['key' => 'openspout-xlsx', 'fn' => static function ($file) {
            $reader = new SpoutXlsxReader();
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                }
            }
            $reader->close();
        }],
    ],
    'ods' => [
        'Baresheet (ODS)' => ['key' => 'baresheet-ods', 'fn' => static function ($file) {
            $reader = new OdsReader();
            foreach ($reader->readFile($file) as $row) {
            }
        }],
        'OpenSpout (ODS)' => ['key' => 'openspout-ods', 'fn' => static function ($file) {
            $reader = new SpoutOdsReader();
            $reader->open($file);
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                }
            }
            $reader->close();
        }],
    ]
];

$files = [
    'csv' => $largeCsv,
    'xlsx' => $largeXlsx,
    'ods' => $largeOds,
];

foreach ($libraries as $format => $adapters) {
    $file = $files[$format];

    echo "## Read Benchmark: " . strtoupper($format) . PHP_EOL . PHP_EOL;
    echo "| Library | Avg Time (s) | Peak Memory (MB) |" . PHP_EOL;
    echo "|---|---|---|" . PHP_EOL;

    $results = [];

    foreach ($adapters as $label => $config) {
        $times = [];

        for ($i = 0; $i < $reps; $i++) {
            $start = microtime(true);
            ($config['fn'])($file);
            $times[] = microtime(true) - $start;
        }

        // Memory measured in isolated subprocess
        $memory = measureMemory($config['key'], $file);

        $results[$label] = [
            'time' => array_sum($times) / count($times),
            'memory' => $memory
        ];
    }

    // Sort by time
    uasort($results, fn($a, $b) => $a['time'] <=> $b['time']);

    foreach ($results as $label => $stats) {
        printf("| %s | %.4f | %.2f |" . PHP_EOL, $label, $stats['time'], $stats['memory']);
    }

    echo PHP_EOL;
}
