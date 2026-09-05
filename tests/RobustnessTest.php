<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\InvalidRowException;
use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Meta;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;
use ZipArchive;

/**
 * Regression tests for the correction/hardening backlog: reader reuse, strict
 * mode parity, limit=0, duplicate headers, and a few ZIP/XML resource limits.
 */
class RobustnessTest extends TestCase
{
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

    private function odsRowXml(string $cellsXml): string
    {
        return (
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
            . '<office:body><office:spreadsheet><table:table table:name="Sheet1">'
            . $cellsXml
            . '</table:table></office:spreadsheet></office:body>'
            . '</office:document-content>'
        );
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

        $this->expectException(InvalidRowException::class);
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

        $this->expectException(InvalidRowException::class);
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

    // -- 4. Duplicate headers must be rejected regardless of assoc --

    public function testDuplicateHeadersRejectedCsv(): void
    {
        $file = $this->tempFile('csv');
        file_put_contents($file, "id,name,name\n1,John,Doe\n");

        $reader = new CsvReader(new Options(assoc: true));

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('Duplicate header');

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
        } catch (InvalidDocumentException $e) {
            self::assertStringContainsString('Duplicate header', $e->getMessage());
        } finally {
            unlink($xlsxFile);
        }

        $odsFile = $this->tempFile('ods');
        Baresheet::write($rows, $odsFile);
        $odsReader = new OdsReader(new Options(assoc: true));
        try {
            iterator_to_array($odsReader->readFile($odsFile));
            self::fail('Expected an exception for duplicate headers');
        } catch (InvalidDocumentException $e) {
            self::assertStringContainsString('Duplicate header', $e->getMessage());
        } finally {
            unlink($odsFile);
        }
    }

