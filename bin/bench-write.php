<?php

use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\XlsxWriter;
use League\Csv\Writer as LeagueCsvWriter;
use OpenSpout\Writer\CSV\Writer as SpoutCsvWriter;
use OpenSpout\Writer\XLSX\Writer as SpoutXlsxWriter;
use OpenSpout\Writer\ODS\Writer as SpoutOdsWriter;
use Shuchkin\SimpleXLSXGen;

require dirname(__DIR__) . '/vendor/autoload.php';

// Subprocess mode: measure memory for a single library in isolation
if (isset($argv[1]) && $argv[1] === '--memory') {
    $key = $argv[2];
    $file = $argv[3];
    $rowCount = isset($argv[4]) ? (int)$argv[4] : 50000;

    // Generate data in-process to match main benchmark
    $data = [];
    for ($i = 1; $i <= $rowCount; $i++) {
        $data[] = [$i, "fname $i", "sname $i", "email-$i@domain.com"];
    }

    gc_collect_cycles();
    $startMem = memory_get_usage();

    switch ($key) {
        case 'baresheet-csv':
            $writer = new CsvWriter();
            $writer->writeFile($data, $file);
            break;
        case 'league-csv':
            $writer = LeagueCsvWriter::createFromPath($file, 'w+');
            $writer->insertAll($data);
            break;
        case 'openspout-csv':
            $writer = new SpoutCsvWriter();
            $writer->openToFile($file);
            foreach ($data as $row) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
            }
            $writer->close();
            break;
        case 'baresheet-xlsx':
            $writer = new XlsxWriter();
            $writer->writeFile($data, $file);
            break;
        case 'baresheet-xlsx-shared':
            $writer = new XlsxWriter();
            $writer->sharedStrings = true;
            $writer->writeFile($data, $file);
            break;
        case 'baresheet-xlsx-autowidth':
            $writer = new XlsxWriter();
            $writer->autoWidth = true;
            $writer->writeFile($data, $file);
            break;
        case 'baresheet-xlsx-full':
            $writer = new XlsxWriter();
            $writer->sharedStrings = true;
            $writer->autoWidth = true;
            $writer->writeFile($data, $file);
            break;
        case 'simplexlsxgen-xlsx':
            $xlsx = SimpleXLSXGen::fromArray($data);
            $xlsx->saveAs($file);
            break;
        case 'openspout-xlsx':
            $writer = new SpoutXlsxWriter();
            $writer->openToFile($file);
            foreach ($data as $row) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
            }
            $writer->close();
            break;
        case 'baresheet-ods':
            $writer = new OdsWriter();
            $writer->writeFile($data, $file);
            break;
        case 'openspout-ods':
            $writer = new SpoutOdsWriter();
            $writer->openToFile($file);
            foreach ($data as $row) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
            }
            $writer->close();
            break;
    }

    if (file_exists($file)) {
        unlink($file);
    }

    printf("%.0f", memory_get_peak_usage() - $startMem);
    exit;
}

/**
 * Measure peak memory in an isolated subprocess so memory_get_peak_usage() is accurate.
 */
function measureWriteMemory(string $key, string $format, int $rowCount): float
{
    $tempFile = sys_get_temp_dir() . '/bench_mem_' . $key . '_' . $rowCount . '.' . $format;
    $cmd = PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' --memory ' . escapeshellarg($key) . ' ' . escapeshellarg($tempFile) . ' ' . escapeshellarg((string)$rowCount);
    $bytes = (int) trim((string) shell_exec($cmd));
    return $bytes / 1024 / 1024;
}

$reps = 3;

$libraries = [
    'csv' => [
        'Baresheet (CSV)' => ['key' => 'baresheet-csv', 'fn' => function ($file, $data) {
            $writer = new CsvWriter();
            $writer->writeFile($data, $file);
        }],
        'League (CSV)' => ['key' => 'league-csv', 'fn' => function ($file, $data) {
            $writer = LeagueCsvWriter::createFromPath($file, 'w+');
            $writer->insertAll($data);
        }],
        'OpenSpout (CSV)' => ['key' => 'openspout-csv', 'fn' => function ($file, $data) {
            $writer = new SpoutCsvWriter();
            $writer->openToFile($file);
            foreach ($data as $row) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
            }
            $writer->close();
        }],
    ],
    'xlsx' => [
        'Baresheet (XLSX)' => ['key' => 'baresheet-xlsx', 'fn' => function ($file, $data) {
            $writer = new XlsxWriter();
            $writer->writeFile($data, $file);
        }],
        'Baresheet (XLSX - Shared Strings)' => ['key' => 'baresheet-xlsx-shared', 'fn' => function ($file, $data) {
            $writer = new XlsxWriter();
            $writer->sharedStrings = true;
            $writer->writeFile($data, $file);
        }],
        'Baresheet (XLSX - Auto Width)' => ['key' => 'baresheet-xlsx-autowidth', 'fn' => function ($file, $data) {
            $writer = new XlsxWriter();
            $writer->autoWidth = true;
            $writer->writeFile($data, $file);
        }],
        'Baresheet (XLSX - Full)' => ['key' => 'baresheet-xlsx-full', 'fn' => function ($file, $data) {
            $writer = new XlsxWriter();
            $writer->sharedStrings = true;
            $writer->autoWidth = true;
            $writer->writeFile($data, $file);
        }],
        'SimpleXLSXGen (XLSX)' => ['key' => 'simplexlsxgen-xlsx', 'fn' => function ($file, $data) {
            $xlsx = SimpleXLSXGen::fromArray($data);
            $xlsx->saveAs($file);
        }],
        'OpenSpout (XLSX)' => ['key' => 'openspout-xlsx', 'fn' => function ($file, $data) {
            $writer = new SpoutXlsxWriter();
            $writer->openToFile($file);
            foreach ($data as $row) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
            }
            $writer->close();
        }],
    ],
    'ods' => [
        'Baresheet (ODS)' => ['key' => 'baresheet-ods', 'fn' => function ($file, $data) {
            $writer = new OdsWriter();
            $writer->writeFile($data, $file);
        }],
        'OpenSpout (ODS)' => ['key' => 'openspout-ods', 'fn' => function ($file, $data) {
            $writer = new SpoutOdsWriter();
            $writer->openToFile($file);
            foreach ($data as $row) {
                $writer->addRow(\OpenSpout\Common\Entity\Row::fromValues($row));
            }
            $writer->close();
        }],
    ]
];

$rowCounts = [2500, 50000];

foreach ($rowCounts as $rowCount) {
    echo "# Benchmark with $rowCount rows\n\n";

    $genData = [];
    for ($i = 1; $i <= $rowCount; $i++) {
        $genData[] = [$i, "fname $i", "sname $i", "email-$i@domain.com"];
    }

    foreach ($libraries as $format => $adapters) {
        echo "## Write Benchmark: " . strtoupper($format) . PHP_EOL . PHP_EOL;
        echo "| Library | Avg Time (s) | Peak Memory (MB) |" . PHP_EOL;
        echo "|---|---|---|" . PHP_EOL;

        $results = [];

        foreach ($adapters as $label => $config) {
            $times = [];

            for ($i = 0; $i < $reps; $i++) {
                $tempFile = sys_get_temp_dir() . '/bench_' . time() . '_' . $i . '.' . $format;

                $start = microtime(true);
                ($config['fn'])($tempFile, $genData);
                $times[] = microtime(true) - $start;

                if (file_exists($tempFile)) {
                    unlink($tempFile);
                }
            }

            // Memory measured in isolated subprocess
            $memory = measureWriteMemory($config['key'], $format, $rowCount);

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
}
