<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Spread;
use LeKoala\Baresheet\XlsxWriter;

class SpreadTest extends TestCase
{
    public function testGetPropertiesXlsx(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(
            title: 'XlsxTitle',
            subject: 'XlsxSubject',
            creator: 'XlsxCreator',
            keywords: 'xlsx,test',
            description: 'XlsxDescription',
            category: 'XlsxCategory',
            language: 'en-GB',
        );
        $writer->writeFile([['data']], $tempFile);

        $props = Spread::getProperties($tempFile);
        self::assertEquals('xlsx', $props['format']);
        self::assertEquals('XlsxTitle', $props['meta']['title'] ?? null);
        self::assertEquals('XlsxSubject', $props['meta']['subject'] ?? null);
        self::assertEquals('XlsxCreator', $props['meta']['creator'] ?? null);
        self::assertEquals('xlsx,test', $props['meta']['keywords'] ?? null);
        self::assertEquals('XlsxDescription', $props['meta']['description'] ?? null);
        self::assertEquals('XlsxCategory', $props['meta']['category'] ?? null);
        self::assertEquals('en-GB', $props['meta']['language'] ?? null);
        self::assertContains('Sheet1', $props['sheets']);

        unlink($tempFile);
    }

    public function testGetPropertiesOds(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(
            title: 'OdsTitle',
            subject: 'OdsSubject',
            creator: 'OdsCreator',
            keywords: 'ods, test',
            description: 'OdsDescription',
            language: 'fr-FR',
        );
        $writer->sheet = 'MySheet';
        $writer->writeFile([['data']], $tempFile);

        $props = Spread::getProperties($tempFile);
        self::assertEquals('ods', $props['format']);
        self::assertEquals('OdsTitle', $props['meta']['title'] ?? null);
        self::assertEquals('OdsSubject', $props['meta']['subject'] ?? null);
        self::assertEquals('OdsCreator', $props['meta']['creator'] ?? null);
        self::assertEquals('ods, test', $props['meta']['keywords'] ?? null);
        self::assertEquals('OdsDescription', $props['meta']['description'] ?? null);
        self::assertEquals('fr-FR', $props['meta']['language'] ?? null);
        self::assertArrayNotHasKey('category', $props['meta']);
        self::assertContains('MySheet', $props['sheets']);

        unlink($tempFile);
    }

    public function testGetSheetNamesXlsx(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->sheet = 'TestSheet';
        $writer->writeFile([['data']], $tempFile);

        $names = Spread::getSheetNames($tempFile);
        self::assertEquals(['TestSheet'], $names);

        unlink($tempFile);
    }

    public function testGetSheetNamesOds(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->sheet = 'TestSheet';
        $writer->writeFile([['data']], $tempFile);

        $names = Spread::getSheetNames($tempFile);
        self::assertEquals(['TestSheet'], $names);

        unlink($tempFile);
    }

