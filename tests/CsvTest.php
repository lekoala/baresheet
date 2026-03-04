<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\Options;

class CsvTest extends TestCase
{
    public function testReadCsvFromFile(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/basic.csv'));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testReadCsvAssoc(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/headers.csv', new Options(assoc: true)));
        self::assertCount(1, $data);
        self::assertArrayHasKey('email', $data[0]);
    }

    public function testReadCsvFromString(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readString("john,doe,john.doe@example.com"));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testReadCsvFromStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, "john,doe,john.doe@example.com");
        rewind($stream);

        $reader = new CsvReader();
        $data = iterator_to_array($reader->readStream($stream));
        fclose($stream);
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testWriteCsvToFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_test_' . time() . '.csv';
        $writer = new CsvWriter();
        $writer->bom = false;
        $result = $writer->writeFile([
            ["john", "doe", "john.doe@example.com"]
        ], $tempFile);

        self::assertTrue($result);
        self::assertTrue(is_file($tempFile));

        $contents = file_get_contents($tempFile);
        self::assertStringContainsString('john.doe@example.com', $contents);
        self::assertStringNotContainsString("\xef\xbb\xbf", $contents);

        unlink($tempFile);
    }

    public function testWriteCsvWithBom(): void
    {
        $writer = new CsvWriter();
        $output = $writer->writeString([
            ["john", "doe", "john.doe@example.com"]
        ]);

        self::assertStringStartsWith("\xef\xbb\xbf", $output);
    }

    public function testWriteCsvWithoutBom(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $output = $writer->writeString([
            ["john", "doe", "john.doe@example.com"]
        ]);

        self::assertStringNotContainsString("\xef\xbb\xbf", $output);
    }

    public function testWriteCsvToString(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $output = $writer->writeString([
            ["john", "doe", "john.doe@example.com"]
        ]);

        self::assertStringContainsString('john.doe@example.com', $output);
    }

    public function testCsvRoundTrip(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $original = [
            ["name", "email"],
            ["John Doe", "john@example.com"],
            ["Jane Doe", "jane@example.com"],
        ];

        $csv = $writer->writeString($original);

        $reader = new CsvReader();
        $readBack = iterator_to_array($reader->readString($csv));
        self::assertCount(3, $readBack);
        self::assertEquals($original[0], $readBack[0]);
        self::assertEquals($original[1], $readBack[1]);
    }

    public function testAutoDelimiterDetection(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readString("john;doe;john@example.com"));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testTabDelimiterDetection(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readString("john\tdoe\tjohn@example.com"));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testFormulaEscaping(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->escapeFormulas = true;
        $output = $writer->writeString([
            ["=SUM(A1:A10)", "+cmd", "-data", "@url", "\ttab", "\rreturn"],
        ]);

        self::assertStringContainsString("'=SUM(A1:A10)", $output);
        self::assertStringContainsString("'+cmd", $output);
        self::assertStringContainsString("'-data", $output);
        self::assertStringContainsString("'@url", $output);
    }

    public function testFormulaEscapingIsDefault(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        // escapeFormulas should be false by default to prevent data corruption!
        $output = $writer->writeString([
            ["=SUM(A1:A10)", "+cmd", "-data", "@url", "\ttab", "\rreturn"],
        ]);

        self::assertStringContainsString("=SUM(A1:A10)", $output);
        self::assertStringNotContainsString("'=SUM(A1:A10)", $output);
        self::assertStringContainsString("+cmd", $output);
        self::assertStringNotContainsString("'+cmd", $output);
    }

    public function testCustomSeparator(): void
    {
        $writer = new CsvWriter();
        $writer->separator = ';';
        $writer->bom = false;
        $output = $writer->writeString([
            ["john", "doe", "john@example.com"]
        ]);

        self::assertStringContainsString('john;doe;john@example.com', $output);
    }

    public function testWriteWithHeaders(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->headers = ['first', 'last', 'email'];
        $output = $writer->writeString([
            ["john", "doe", "john@example.com"],
        ]);

        self::assertStringStartsWith('first,last,email', $output);
    }

    public function testLimitReading(): void
    {
        $reader = new CsvReader();
        $reader->limit = 2;
        $data = iterator_to_array($reader->readString("a,b,c\n1,2,3\n4,5,6\n7,8,9"));
        self::assertCount(2, $data);
    }

    public function testLimitWithAssoc(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readString(
            "name,value,extra\n1,2,3\n4,5,6\n7,8,9",
            new Options(assoc: true, limit: 1)
        ));
        self::assertCount(1, $data);
        self::assertArrayHasKey('name', $data[0]);
    }

    public function testStrictModeThrowsOnInconsistentColumns(): void
    {
        $reader = new CsvReader();
        $reader->strict = true;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected 3');
        iterator_to_array($reader->readString("a,b,c\n1,2"));
    }

    public function testStrictModePassesOnConsistentData(): void
    {
        $reader = new CsvReader();
        $reader->strict = true;
        $data = iterator_to_array($reader->readString("a,b,c\n1,2,3\n4,5,6"));
        self::assertCount(3, $data);
    }

    public function testOutputEncodingOnWriter(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->outputEncoding = 'ISO-8859-1';
        $output = $writer->writeString([
            ["café", "résumé"],
        ]);

        // Output should be ISO-8859-1 encoded
        self::assertStringContainsString(mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8'), $output);
    }
}