    public function testDuplicateInjectedHeadersRejected(): void
    {
        $file = $this->tempFile('csv');
        file_put_contents($file, "1,John,Doe\n");

        $reader = new CsvReader(new Options(assoc: true, headers: ['id', 'name', 'name']));

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('Duplicate header');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testDuplicateInjectedHeadersRejectedEvenWithoutAssoc(): void
    {
        // headers+columns is non-assoc but still resolves 'name' by index into the
        // duplicated header — just as ambiguous as the assoc=true case.
        $file = $this->tempFile('csv');
        file_put_contents($file, "1,John,Doe\n");

        $reader = new CsvReader(new Options(
            assoc: false,
            headers: ['id', 'name', 'name'],
            columns: ['name'],
        ));

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('Duplicate header');

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

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessageMatches('/exceeds the maximum/');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testOdsRejectsAbsurdColumnsRepeated(): void
    {
        $file = $this->writeMinimalOds($this->odsRowXml(
            '<table:table-row>'
            . '<table:table-cell table:number-columns-repeated="999999999" office:value-type="string">'
            . '<text:p>bomb</text:p></table:table-cell>'
            . '</table:table-row>',
        ));

        $reader = new OdsReader();

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessageMatches('/exceeds the maximum number of columns/');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    public function testOdsRejectsAbsurdRowsRepeated(): void
    {
        $file = $this->writeMinimalOds($this->odsRowXml(
            '<table:table-row table:number-rows-repeated="999999999">'
            . '<table:table-cell office:value-type="string"><text:p>bomb</text:p></table:table-cell>'
            . '</table:table-row>',
        ));

        $reader = new OdsReader();

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessageMatches('/number-rows-repeated.*exceeds the maximum/');

        try {
            iterator_to_array($reader->readFile($file));
        } finally {
            unlink($file);
        }
    }

    // -- 6. number-rows-repeated must emit the row N times, not just once --

    public function testOdsNumberRowsRepeatedEmitsRowMultipleTimes(): void
    {
        $file = $this->writeMinimalOds($this->odsRowXml(
            '<table:table-row table:number-rows-repeated="3">'
            . '<table:table-cell office:value-type="string"><text:p>Hello</text:p></table:table-cell>'
            . '</table:table-row>'
            . '<table:table-row>'
            . '<table:table-cell office:value-type="string"><text:p>World</text:p></table:table-cell>'
            . '</table:table-row>',
        ));

        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile($file));

        self::assertSame(
            [
                ['Hello'],
                ['Hello'],
                ['Hello'],
                ['World'],
            ],
            $data,
        );

        unlink($file);
    }

    // -- 7. Generator abandonment: break, exception and unset must release resources --

    private function writeCsvForCleanup(): string
    {
        $file = $this->tempFile('csv');
        file_put_contents($file, "a,b,c\n1,2,3\n4,5,6\n7,8,9\n");
        return $file;
    }

    private function writeXlsxForCleanup(): string
    {
        $file = $this->tempFile('xlsx');
        Baresheet::write([
            ['a', 'b', 'c'],
            ['1', '2', '3'],
            ['4', '5', '6'],
            ['7', '8', '9'],
        ], $file);
        return $file;
    }

    private function writeOdsForCleanup(): string
    {
        $file = $this->tempFile('ods');
        Baresheet::write([
            ['a', 'b', 'c'],
            ['1', '2', '3'],
            ['4', '5', '6'],
            ['7', '8', '9'],
        ], $file);
        return $file;
    }

    public function testCsvReaderReleasesResourcesWhenGeneratorIsAbandoned(): void
    {
        $file = $this->writeCsvForCleanup();
        $reader = new CsvReader();
        $gen = $reader->readFile($file);
        $gen->rewind();

        self::assertTrue($gen->valid());
        self::assertSame(['a', 'b', 'c'], $gen->current());

        unset($gen);

        unlink($file);
        self::assertFileDoesNotExist($file);
    }

    public function testXlsxReaderReleasesResourcesWhenGeneratorIsAbandoned(): void
    {
        $file = $this->writeXlsxForCleanup();
        $reader = new XlsxReader();
        $gen = $reader->readFile($file);
        $gen->rewind();

        self::assertTrue($gen->valid());
        self::assertSame(['a', 'b', 'c'], $gen->current());

        unset($gen);

        unlink($file);
        self::assertFileDoesNotExist($file);
    }

    public function testOdsReaderReleasesResourcesWhenGeneratorIsAbandoned(): void
    {
        $file = $this->writeOdsForCleanup();
        $reader = new OdsReader();
        $gen = $reader->readFile($file);
        $gen->rewind();

        self::assertTrue($gen->valid());
        self::assertSame(['a', 'b', 'c'], $gen->current());

        unset($gen);

        unlink($file);
        self::assertFileDoesNotExist($file);
    }

    public function testExceptionReleasesFileCsv(): void
    {
        $file = $this->writeCsvForCleanup();
        $reader = new CsvReader();
        try {
            foreach ($reader->readFile($file) as $row) {
                throw new \RuntimeException('Simulated failure');
            }
        } catch (\RuntimeException) {
        }
        unlink($file);
        self::assertFileDoesNotExist($file);
    }

    public function testExceptionReleasesFileXlsx(): void
    {
        $file = $this->writeXlsxForCleanup();
        $reader = new XlsxReader();
        try {
            foreach ($reader->readFile($file) as $row) {
                throw new \RuntimeException('Simulated failure');
            }
        } catch (\RuntimeException) {
        }
        unlink($file);
        self::assertFileDoesNotExist($file);
    }

    public function testExceptionReleasesFileOds(): void
    {
        $file = $this->writeOdsForCleanup();
        $reader = new OdsReader();
        try {
            foreach ($reader->readFile($file) as $row) {
                throw new \RuntimeException('Simulated failure');
            }
        } catch (\RuntimeException) {
        }
        unlink($file);
        self::assertFileDoesNotExist($file);
    }

    // -- 8. Stream ownership: writer methods never close caller-owned streams --

    public function testWriteToStreamDoesNotCloseCallerStream(): void
    {
        $stream = fopen('php://temp', 'r+');
        self::assertIsResource($stream);

        $writer = new CsvWriter();
        $writer->writeToStream([['a', 'b'], ['1', '2']], $stream);

        self::assertIsResource($stream);
        rewind($stream);
        $contents = stream_get_contents($stream);
        self::assertStringContainsString('a,b', $contents);
        fclose($stream);
    }

    public function testReadStreamDoesNotCloseCallerStream(): void
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "a,b\n1,2\n");
        rewind($stream);

        $reader = new CsvReader();
        $rows = iterator_to_array($reader->readStream($stream));
        self::assertSame([['a', 'b'], ['1', '2']], $rows);

        self::assertIsResource($stream);
        fclose($stream);
    }

    public function testWriteStreamReturnsPositionedStream(): void
    {
        $writer = new CsvWriter();
        $stream = $writer->writeStream([['a', 'b'], ['1', '2']]);

        $meta = stream_get_meta_data($stream);
        self::assertSame(0, $meta['seekable'] ? ftell($stream) : 0);

        $contents = stream_get_contents($stream);
        self::assertStringContainsString('a,b', $contents);

        fclose($stream);
    }

    // -- 9. XML-invalid cell content must be rejected with position, not silently corrupted --

    public function testXlsxWriterRejectsInvalidUtf8CellWithPosition(): void
    {
        $writer = new XlsxWriter();
        try {
            $writer->writeString([["caf\xE9"]]);
            self::fail('Expected WriteException');
        } catch (WriteException $e) {
            self::assertStringContainsString("sheet 'Sheet1', cell A1", $e->getMessage());
            self::assertStringContainsString('Invalid UTF-8', $e->getMessage());
        }
    }

    public function testXlsxWriterRejectsForbiddenCodePointCellWithPosition(): void
    {
        $writer = new XlsxWriter();
        try {
            $writer->writeString([["a\xEF\xBF\xBE b"]]);
            self::fail('Expected WriteException');
        } catch (WriteException $e) {
            self::assertStringContainsString("sheet 'Sheet1', cell A1", $e->getMessage());
            self::assertStringContainsString('U+FFFE or U+FFFF', $e->getMessage());
        }
    }