    public function testZipError(): void
    {
        self::assertEquals('File already exists.', Spread::zipError(\ZipArchive::ER_EXISTS));
        self::assertEquals('Zip archive inconsistent.', Spread::zipError(\ZipArchive::ER_INCONS));
        self::assertEquals('Invalid argument.', Spread::zipError(\ZipArchive::ER_INVAL));
        self::assertEquals('Malloc failure.', Spread::zipError(\ZipArchive::ER_MEMORY));
        self::assertEquals('No such file.', Spread::zipError(\ZipArchive::ER_NOENT));
        self::assertEquals('Not a zip archive.', Spread::zipError(\ZipArchive::ER_NOZIP));
        self::assertEquals("Can't open file.", Spread::zipError(\ZipArchive::ER_OPEN));
        self::assertEquals('Read error.', Spread::zipError(\ZipArchive::ER_READ));
        self::assertEquals('Seek error.', Spread::zipError(\ZipArchive::ER_SEEK));
        self::assertEquals('Unknown error code 999.', Spread::zipError(999));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('isNumericCellValueProvider')]
    public function testIsNumericCellValue(mixed $value, bool $expected): void
    {
        self::assertSame($expected, Spread::isNumericCellValue($value));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function isNumericCellValueProvider(): array
    {
        return [
            'int' => [42, true],
            'zero int' => [0, true],
            'float' => [3.14, true],
            'negative float' => [-0.5, true],
            'string int' => ['42', true],
            'string zero' => ['0', true],
            'string decimal' => ['3.14', true],
            'string negative decimal' => ['-0.5', true],
            '15 digits' => ['123456789012345', true],
            '16 digits' => ['1234567890123456', false],
            '15 significant digits with leading zero' => ['0.123456789012345', true],
            '16 significant digits with leading zero' => ['0.1234567890123456', false],
            'small decimal with many zeros' => ['0.000000000000001', true],
            'zero with trailing precision' => ['0.000', true],
            'leading zero' => ['007', false],
            'leading zero decimal' => ['007.5', false],
            'negative leading zero' => ['-007', false],
            'leading plus' => ['+42', false],
            'scientific notation' => ['1e3', false],
            '20 digits' => ['12345678901234567890', false],
            'empty string' => ['', false],
            'non numeric string' => ['abc', false],
            'bool true' => [true, false],
            'bool false' => [false, false],
            'null' => [null, false],
        ];
    }

    public function testDateToExcel(): void
    {
        // 1899-12-30 is base 0
        $dtBase = new \DateTime('1899-12-30 00:00:00');
        self::assertEquals(0.0, Spread::dateToExcel($dtBase));

        // 1900-01-01 is 1 in Excel
        $dt1900 = new \DateTime('1900-01-01 00:00:00');
        self::assertEquals(1.0, Spread::dateToExcel($dt1900));

        // 1900-02-28 is 59 in Excel (before leap bug)
        $dtFeb28 = new \DateTime('1900-02-28 00:00:00');
        self::assertEquals(59.0, Spread::dateToExcel($dtFeb28));

        // 1900-03-01 is 61 (after leap bug)
        $dtMar01 = new \DateTime('1900-03-01 00:00:00');
        self::assertEquals(61.0, Spread::dateToExcel($dtMar01));

        // Modern date
        $dtModern = new \DateTime('2023-10-15 12:00:00');
        self::assertEquals(45_214.5, Spread::dateToExcel($dtModern));

        // Quarter day
        $dtQuarter = new \DateTime('2024-01-01 06:00:00');
        self::assertEquals(45_292.25, Spread::dateToExcel($dtQuarter));
    }

    public function testExcelDateToStringCache(): void
    {
        $date1 = Spread::excelDateToString(45_214.5);
        $date2 = Spread::excelDateToString(45_214.5);
        self::assertSame($date1, $date2);
        self::assertSame('2023-10-15 12:00:00', $date1);
    }

    public function testExcelDateToStringCacheKeyVariations(): void
    {
        $d1 = Spread::excelDateToString(45_214.5, null, false);
        $d2 = Spread::excelDateToString(45_214.5, null, true);
        // 1904 vs 1900 date system should produce different results
        self::assertNotSame($d1, $d2);

        $d3 = Spread::excelDateToString(45_214.5, 'Y-m-d', false);
        $d4 = Spread::excelDateToString(45_214.5, 'H:i:s', false);
        self::assertNotSame($d3, $d4);
    }

    public function testExcelDateToStringMaxCacheSize(): void
    {
        // Fill cache beyond limit to ensure it resets without crashing
        for ($i = 0; $i < 10_005; $i++) {
            Spread::excelDateToString((float) $i);
        }
        // If we get here without memory exhaustion, the cache reset works
        self::assertTrue(true);
    }

    public function testEnsureExtension(): void
    {
        self::assertEquals('test.csv', Spread::ensureExtension('test', 'csv'));
        self::assertEquals('test.csv', Spread::ensureExtension('test.csv', 'csv'));
        self::assertEquals('test.CSV', Spread::ensureExtension('test.CSV', 'csv'));
        self::assertEquals('test.csv', Spread::ensureExtension('test.csv', 'CSV'));
        self::assertEquals('test.xlsx.csv', Spread::ensureExtension('test.xlsx', 'csv'));
        self::assertEquals('path/to/test.ods', Spread::ensureExtension('path/to/test', 'ods'));
    }

    public function testColumnLetter(): void
    {
        self::assertEquals('A', Spread::columnLetter(1));
        self::assertEquals('Z', Spread::columnLetter(26));
        self::assertEquals('AA', Spread::columnLetter(27));
        self::assertEquals('AZ', Spread::columnLetter(52));
        self::assertEquals('BA', Spread::columnLetter(53));
        self::assertEquals('ZZ', Spread::columnLetter(702));
        self::assertEquals('AAA', Spread::columnLetter(703));
        self::assertEquals('XFD', Spread::columnLetter(16_384));
    }

    public function testCellAddress(): void
    {
        // Relative addresses
        self::assertEquals('A1', Spread::cellAddress(0, 0));
        self::assertEquals('B2', Spread::cellAddress(1, 1));
        self::assertEquals('Z99', Spread::cellAddress(98, 25));
        self::assertEquals('AA100', Spread::cellAddress(99, 26));

        // Absolute addresses
        self::assertEquals('$A$1', Spread::cellAddress(0, 0, true));
        self::assertEquals('$Z$99', Spread::cellAddress(98, 25, true));
        self::assertEquals('$AA$100', Spread::cellAddress(99, 26, true));
    }

    public function testColumnIndex(): void
    {
        self::assertEquals(1, Spread::columnIndex('A'));
        self::assertEquals(26, Spread::columnIndex('Z'));
        self::assertEquals(27, Spread::columnIndex('AA'));
        self::assertEquals(52, Spread::columnIndex('AZ'));
        self::assertEquals(53, Spread::columnIndex('BA'));
        self::assertEquals(702, Spread::columnIndex('ZZ'));
        self::assertEquals(703, Spread::columnIndex('AAA'));
        self::assertEquals(16_384, Spread::columnIndex('XFD'));
        // Test case sensitivity
        self::assertEquals(1, Spread::columnIndex('a'));
    }

    public function testColumnIndexAndLetterConsistency(): void
    {
        for ($i = 1; $i <= 2000; $i++) {
            $letter = Spread::columnLetter($i);
            $index = Spread::columnIndex($letter);
            self::assertEquals($i, $index, "Failed for index {$i} (Letter: {$letter})");
        }
    }

    public function testGetSheetNamesInvalidZip(): void
    {
        $invalidFile = __DIR__ . '/data/auto.csv';
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to open zip archive/');
        Spread::getSheetNames($invalidFile);
    }

    public function testGetSheetNamesNonExistentFile(): void
    {
        $nonExistentFile = __DIR__ . '/data/non_existent.xlsx';
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to open zip archive/');
        Spread::getSheetNames($nonExistentFile);
    }

    public function testEscapeXmlEmptyString(): void
    {
        self::assertSame('', Spread::escapeXml(''));
    }

    public function testEscapeXmlFastPath(): void
    {
        $plain = 'Hello World';
        self::assertSame($plain, Spread::escapeXml($plain));
    }

    public function testEscapeXmlSpecialChars(): void
    {
        self::assertSame('foo&amp;bar', Spread::escapeXml('foo&bar'));
        self::assertSame('foo&lt;bar', Spread::escapeXml('foo<bar'));
        self::assertSame('foo&gt;bar', Spread::escapeXml('foo>bar'));
    }

    public function testEscapeXmlStripsControlChars(): void
    {
        $dirty = "Hello\x00World\x0B";
        self::assertSame('HelloWorld', Spread::escapeXml($dirty));
    }

    public function testEscapeXmlPreservesAllowedControls(): void
    {
        $allowed = "Tab\tLine\nReturn\r";
        self::assertSame($allowed, Spread::escapeXml($allowed));
    }

    public function testEscapeXmlAttrEmptyString(): void
    {
        self::assertSame('', Spread::escapeXmlAttr(''));
    }

    public function testEscapeXmlAttrFastPath(): void
    {
        $plain = 'Hello World';
        self::assertSame($plain, Spread::escapeXmlAttr($plain));
    }

    public function testEscapeXmlAttrEscapesQuotes(): void
    {
        self::assertSame('&quot;foo&apos;bar&quot;', Spread::escapeXmlAttr('"foo\'bar"'));
    }

    public function testEscapeXmlAttrEscapesSpecialChars(): void
    {
        self::assertSame('foo&amp;bar', Spread::escapeXmlAttr('foo&bar'));
        self::assertSame('foo&lt;bar', Spread::escapeXmlAttr('foo<bar'));
        self::assertSame('foo&gt;bar', Spread::escapeXmlAttr('foo>bar'));
    }

    public function testEscapeXmlAttrStripsControlChars(): void
    {
        $dirty = "Hello\x00World\x0B";
        self::assertSame('HelloWorld', Spread::escapeXmlAttr($dirty));
    }

    public function testValidateSheetNameValid(): void
    {
        self::assertSame('Sheet1', Spread::validateSheetName('Sheet1'));
        self::assertSame('My Data', Spread::validateSheetName('My Data'));
        self::assertSame('Résumé', Spread::validateSheetName('Résumé'));
        self::assertSame(str_repeat('a', 31), Spread::validateSheetName(str_repeat('a', 31)));
    }

    public function testValidateSheetNameEmptyThrows(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Sheet name must not be empty');
        Spread::validateSheetName('');
    }

    public function testValidateSheetNameTooLongThrows(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessageMatches('/Invalid XLSX sheet name/');
        Spread::validateSheetName(str_repeat('a', 32));
    }

    public function testValidateSheetNameInvalidCharsThrows(): void
    {
        $invalidNames = ['foo/bar', 'foo\\bar', 'foo?bar', 'foo*bar', 'foo:bar', 'foo[bar', 'foo]bar'];
        foreach ($invalidNames as $name) {
            try {
                Spread::validateSheetName($name);
                self::fail("Expected WriteException for sheet name: {$name}");
            } catch (WriteException $e) {
                self::assertStringContainsString('Invalid XLSX sheet name', $e->getMessage());
            }
        }
    }

    public function testValidateSheetNameLeadingApostropheThrows(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessageMatches('/Invalid XLSX sheet name/');
        Spread::validateSheetName("'Sheet1");
    }

    public function testValidateSheetNameTrailingApostropheThrows(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessageMatches('/Invalid XLSX sheet name/');
        Spread::validateSheetName("Sheet1'");
    }

    public function testValidateSheetNameHistoryThrows(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessageMatches('/Invalid XLSX sheet name/');
        Spread::validateSheetName('History');
    }

    public function testValidateSheetNameHistoryCaseInsensitiveThrows(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessageMatches('/Invalid XLSX sheet name/');
        Spread::validateSheetName('HISTORY');
    }

    public function testBuildColumnSelectionBasic(): void
    {
        [$map, $indices] = Spread::buildColumnSelection(['name', 'age'], ['id', 'name', 'age', 'city']);
        self::assertSame(['name' => 1, 'age' => 2], $map);
        self::assertSame([1 => true, 2 => true], $indices);
    }

    public function testBuildColumnSelectionEmpty(): void
    {
        [$map, $indices] = Spread::buildColumnSelection([], ['a', 'b']);
        self::assertSame([], $map);
        self::assertSame([], $indices);
    }

    public function testBuildColumnSelectionMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: missing');
        Spread::buildColumnSelection(['name', 'missing'], ['name', 'age']);
    }

