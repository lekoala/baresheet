<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\XlsxWriter;

class OptionsTest extends TestCase
{
    public function testApplyTo(): void
    {
        $opts = new Options(
            assoc: true,
            strict: true,
            limit: 10,
            separator: ';',
            enclosure: '\'',
            escape: '\\',
            eol: "\r\n",
            bom: false,
            escapeFormulas: true
        );

        $reader = new CsvReader();
        $opts->applyTo($reader);

        self::assertTrue($reader->assoc);
        self::assertTrue($reader->strict);
        self::assertEquals(10, $reader->limit);
        self::assertEquals(';', $reader->separator);
        self::assertEquals('\'', $reader->enclosure);
        self::assertEquals('\\', $reader->escape);

        $writer = new CsvWriter();
        $opts->applyTo($writer);

        self::assertEquals(';', $writer->separator);
        self::assertEquals('\'', $writer->enclosure);
        self::assertEquals('\\', $writer->escape);
        self::assertEquals("\r\n", $writer->eol);
        self::assertFalse($writer->bom);
        self::assertTrue($writer->escapeFormulas);
    }

    public function testCsvEnclosureAndEscape(): void
    {
        $writer = new CsvWriter();
        $writer->enclosure = '\'';
        $writer->escape = '\\';
        $writer->bom = false;

        $data = [["I'm a \"test\""]];
        $csv = $writer->writeString($data);

        // PHP's fputcsv uses RFC-4180 enclosure doubling ('' instead of \'), regardless of escape char.
        // Expected: 'I''m a "test"'
        $expected = "'I''m a \"test\"'\n";
        self::assertEquals($expected, $csv);

        $reader = new CsvReader();
        $reader->enclosure = '\'';
        $reader->escape = '\\';
        $readBack = iterator_to_array($reader->readString($csv));

        self::assertEquals($data[0][0], $readBack[0][0]);
    }

    public function testCsvEol(): void
    {
        $writer = new CsvWriter();
        $writer->eol = "\r\n";
        $writer->bom = false;
        $csv = $writer->writeString([["a"], ["b"]]);

        self::assertStringContainsString("a\r\n", $csv);
    }

    public function testXlsxOptions(): void
    {
        $opts = new Options(
            autofilter: 'A1:C1',
            freezePane: 'A2',
            boldHeaders: true,
            sharedStrings: true,
            autoWidth: true
        );

        $writer = new XlsxWriter();
        $opts->applyTo($writer);

        self::assertEquals('A1:C1', $writer->autofilter);
        self::assertEquals('A2', $writer->freezePane);
        self::assertTrue($writer->boldHeaders);
        self::assertTrue($writer->sharedStrings);
        self::assertTrue($writer->autoWidth);
    }

    public function testTempPathOption(): void
    {
        $customTemp = sys_get_temp_dir() . '/baresheet_custom_temp';
        if (!is_dir($customTemp)) {
            mkdir($customTemp);
        }

        $opts = new Options(tempPath: $customTemp);
        $writer = new XlsxWriter();
        $opts->applyTo($writer);

        self::assertEquals($customTemp, $writer->tempPath);

        // Verification of actual usage of tempPath is complex without mocking sys_get_temp_dir
        // but we at least verify the property is correctly passed.

        rmdir($customTemp);
    }
}
