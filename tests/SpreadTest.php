<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Spread;
use LeKoala\Baresheet\XlsxWriter;
use PHPUnit\Framework\TestCase;

class SpreadTest extends TestCase
{
    public function testGetPropertiesXlsx(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_props_' . time() . '.xlsx';
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
        $tempFile = sys_get_temp_dir() . '/baresheet_props_' . time() . '.ods';
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

    public function testGetSheetNamesOds(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_sheets_' . time() . '.ods';
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
}