    public function testBuildColumnSelectionDuplicateHeaders(): void
    {
        [$map, $indices] = Spread::buildColumnSelection(['a'], ['a', 'b', 'a']);
        self::assertSame(['a' => 0], $map);
    }

    public function testApplyColumnSelectionEmptyMap(): void
    {
        $row = ['a', 'b', 'c'];
        $result = Spread::applyColumnSelection($row, [], ['name'], false);
        self::assertSame($row, $result);
    }

    public function testApplyColumnSelectionAssoc(): void
    {
        $row = ['id' => 1, 'name' => 'John', 'age' => 30];
        $columnMap = ['name' => 1, 'age' => 2];
        $columns = ['name', 'age'];

        $result = Spread::applyColumnSelection($row, $columnMap, $columns, true);

        // Result should be keyed by column name, maintaining the requested order in $columns
        self::assertSame(['name' => 'John', 'age' => 30], $result);
    }

    public function testApplyColumnSelectionAssocMissingKey(): void
    {
        $row = ['id' => 1, 'name' => 'John']; // missing age
        $columnMap = ['name' => 1, 'age' => 2];
        $columns = ['name', 'age', 'city']; // missing city in columnMap doesn't matter for assoc mode, it just looks up $row

        $result = Spread::applyColumnSelection($row, $columnMap, $columns, true);

        self::assertSame(['name' => 'John', 'age' => null, 'city' => null], $result);
    }

