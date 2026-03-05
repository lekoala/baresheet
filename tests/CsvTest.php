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

    public function testReadBomCsv(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/bom.csv'));
        self::assertCount(1, $data);
        // The first element should not start with a BOM
        self::assertEquals('john', $data[0][0]);
    }

    public function testReadEmptyCsv(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/empty.csv'));
        self::assertCount(0, $data);
    }

    public function testReadSeparatorCsv(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/separator.csv'));
        self::assertNotEmpty($data);
    }

    public function testReadLargeCsv(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/large.csv'));
        self::assertGreaterThan(100, count($data));
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
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/auto.csv'));
        self::assertCount(101, $data);
        self::assertCount(4, $data[0]);
        // The first separator is ; (in "first;name") but the dominant one is ,
        self::assertEquals('1', $data[1][0]);
    }

    public function testAutoDelimiterDetectionSemicolon(): void
    {
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/auto2.csv'));
        self::assertCount(101, $data);
        self::assertCount(4, $data[0]);
        // The first separator is , (in "first,name") but the dominant one is ;
        self::assertEquals('1', $data[1][0]);
    }

    public function testAutoDelimiterDetectionPipe(): void
    {
        // 3 lines (1 header, 2 data).
        $reader = new CsvReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/auto3.csv'));
        self::assertCount(3, $data);
        // Header in data[0] has 4 columns.
        self::assertCount(4, $data[0]);
        self::assertEquals('john,doe', $data[1][1]);
        self::assertEquals('john;doe@example.com', $data[1][3]);
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

    public function testReadVariousBoms(): void
    {
        $boms = [
            \LeKoala\Baresheet\Bom::Utf8,
            \LeKoala\Baresheet\Bom::Utf16Le,
            \LeKoala\Baresheet\Bom::Utf32Be
        ];

        foreach ($boms as $bom) {
            // Simulate a file with a BOM and UTF-8 content
            $content = $bom->value;

            // If it's a UTF-16/32 BOM, encode the payload appropriately
            $payload = "name,email\njohn,john@example.com\n";
            if (!$bom->isUtf8()) {
                $payload = mb_convert_encoding($payload, $bom->encoding(), 'UTF-8');
            }
            $content .= $payload;

            $reader = new CsvReader();
            $reader->assoc = true;
            $data = iterator_to_array($reader->readString($content));

            self::assertEquals($bom, $reader->getInputBOM(), "Failed detecting {$bom->name}");
            self::assertCount(1, $data);
            self::assertEquals('john', $data[0]['name'], "Failed decoding data for {$bom->name}");
        }
    }

    public function testWriteVariousBoms(): void
    {
        $boms = [
            \LeKoala\Baresheet\Bom::Utf8,
            \LeKoala\Baresheet\Bom::Utf16Le
        ];

        foreach ($boms as $bom) {
            $writer = new CsvWriter();
            $writer->bom = $bom;
            $writer->headers = ['name', 'email'];
            $output = $writer->writeString([
                ["john", "john@example.com"],
            ]);

            // The output should start with the exact BOM sequence
            self::assertStringStartsWith($bom->value, $output, "Failed writing BOM {$bom->name}");

            // The rest of the output should be properly encoded in the target encoding
            $payload = "name,email\njohn,john@example.com\n";
            if (!$bom->isUtf8()) {
                $payload = mb_convert_encoding($payload, $bom->encoding(), 'UTF-8');
            }

            // Depending on the OS newlines, we can't do exact string equality for the whole payload,
            // but we can check if it contains the encoded "john" string.
            $encodedJohn = mb_convert_encoding("john", $bom->encoding() ?: 'UTF-8', 'UTF-8');
            self::assertStringContainsString($encodedJohn, substr($output, $bom->length()));
        }
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

    public function testSkipEmptyLinesByDefault(): void
    {
        $reader = new \LeKoala\Baresheet\CsvReader();
        // SkipEmptyLines is true by default now
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/empty_lines.csv'));
        self::assertCount(4, $data);
        self::assertEquals('bob', $data[3][1]);
    }

    public function testSkipEmptyLinesFalse(): void
    {
        $reader = new \LeKoala\Baresheet\CsvReader();
        $reader->skipEmptyLines = false;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/empty_lines.csv'));
        // 1 header + 3 data rows + 2 empty lines = 6 rows in file
        self::assertCount(6, $data);
        self::assertNull($data[2][0]); // Row 3 is empty -> [null]
    }

    public function testOffsetAndLimit(): void
    {
        $reader = new \LeKoala\Baresheet\CsvReader();
        $reader->offset = 1; // Skip header
        $reader->limit = 1;  // Only one row
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/headers.csv'));
        self::assertCount(1, $data);
        self::assertEquals('john', $data[0][0]);
    }

    public function testOffsetAndAssocAndSkipEmptyLines(): void
    {
        $reader = new \LeKoala\Baresheet\CsvReader();
        $reader->assoc = true;
        $reader->skipEmptyLines = true;
        $reader->offset = 1; // Skip john, second data row is jane
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/empty_lines.csv'));

        self::assertCount(2, $data);
        self::assertEquals('jane', $data[0]['name']);
        self::assertEquals('bob', $data[1]['name']);
    }
}
