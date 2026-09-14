<?php

require 'vendor/autoload.php';

use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Options;

$file = tempnam(sys_get_temp_dir(), 'ods_bench_') . '.ods';

$rows = [];
$numRows = 50000;
$numCols = 50;

// Generate column keys
$cols = [];
for ($i = 0; $i < $numCols; $i++) {
    $cols[] = "Col_$i";
}

// First row defines the order
$firstRow = [];
foreach ($cols as $col) {
    $firstRow[$col] = "Value";
}
$rows[] = $firstRow;

// Subsequent rows have a different key order to trigger alignment logic
$shuffledCols = $cols;
shuffle($shuffledCols);

for ($r = 1; $r < $numRows; $r++) {
    $row = [];
    foreach ($shuffledCols as $col) {
        $row[$col] = "Value";
    }
    $rows[] = $row;
}

echo "Writing $numRows rows with $numCols columns to $file\n";

$start = microtime(true);
Baresheet::write($rows, $file);
$end = microtime(true);

echo "Time taken: " . ($end - $start) . " seconds\n";

unlink($file);