    public function testApplyColumnSelectionIndexed(): void
    {
        $row = [1, 'John', 30];
        $columnMap = ['name' => 1, 'age' => 2];
        $columns = ['name', 'age'];

        $result = Spread::applyColumnSelection($row, $columnMap, $columns, false);

        // Result should be numerically indexed based on the requested $columns order
        self::assertSame(['John', 30], $result);
    }

    public function testApplyColumnSelectionIndexedMissingValues(): void
    {
        $row = [1, 'John']; // missing index 2 (age)
        $columnMap = ['name' => 1, 'age' => 2, 'city' => 3];
        $columns = ['name', 'age', 'city', 'country']; // country not in columnMap

        $result = Spread::applyColumnSelection($row, $columnMap, $columns, false);

        // missing index 2 -> null
        // city index 3 -> null
        // country -> index not in map -> null
        self::assertSame(['John', null, null, null], $result);
    }

    public function testGetOutputStream(): void
    {
        $tempFile = $this->tempFile('txt');
        $stream = Spread::getOutputStream($tempFile);
        self::assertIsResource($stream);
        fwrite($stream, 'hello');
        fclose($stream);
        self::assertStringEqualsFile($tempFile, 'hello');
        unlink($tempFile);
    }

    public function testGetOutputStreamPharBlocked(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid stream wrapper: phar is not allowed');
        Spread::getOutputStream('phar://test.phar');
    }

