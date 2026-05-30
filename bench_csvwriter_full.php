<?php
require 'vendor/autoload.php';

use LeKoala\Baresheet\CsvWriter;

function get_writer($escapeFormulas, $hasEncoding) {
    $writer = new CsvWriter();
    $writer->escapeFormulas = $escapeFormulas;
    if ($hasEncoding) $writer->outputEncoding = 'UTF-8';
    return $writer;
}

$rowCount = 50000;
$rowSize = 10;
$data = [];
for ($i = 0; $i < $rowCount; $i++) {
    $row = [];
    for ($j = 0; $j < $rowSize; $j++) {
        $row[] = ($j % 2 === 0) ? "some string $i $j" : "=formula$i$j";
    }
    $data[] = $row;
}

$tempFile = sys_get_temp_dir() . '/bench_csvwriter.csv';

$scenarios = [
    [true, true],
    [true, false],
    [false, true],
    [false, false],
];

foreach ($scenarios as $scenario) {
    list($escapeFormulas, $hasEncoding) = $scenario;
    $writer = get_writer($escapeFormulas, $hasEncoding);

    $start = microtime(true);
    $writer->writeFile($data, $tempFile);
    $time = microtime(true) - $start;

    echo "Escape: " . ($escapeFormulas ? 'yes' : 'no') . ", Encoding: " . ($hasEncoding ? 'yes' : 'no') . " -> Time: $time\n";
}
