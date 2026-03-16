<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\Spread;
use LeKoala\Baresheet\XlsxWriter;
use LeKoala\Baresheet\OdsWriter;

class SpreadTest extends TestCase
{
    public function testGetPropertiesXlsx(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_props_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(
            creator: 'TestCreator',
            title: 'TestTitle',
            subject: 'TestSubject'
        );
        $writer->writeFile([["data"]], $tempFile);

        $props = Spread::getProperties($tempFile);
        self::assertEquals('xlsx', $props['format']);
        self::assertEquals('TestCreator', $props['meta']['creator'] ?? null);
        self::assertEquals('TestTitle', $props['meta']['title'] ?? null);
        self::assertEquals('TestSubject', $props['meta']['subject'] ?? null);
        self::assertContains('Sheet1', $props['sheets']);

        unlink($tempFile);
    }

    public function testGetPropertiesOds(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_props_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(creator: 'OdsCreator', title: 'OdsTitle');
        $writer->sheet = 'MySheet';
        $writer->writeFile([["data"]], $tempFile);

        $props = Spread::getProperties($tempFile);
        self::assertEquals('ods', $props['format']);
        self::assertEquals('OdsCreator', $props['meta']['creator'] ?? null);
        self::assertEquals('OdsTitle', $props['meta']['title'] ?? null);
        self::assertContains('MySheet', $props['sheets']);

        unlink($tempFile);
    }

    public function testGetSheetNamesOds(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_sheets_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->sheet = 'TestSheet';
        $writer->writeFile([["data"]], $tempFile);

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
}
