<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * Regression tests for the correction/hardening backlog: reader reuse, strict
 * mode parity, limit=0, duplicate headers, and a few ZIP/XML resource limits.
 */
class RobustnessTest extends TestCase
{
    private function tempFile(string $ext): string
    {
        return sys_get_temp_dir() . '/baresheet_robustness_' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    /**
     * @return string Path to a minimal .xlsx file whose xl/worksheets/sheet1.xml is $sheetXml.
     */
    private function writeMinimalXlsx(string $sheetXml): string
    {
        $path = $this->tempFile('xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
        return $path;
    }

    /**
     * @return string Path to a minimal .ods file whose content.xml is $contentXml.
     */
    private function writeMinimalOds(string $contentXml): string
    {
        $path = $this->tempFile('ods');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('content.xml', $contentXml);
        $zip->close();
        return $path;
    }

    // -- 1. CsvReader must not mutate its own separator/inputEncoding --

    public function testCsvReaderReuseDoesNotLeakDetectedSeparator(): void
    {
        $commaFile = $this->tempFile('csv');
        $semicolonFile = $this->tempFile('csv');
        file_put_contents($commaFile, "a,b\n1,2\n");
        file_put_contents($semicolonFile, "a;b\n3;4\n");

        $reader = new CsvReader();
        self::assertSame('auto', $reader->separator);

        $first = iterator_to_array($reader->readFile($commaFile));
        self::assertSame(['1', '2'], $first[1]);

        // If detection leaked into $this->separator, this would try to split on comma
        // and return a single-column row instead of two.
        $second = iterator_to_array($reader->readFile($semicolonFile));
        self::assertSame(['3', '4'], $second[1]);
        self::assertSame('auto', $reader->separator);

        unlink($commaFile);
        unlink($semicolonFile);
    }

    // -- 2. `strict` must behave identically across CSV/XLSX/ODS --

    public function testXlsxStrictWasPreviouslyANoOpNowThrows(): void
    {
        $sheetXml =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1"><v>1</v></c><c r="B1"><v>2</v></c></row>'
            . '<row r="2"><c r="A2"><v>3</v></c></row>'
            . '</sheetData></worksheet>';
        $file = $this->writeMinimalXlsx($sheetXml);

        $reader = new XlsxReader(new Options(strict: true));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Row 2 has 1 columns, expected 2');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testCsvStrictWithInjectedHeadersAndNonAssocCatchesShortFirstRow(): void
    {
        $file = $this->tempFile('csv');
        // First data row is short (2 cols) against 3 injected headers.
        file_put_contents($file, "1,John\n2,Jane,Doe\n");

        $reader = new CsvReader(new Options(
            strict: true,
            headers: ['id', 'first', 'last'],
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Row 1 has 2 columns, expected 3');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    // -- 3. limit=0 must yield zero rows in all three readers --

    public function testLimitZeroYieldsNoRowsCsv(): void
    {
        $file = $this->tempFile('csv');
        file_put_contents($file, "a,b\n1,2\n3,4\n");

        $reader = new CsvReader(new Options(limit: 0));
        $data = iterator_to_array($reader->readFile($file));
        self::assertSame([], $data);

        unlink($file);
    }

    public function testLimitZeroYieldsNoRowsXlsxAndOds(): void
    {
        $rows = [['a', 'b'], ['1', '2'], ['3', '4']];

        $xlsxFile = $this->tempFile('xlsx');
        Baresheet::write($rows, $xlsxFile);
        $xlsxReader = new XlsxReader(new Options(limit: 0));
        self::assertSame([], iterator_to_array($xlsxReader->readFile($xlsxFile)));
        unlink($xlsxFile);

        $odsFile = $this->tempFile('ods');
        Baresheet::write($rows, $odsFile);
        $odsReader = new OdsReader(new Options(limit: 0));
        self::assertSame([], iterator_to_array($odsReader->readFile($odsFile)));
        unlink($odsFile);
    }

    // -- 4. Duplicate headers must be rejected in assoc mode --

    public function testDuplicateHeadersRejectedCsv(): void
    {
        $file = $this->tempFile('csv');
        file_put_contents($file, "id,name,name\n1,John,Doe\n");

        $reader = new CsvReader(new Options(assoc: true));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate header(s) found: name');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testDuplicateHeadersRejectedXlsxAndOds(): void
    {
        $rows = [
            ['id', 'name', 'name'],
            ['1',  'John', 'Doe'],
        ];

        $xlsxFile = $this->tempFile('xlsx');
        Baresheet::write($rows, $xlsxFile);
        $xlsxReader = new XlsxReader(new Options(assoc: true));
        try {
            iterator_to_array($xlsxReader->readFile($xlsxFile));
            self::fail('Expected an exception for duplicate headers');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Duplicate header(s) found: name', $e->getMessage());
        } finally {
            unlink($xlsxFile);
        }

        $odsFile = $this->tempFile('ods');
        Baresheet::write($rows, $odsFile);
        $odsReader = new OdsReader(new Options(assoc: true));
        try {
            iterator_to_array($odsReader->readFile($odsFile));
            self::fail('Expected an exception for duplicate headers');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('Duplicate header(s) found: name', $e->getMessage());
        } finally {
            unlink($odsFile);
        }
    }

    public function testDuplicateInjectedHeadersRejected(): void
    {
        $file = $this->tempFile('csv');
        file_put_contents($file, "1,John,Doe\n");

        $reader = new CsvReader(new Options(assoc: true, headers: ['id', 'name', 'name']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate header(s) found: name');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    // -- 5. Bounding pathological expansions --

    public function testXlsxRejectsAbsurdColumnReference(): void
    {
        $sheetXml =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="ZZZZZZZZ1"/></row>'
            . '</sheetData></worksheet>';
        $file = $this->writeMinimalXlsx($sheetXml);

        $reader = new XlsxReader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/exceeds the maximum/');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testOdsRejectsAbsurdColumnsRepeated(): void
    {
        $contentXml =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            . '<office:body><office:spreadsheet><table:table table:name="Sheet1"><table:table-row>'
            . '<table:table-cell table:number-columns-repeated="999999999" office:value-type="string">'
            . '<text:p>bomb</text:p></table:table-cell>'
            . '</table:table-row></table:table></office:spreadsheet></office:body>'
            . '</office:document-content>';
        $file = $this->writeMinimalOds($contentXml);

        $reader = new OdsReader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/number-columns-repeated.*exceeds the maximum/');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }
}
