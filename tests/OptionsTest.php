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
            escapeFormulas: true,
            skipEmptyLines: true,
            offset: 5
        );

        $reader = new CsvReader();
        $opts->applyTo($reader);

        self::assertTrue($reader->assoc);
        self::assertTrue($reader->strict);
        self::assertEquals(10, $reader->limit);
        self::assertEquals(';', $reader->separator);
        self::assertEquals('\'', $reader->enclosure);
        self::assertEquals('\\', $reader->escape);
        self::assertTrue($reader->skipEmptyLines);
        self::assertEquals(5, $reader->offset);

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
        $expected = "'I''m a \"test\"'\r\n";
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

    public function testConstructorOptions(): void
    {
        $opts = new Options(
            assoc: true,
            separator: ';',
            boldHeaders: true
        );

        $reader = new CsvReader($opts);
        self::assertTrue($reader->assoc);
        self::assertEquals(';', $reader->separator);

        $writer = new XlsxWriter($opts);
        self::assertTrue($writer->boldHeaders);
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

    public function testApplyToGenericObject(): void
    {
        $opts = new Options(
            assoc: true,
            strict: true,
            stream: false,
            headers: ['a', 'b'],
            skipEmptyLines: false,
            offset: 10,
            limit: 100,
            separator: ';',
            enclosure: "'",
            escape: "\\",
            eol: "\n",
            inputEncoding: 'UTF-8',
            outputEncoding: 'UTF-16',
            bom: \LeKoala\Baresheet\Bom::Utf16Be,
            escapeFormulas: true,
            meta: ['title' => 'Test'],
            autofilter: 'A1:B1',
            freezePane: 'A2',
            sheet: 'MySheet',
            boldHeaders: true,
            tempPath: '/tmp',
            sharedStrings: true,
            autoWidth: true
        );

        $target = new class {
            public bool $assoc = false;
            public bool $strict = false;
            public bool $stream = true;
            public array $headers = [];
            public bool $skipEmptyLines = true;
            public int $offset = 0;
            public ?int $limit = null;
            public string $separator = "auto";
            public string $enclosure = "\"";
            public string $escape = "";
            public string $eol = "\r\n";
            public ?string $inputEncoding = null;
            public ?string $outputEncoding = null;
            public bool|\LeKoala\Baresheet\Bom|string $bom = true;
            public bool $escapeFormulas = false;
            public \LeKoala\Baresheet\Meta|array|null $meta = null;
            public ?string $autofilter = null;
            public ?string $freezePane = null;
            public string|int|null $sheet = null;
            public bool $boldHeaders = false;
            public ?string $tempPath = null;
            public bool $sharedStrings = false;
            public bool $autoWidth = false;
            public array $requiredColumns = [];
            public array $columns = [];
        };

        $opts->applyTo($target);

        foreach (get_object_vars($opts) as $k => $v) {
            self::assertEquals($v, $target->$k, "Property $k was not correctly copied");
        }

        $minimalTarget = new \stdClass();
        $opts->applyTo($minimalTarget);
        self::assertEmpty(get_object_vars($minimalTarget), "Properties should not be added to target if they don't exist");
    }
}
