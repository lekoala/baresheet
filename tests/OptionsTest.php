<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxWriter;
use PHPUnit\Framework\TestCase;

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
            offset: 5,
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
        $csv = $writer->writeString([['a'], ['b']]);

        self::assertStringContainsString("a\r\n", $csv);
    }

    public function testXlsxOptions(): void
    {
        $opts = new Options(
            autofilter: 'A1:C1',
            freezePane: 'A2',
            sheetProtection: 'password',
            boldHeaders: true,
            sharedStrings: true,
            autoWidth: true,
        );

        $writer = new XlsxWriter();
        $opts->applyTo($writer);

        self::assertEquals('A1:C1', $writer->autofilter);
        self::assertEquals('A2', $writer->freezePane);
        self::assertSame('password', $writer->sheetProtection);
        self::assertTrue($writer->boldHeaders);
        self::assertTrue($writer->sharedStrings);
        self::assertTrue($writer->autoWidth);
    }

    public function testConstructorOptions(): void
    {
        $opts = new Options(
            assoc: true,
            separator: ';',
            boldHeaders: true,
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
            autoWidth: true,
        );

        $target = new class {
            public bool $assoc = false;
            public bool $strict = false;
            public bool $stream = true;
            public array $headers = [];
            public bool $skipEmptyLines = true;
            public int $offset = 0;
            public ?int $limit = null;
            public string $separator = 'auto';
            public string $enclosure = '"';
            public string $escape = '';
            public string $eol = "\r\n";
            public ?string $inputEncoding = null;
            public ?string $outputEncoding = null;
            public bool $skipInputBOM = false;
            public bool $transcodeBomInput = false;
            public bool|\LeKoala\Baresheet\Bom|string $bom = true;
            public bool $escapeFormulas = false;
            public \LeKoala\Baresheet\Meta|array|null $meta = null;
            public ?string $autofilter = null;
            public ?string $freezePane = null;
            public bool|string $sheetProtection = false;
            public string|int|null $sheet = null;
            public bool $boldHeaders = false;
            public ?string $tempPath = null;
            public bool $sharedStrings = false;
            public bool $autoWidth = false;
            public array $columnWidths = [];
            public ?float $minColumnWidth = null;
            public ?float $maxColumnWidth = null;
            public ?string $printTitleRows = null;
            public array $requiredColumns = [];
            public array $columns = [];
            public array $aliases = [];
            public int $headerRows = 1;
            public int|string|null $headerOffset = null;
            public $headerNormalizer = null;
            public ?int $maxWorksheetSize = 500_000_000;
            public bool $stringifyValues = true;
            public bool $inferNumericStrings = true;
        };

        $opts->applyTo($target);

        foreach (get_object_vars($opts) as $k => $v) {
            self::assertEquals($v, $target->$k, "Property {$k} was not correctly copied");
        }

        $minimalTarget = new \stdClass();
        $opts->applyTo($minimalTarget);
        self::assertEmpty(
            get_object_vars($minimalTarget),
            "Properties should not be added to target if they don't exist",
        );
    }

    public function testInvalidOffsetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be >= 0');

        new Options(offset: -1);
    }

    public function testInvalidLimitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be >= 0');

        new Options(limit: -5);
    }

    public function testInvalidSeparatorThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Separator must be a single character');

        new Options(separator: ';;');
    }

    public function testInvalidEnclosureThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Enclosure must be a single character');

        new Options(enclosure: '""');
    }

    public function testInvalidEscapeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Escape must be a single character');

        new Options(escape: '\\\\');
    }

    public function testInvalidMaxWorksheetSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxWorksheetSize must be greater than 0 or null');

        new Options(maxWorksheetSize: 0);
    }

    public function testInvalidHeaderRowsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('headerRows must be >= 1');

        new Options(headerRows: 0);
    }

    public function testHeaderOffsetValidation(): void
    {
        // null, non-negative integers and 'auto' are accepted.
        new Options(headerOffset: null);
        new Options(headerOffset: 0);
        new Options(headerOffset: 3);
        new Options(headerOffset: 'auto');
        self::addToAssertionCount(1);

        foreach ([-1, 'bogus', '2'] as $bad) {
            try {
                new Options(headerOffset: $bad);
                self::fail('headerOffset ' . var_export($bad, true) . ' should throw');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }
    }

    public function testValidEdgeCases(): void
    {
        // These should not throw
        $opts = new Options(offset: 0, limit: null, separator: 'auto', enclosure: "'", escape: '');
        self::assertSame(0, $opts->offset);
        self::assertNull($opts->limit);

        $opts = new Options(offset: 100, limit: 0, separator: '|', enclosure: '"', escape: '\\');
        self::assertSame(100, $opts->offset);
        self::assertSame(0, $opts->limit);
    }

    public function testColumnWidthsValidation(): void
    {
        foreach ([['x' => -5], ['A' => 0], [0 => -2], ['2A' => 10]] as $bad) {
            try {
                new Options(columnWidths: $bad);
                self::fail('columnWidths ' . var_export($bad, true) . ' should throw');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $opts = new Options(columnWidths: ['A' => 20, 3 => 12.5]);
        self::assertSame(['A' => 20, 3 => 12.5], $opts->columnWidths);
    }

    public function testColumnWidthBoundsValidation(): void
    {
        foreach ([0.0, -1.5] as $bad) {
            try {
                new Options(minColumnWidth: $bad);
                self::fail('minColumnWidth ' . $bad . ' should throw');
            } catch (\InvalidArgumentException) {
                // expected
            }
            try {
                new Options(maxColumnWidth: $bad);
                self::fail('maxColumnWidth ' . $bad . ' should throw');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minColumnWidth must be <= maxColumnWidth');
        new Options(minColumnWidth: 50, maxColumnWidth: 10);
    }

    public function testPrintTitleRowsValidation(): void
    {
        foreach (['x', '1:', ':3', 'a:b', '3:1', ''] as $bad) {
            try {
                new Options(printTitleRows: $bad);
                self::fail('printTitleRows ' . var_export($bad, true) . ' should throw');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $opts = new Options(printTitleRows: '1:2');
        self::assertSame('1:2', $opts->printTitleRows);
        $opts = new Options(printTitleRows: '2');
        self::assertSame('2', $opts->printTitleRows);
    }

    public function testApplyToMockObjectSkippingMissingProperties(): void
    {
        $opts = new Options(
            assoc: true,
            strict: true,
            separator: ',',
        );

        // Create a mock object that only has a subset of properties
        // We use an anonymous class to provide specific partial properties
        $mock = new class {
            public bool $assoc = false;
        };

        // This should apply 'assoc' but skip 'strict', 'separator', etc., without throwing exceptions
        $opts->applyTo($mock);

        self::assertTrue($mock->assoc, "Existing property 'assoc' should be updated");
        self::assertFalse(property_exists($mock, 'strict'), "Missing property 'strict' should be skipped");
        self::assertFalse(property_exists($mock, 'separator'), "Missing property 'separator' should be skipped");
    }
}
