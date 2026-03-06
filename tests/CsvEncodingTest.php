<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\Options;
use PHPUnit\Framework\TestCase;

class CsvEncodingTest extends TestCase
{
    public function testBomPriority(): void
    {
        // UTF-16LE CSV: "col1,col2\nrow1,row2"
        $utf16csv = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', "col1,col2\nrow1,row2");
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $utf16csv);
        rewind($stream);

        $reader = new CsvReader();
        // Even if we set a wrong inputEncoding, the BOM should take priority
        $reader->inputEncoding = 'ISO-8859-1';
        $reader->outputEncoding = 'UTF-8';
        $reader->assoc = true;

        $data = iterator_to_array($reader->readStream($stream));
        $this->assertCount(1, $data);
        $this->assertEquals(['col1' => 'row1', 'col2' => 'row2'], $data[0]);
        fclose($stream);
    }

    public function testAutoEncodingDetection(): void
    {
        // ISO-8859-1 CSV: "é,à" (UTF-8 would be \xc3\xa9, \xc3\xa0)
        $isoCsv = iconv('UTF-8', 'ISO-8859-1', "é,à");
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $isoCsv);
        rewind($stream);

        $reader = new CsvReader();
        $reader->outputEncoding = 'UTF-8'; // Trigger conversion
        $reader->inputEncoding = 'auto';

        $data = iterator_to_array($reader->readStream($stream));
        $this->assertEquals('é', $data[0][0]);
        $this->assertEquals('à', $data[0][1]);
        fclose($stream);
    }

    public function testMalformedCsvDetection(): void
    {
        // Malformed CSV with unclosed quote
        $malformed = "col1,col2\n\"unclosed,quote\nrow2,data";
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $malformed);
        rewind($stream);

        $reader = new CsvReader();
        $reader->strict = true;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Potential malformed data or unclosed quote');

        iterator_to_array($reader->readStream($stream));
        fclose($stream);
    }
}