    public function testOdsWriterRejectsInvalidUtf8CellWithPosition(): void
    {
        $writer = new OdsWriter();
        try {
            $writer->writeString([["caf\xE9"]]);
            self::fail('Expected WriteException');
        } catch (WriteException $e) {
            self::assertStringContainsString("sheet 'Sheet1', cell A1", $e->getMessage());
            self::assertStringContainsString('Invalid UTF-8', $e->getMessage());
        }
    }

    public function testOdsWriterRejectsForbiddenCodePointCellWithPosition(): void
    {
        $writer = new OdsWriter();
        try {
            $writer->writeString([["a\xEF\xBF\xBF b"]]);
            self::fail('Expected WriteException');
        } catch (WriteException $e) {
            self::assertStringContainsString("sheet 'Sheet1', cell A1", $e->getMessage());
            self::assertStringContainsString('U+FFFE or U+FFFF', $e->getMessage());
        }
    }

    public function testXlsxMetadataFieldRejectsInvalidUtf8(): void
    {
        $writer = new XlsxWriter();
        $writer->meta = new Meta(title: "caf\xE9");
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('metadata title');
        $writer->writeString([['a']]);
    }

    public function testOdsMetadataFieldRejectsInvalidUtf8(): void
    {
        $writer = new OdsWriter();
        $writer->meta = new Meta(creator: "caf\xE9");
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('metadata creator');
        $writer->writeString([['a']]);
    }

    public function testWritersRoundTripValidUnicode(): void
    {
        $rows = [['café 😊', 'Zoë']];

        $xlsx = $this->tempFile('xlsx');
        Baresheet::write($rows, $xlsx);
        self::assertSame($rows, iterator_to_array((new XlsxReader())->readFile($xlsx)));
        unlink($xlsx);

        $ods = $this->tempFile('ods');
        Baresheet::write($rows, $ods);
        self::assertSame($rows, iterator_to_array((new OdsReader())->readFile($ods)));
        unlink($ods);
    }

    public function testFailedXlsxWriteCleansUpInternalTempButNotDestination(): void
    {
        $tempDir = $this->tempFile('dir');
        mkdir($tempDir);
        $destination = $tempDir . '/out.xlsx';

        $writer = new XlsxWriter();
        $writer->tempPath = $tempDir;
        try {
            $writer->writeFile([["caf\xE9"]], $destination);
            self::fail('Expected WriteException');
        } catch (WriteException) {
        }

        $leftovers = glob($tempDir . '/xlsx_native*') ?? [];
        self::assertSame([], $leftovers);
        self::assertFileDoesNotExist($destination);
        rmdir($tempDir);
    }

    // -- 10. XLSX writer must refuse dimensions its own reader cannot round-trip --

    public function testXlsxWriterRejectsTooManyColumns(): void
    {
        $writer = new XlsxWriter();
        try {
            $writer->writeString([array_fill(0, 16_385, 'x')]);
            self::fail('Expected WriteException for too many columns');
        } catch (WriteException $e) {
            self::assertStringContainsString('exceeds the maximum of 16384 columns', $e->getMessage());
        }
    }

    public function testXlsxWriterAcceptsMaxColumns(): void
    {
        $writer = new XlsxWriter();
        $bytes = $writer->writeString([array_fill(0, 16_384, 'x')]);
        self::assertNotSame('', $bytes);
    }

    public function testXlsxWriterRejectsTooLongCell(): void
    {
        $writer = new XlsxWriter();
        try {
            $writer->writeString([[str_repeat('x', 32_768)]]);
            self::fail('Expected WriteException for an over-long cell');
        } catch (WriteException $e) {
            self::assertStringContainsString("Cell A1", $e->getMessage());
            self::assertStringContainsString('exceeds the maximum of 32767 characters', $e->getMessage());
        }
    }

    public function testXlsxWriterAcceptsMaxCellLength(): void
    {
        $writer = new XlsxWriter();
        $bytes = $writer->writeString([[str_repeat('x', 32_767)]]);
        self::assertNotSame('', $bytes);
    }

    public function testXlsxWriterRejectsTooManyRows(): void
    {
        $writer = new XlsxWriter();
        $rows = (static function () {
            for ($i = 0; $i <= 1_048_576; $i++) {
                yield [null];
            }
        })();
        try {
            $writer->writeString($rows);
            self::fail('Expected WriteException for too many rows');
        } catch (WriteException $e) {
            self::assertStringContainsString('exceeds the maximum of 1048576 rows', $e->getMessage());
        }
    }

    // -- 11. Inline strings must skip phonetic runs and keep rich-text runs --

    public function testXlsxInlineStringSkipsPhoneticRuns(): void
    {
        $sheetXml =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>original</t><rPh sb="0" eb="6"><t>PHONETIC</t></rPh>'
            . '<phoneticPr fontId="1"/></is></c></row>'
            . '</sheetData></worksheet>';
        $file = $this->writeMinimalXlsx($sheetXml);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame([['original']], $data);
    }

    public function testXlsxInlineStringRichTextRuns(): void
    {
        $sheetXml =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><r><t>Hello</t></r><r><t xml:space="preserve"> World</t></r></is></c></row>'
            . '</sheetData></worksheet>';
        $file = $this->writeMinimalXlsx($sheetXml);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame([['Hello World']], $data);
    }
}
