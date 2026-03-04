<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

class XlsxTest extends TestCase
{
    public function testReadXlsxFromFile(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/basic.xlsx'));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testReadXlsxAssoc(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/header.xlsx', new Options(assoc: true)));
        self::assertNotEmpty($data);
        self::assertArrayHasKey('email', $data[0]);
    }

    public function testWriteXlsxToFile(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_test_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $result = $writer->writeFile([
            ["john", "doe", "john.doe@example.com"]
        ], $tempFile);

        self::assertTrue($result);
        self::assertTrue(is_file($tempFile));

        $reader = new XlsxReader();
        $readBack = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(1, $readBack);
        self::assertCount(3, $readBack[0]);

        unlink($tempFile);
    }

    public function testWriteXlsxToString(): void
    {
        $writer = new XlsxWriter();
        $output = $writer->writeString([
            ["john", "doe", "john.doe@example.com"]
        ]);

        self::assertStringContainsString('[Content_Types].xml', $output);
    }

    public function testXlsxRoundTrip(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_roundtrip_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $original = [
            ["John Doe", "john@example.com", "42"],
            ["Jane Doe", "jane@example.com", "99"],
        ];

        $writer->writeFile($original, $tempFile);

        $reader = new XlsxReader();
        $readBack = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $readBack);
        self::assertEquals("John Doe", $readBack[0][0]);
        self::assertEquals("john@example.com", $readBack[0][1]);

        unlink($tempFile);
    }

    public function testWriteWithCreator(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_creator_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(creator: 'TestApp');
        $writer->writeFile([["hello"]], $tempFile);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(1, $data);
        self::assertEquals("hello", $data[0][0]);

        unlink($tempFile);
    }

    public function testWriteWithNumericValues(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_num_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->writeFile([
            ["42", "3.14", "0", "007", "text"],
        ], $tempFile);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertEquals("42", $data[0][0]);
        self::assertEquals("3.14", $data[0][1]);
        self::assertEquals("0", $data[0][2]);
        self::assertEquals("007", $data[0][3]);
        self::assertEquals("text", $data[0][4]);

        unlink($tempFile);
    }

    public function testDateTimeSupport(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_date_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $dt = new \DateTime('2024-01-15 10:30:00');
        $writer->writeFile([[$dt, "label"]], $tempFile);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(1, $data);
        self::assertStringContainsString('2024-01-15', $data[0][0]);

        unlink($tempFile);
    }

    public function testAutoColumnWidths(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_colwidth_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->autoWidth = true;
        $writer->writeFile([
            ["Short", "A much longer string that should cause wider column"],
        ], $tempFile);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(1, $data);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        self::assertStringContainsString('customWidth="true"', $sheet);

        unlink($tempFile);
    }

    public function testXlsxWithEmptyValues(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_empty_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->writeFile([
            ["", "data", ""],
            ["hello", "", "world"],
        ], $tempFile);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);
        self::assertEquals("data", $data[0][1]);

        unlink($tempFile);
    }

    public function testLimitReading(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_limit_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->writeFile([
            ["row1"],
            ["row2"],
            ["row3"],
            ["row4"],
            ["row5"],
        ], $tempFile);

        $reader = new XlsxReader();
        $reader->limit = 2;
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);

        unlink($tempFile);
    }

    public function testAutofilterXml(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_af_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->autofilter = 'A1:B1';
        $writer->writeFile([
            ["Name", "Email"],
            ["John", "john@example.com"],
        ], $tempFile);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        self::assertStringContainsString('<autoFilter ref="A1:B1"/>', $sheet);

        unlink($tempFile);
    }

    public function testFreezePaneXml(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_fp_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->freezePane = 'A2';
        $writer->writeFile([
            ["Header1", "Header2"],
            ["Data1", "Data2"],
        ], $tempFile);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        self::assertStringContainsString('<pane ySplit="1"', $sheet);
        self::assertStringContainsString('state="frozen"', $sheet);

        unlink($tempFile);
    }

    public function testCustomSheetName(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_sn_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->sheet = 'MySheet';
        $writer->writeFile([["data"]], $tempFile);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $wb = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        self::assertStringContainsString('name="MySheet"', $wb);

        unlink($tempFile);
    }

    public function testBoldHeaders(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_bold_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->boldHeaders = true;
        $writer->writeFile([
            ["Name", "Email"],
            ["John", "john@example.com"],
        ], $tempFile);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        self::assertStringContainsString('s="2"', $sheet);

        unlink($tempFile);
    }

    public function testAssocWriteDetection(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_assoc_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->writeFile([
            ['name' => 'John', 'email' => 'john@example.com'],
            ['name' => 'Jane', 'email' => 'jane@example.com'],
        ], $tempFile);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(3, $data);
        self::assertEquals('name', $data[0][0]);
        self::assertEquals('email', $data[0][1]);

        unlink($tempFile);
    }

    public function testExplicitHeaders(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_hdr_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->headers = ['Name', 'Email'];
        $writer->writeFile([
            ["John", "john@example.com"],
        ], $tempFile);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);
        self::assertEquals('Name', $data[0][0]);

        unlink($tempFile);
    }

    public function testGetSheetNames(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_sheets_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->sheet = 'Reports';
        $writer->writeFile([["data"]], $tempFile);

        $names = \LeKoala\Baresheet\Spread::getSheetNames($tempFile);
        self::assertCount(1, $names);
        self::assertEquals('Reports', $names[0]);

        unlink($tempFile);
    }

    public function testReadBySheetName(): void
    {
        // Write a file with a custom sheet name, then read it back using $sheet
        $tempFile = sys_get_temp_dir() . '/baresheet_readsheet_' . time() . '.xlsx';
        $writer = new XlsxWriter();
        $writer->sheet = 'Data';
        $writer->writeFile([["hello"]], $tempFile);

        $reader = new XlsxReader();
        $reader->sheet = 'Data';
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(1, $data);
        self::assertEquals('hello', $data[0][0]);

        unlink($tempFile);
    }

    public function testOptionsPassThrough(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_opts_' . time() . '.xlsx';
        $opts = new Options(meta: new \LeKoala\Baresheet\Meta(creator: 'OptsCreator'), sheet: 'ViaOpts');
        $writer = new XlsxWriter();
        $writer->writeFile([["data"]], $tempFile, $opts);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $wb = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        self::assertStringContainsString('name="ViaOpts"', $wb);

        $props = \LeKoala\Baresheet\Spread::getProperties($tempFile);
        self::assertEquals('OptsCreator', $props['meta']['creator']);

        unlink($tempFile);
    }
}