    public function testGetOutputStreamFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open stream');
        Spread::getOutputStream('/invalid/path/that/does/not/exist/file.txt');
    }

    public function testGetInputStreamFailure(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open stream');
        Spread::getInputStream(__DIR__ . '/data/non_existent_file_12345.csv');
    }

    public function testColumnRange(): void
    {
        $result = iterator_to_array(Spread::columnRange('A', 'C'));
        self::assertSame(['A', 'B', 'C'], $result);
    }

    public function testSafeXml(): void
    {
        $xml = Spread::safeXml('<root><child>value</child></root>');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);
        self::assertSame('value', (string) $xml->child);
    }

    public function testSafeXmlThrowsInvalidDocumentExceptionOnMalformedXml(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid XML document');
        Spread::safeXml('<root><child>unclosed</root>');
    }

    public function testIsSafePathRejectsPhpFilterWrapper(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid php:\/\/ stream/');
        Spread::isSafePath('php://filter/convert.base64-encode/resource=/etc/passwd');
    }

    public function testIsSafePathAllowsPlainPhpOutput(): void
    {
        // Should not throw
        Spread::isSafePath('php://output');
        Spread::isSafePath('php://temp');
        $this->addToAssertionCount(1);
    }

    public function testCheckNoDuplicateHeadersThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate header(s) found: name');
        Spread::checkNoDuplicateHeaders(['id', 'name', 'name']);
    }

    public function testCheckNoDuplicateHeadersPassesForUniqueHeaders(): void
    {
        Spread::checkNoDuplicateHeaders(['id', 'name', 'email']);
        $this->addToAssertionCount(1);
    }

    public function testGetTempFilename(): void
    {
        $tempFile = Spread::getTempFilename();
        self::assertFileExists($tempFile);
        self::assertStringStartsWith(realpath(sys_get_temp_dir()) . \DIRECTORY_SEPARATOR . 'BSH', $tempFile);
        unlink($tempFile);
    }

    public function testUtc(): void
    {
        $tz1 = Spread::utc();
        self::assertInstanceOf(\DateTimeZone::class, $tz1);
        self::assertSame('UTC', $tz1->getName());

        $tz2 = Spread::utc();
        self::assertSame($tz1, $tz2, 'utc() should return the same cached instance');
    }

    public function testFormatDurationComponents(): void
    {
        // Positive, no microseconds
        self::assertSame('1:02:03', Spread::formatDurationComponents(false, 1, 2, 3));

        // Negative, no microseconds
        self::assertSame('-1:02:03', Spread::formatDurationComponents(true, 1, 2, 3));

        // Positive, with microseconds
        self::assertSame('1:02:03.000004', Spread::formatDurationComponents(false, 1, 2, 3, 4));
        self::assertSame('1:02:03.123456', Spread::formatDurationComponents(false, 1, 2, 3, 123456));

        // Negative, with microseconds
        self::assertSame('-1:02:03.000004', Spread::formatDurationComponents(true, 1, 2, 3, 4));

        // Zero duration edge case (always positive, even if negative flag is true)
        self::assertSame('0:00:00', Spread::formatDurationComponents(true, 0, 0, 0));
        self::assertSame('0:00:00', Spread::formatDurationComponents(false, 0, 0, 0));

        // Zero duration with microseconds should still be negative if requested
        self::assertSame('-0:00:00.000001', Spread::formatDurationComponents(true, 0, 0, 0, 1));
        self::assertSame('0:00:00.000001', Spread::formatDurationComponents(false, 0, 0, 0, 1));
    }

    public function testZipGetData(): void
    {
        $tempZip = $this->tempFile('zip');
        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE);
        $zip->addFromString('test.txt', 'Hello World');
        $zip->close();

        $zip = new \ZipArchive();
        $zip->open($tempZip);

        // Happy path
        $content = Spread::zipGetData($zip, 'test.txt');
        self::assertSame('Hello World', $content);

        // Missing file
        $missing = Spread::zipGetData($zip, 'missing.txt');
        self::assertNull($missing);

        $zip->close();
        unlink($tempZip);
    }
}
