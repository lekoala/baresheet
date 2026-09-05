<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\Exception\WriteException;
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
        $isoCsv = iconv('UTF-8', 'ISO-8859-1', 'é,à');
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

    public function testOutputEncodingTranscodesWholeStream(): void
    {
        // Without a BOM, the whole stream (separators and EOL included) must be
        // transcoded, not just the cell content (61002c62000d0a was the bug).
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->outputEncoding = 'UTF-16LE';
        $output = $writer->writeString([['a', 'b']]);

        self::assertTrue(mb_check_encoding($output, 'UTF-16LE'));
        self::assertSame('61002c0062000d000a00', bin2hex($output));
    }

    public function testOutputEncodingFilterIsRemovedBetweenWriteToStreamCalls(): void
    {
        $stream = fopen('php://temp', 'r+');
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->eol = "\n";
        $writer->outputEncoding = 'UTF-16LE';

        $writer->writeToStream([['a', 'b']], $stream);
        $writer->writeToStream([['c', 'd']], $stream);
        // A raw write after writeToStream must not be transcoded: the filter must
        // not leak onto the caller-owned stream.
        fwrite($stream, "RAW\n");
        rewind($stream);
        $contents = (string) stream_get_contents($stream);
        fclose($stream);

        $payload = substr($contents, 0, -4);
        self::assertSame("RAW\n", substr($contents, -4));
        self::assertTrue(mb_check_encoding($payload, 'UTF-16LE'));
        self::assertSame("a,b\nc,d\n", mb_convert_encoding($payload, 'UTF-8', 'UTF-16LE'));
    }

    public function testOutputEncodingUnrepresentableCharacterThrows(): void
    {
        // "€" (U+20AC) cannot be represented in ISO-8859-1; an explicit error is
        // required instead of a silently substituted/truncated file.
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->outputEncoding = 'ISO-8859-1';

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('cannot be represented in ISO-8859-1');
        $writer->writeString([["a\xE2\x82\xAC"]]);
    }

    public function testOutputEncodingInvalidUtf8Throws(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->outputEncoding = 'ISO-8859-1';

        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Invalid UTF-8 in CSV cell');
        $writer->writeString([["a\xC3b"]]);
    }

    public function testOutputEncodingUnknownEncodingThrows(): void
    {
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->outputEncoding = 'NOT-A-REAL-ENCODING';

        $this->expectException(\InvalidArgumentException::class);
        $writer->writeString([['a']]);
    }

    public function testOutputEncodingConvertsUtf8Input(): void
    {
        // Input detected as UTF-8 (default inputEncoding) must still be converted
        // to the requested outputEncoding — previously the bytes stayed UTF-8.
        $reader = new CsvReader();
        $reader->outputEncoding = 'Windows-1252';
        $rows = iterator_to_array($reader->readString("caf\xC3\xA9\n"));
        self::assertSame("caf\xE9", $rows[0][0]);
    }

    public function testOutputEncodingAppliesAfterUtf16BomTranscode(): void
    {
        $reader = new CsvReader();
        $reader->outputEncoding = 'Windows-1252';
        $utf16 = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', "caf\xC3\xA9\n");
        $rows = iterator_to_array($reader->readString($utf16));
        self::assertSame("caf\xE9", $rows[0][0]);
    }

    public function testOutputEncodingAppliesAfterUtf8Bom(): void
    {
        $reader = new CsvReader();
        $reader->outputEncoding = 'Windows-1252';
        $rows = iterator_to_array($reader->readString("\xEF\xBB\xBFcaf\xC3\xA9\n"));
        self::assertSame("caf\xE9", $rows[0][0]);
    }
}
