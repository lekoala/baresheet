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

    public function testReadDateXlsx(): void
    {
        $reader = new XlsxReader();
        $reader->assoc = true;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/date.xlsx'));

        $arr = array_values($data);
        $firstRow = $arr[0];
        self::assertEquals('2016-10-14', $firstRow['BirthDate']);
        self::assertEquals('2025-01-01 10:00:00', $firstRow['Created']);
        self::assertEquals('10:00:00', $firstRow['BestTime']);

        // Test that it works even for silly dates
        self::assertEquals('1545-01-15', $arr[1]['BirthDate']);
        self::assertEquals('2955-12-10', $arr[2]['BirthDate']);
        self::assertEquals('1242-09-16', $arr[3]['BirthDate']);
        self::assertEquals('1742-09-16', $arr[4]['BirthDate']);
        self::assertEquals('1900-09-16', $arr[5]['BirthDate']);
        self::assertEquals('1899-09-16', $arr[6]['BirthDate']);
        self::assertEquals('4111-09-16', $arr[7]['BirthDate']);
        self::assertTrue('' === $arr[8]['BirthDate']); // it has no t attribute so it is simply a string

        // Invalid dates are treated as strings
        self::assertEquals('00/00/0000', $arr[9]['BirthDate']);
    }

    public function testReadDurationXlsx(): void
    {
        $reader = new XlsxReader();
        $reader->assoc = true;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/duration.xlsx'));

        self::assertCount(3, $data);

        $row1 = [
            "Title" => "My title",
            "Date" => "2020-09-24",
            "Start" => "08:00",
            "Duration" => "610",
            "Names" => null,
            "Boolean" => "1",
            "Extra column" => "",
        ];
        self::assertEquals($row1, $data[0]);

        $row3 = [
            "Title" => "",
            "Date" => "2020-10-07",
            "Start" => "16:20",
            "Duration" => "40",
            "Names" => 'smith, john',
            "Boolean" => "0",
            "Extra column" => "My title",
        ];
        self::assertEquals($row3, $data[2]);
    }

    public function testReadDurationZeroXlsx(): void
    {
        $reader = new XlsxReader();
        $reader->assoc = true;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/duration-zero.xlsx'));

        self::assertCount(2, $data);

        $row1 = [
            "Title" => "My Title",
            "Date" => "2020-09-24",
            "Start" => "08:00",
            "Duration" => "610",
            "Names" => null,
            "Boolean" => "1",
            "Extra Title" => null,
        ];
        self::assertEquals($row1, $data[0]);
    }

    public function testReadEmptyColXlsx(): void
    {
        $reader = new XlsxReader();
        $reader->assoc = true;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/empty-col.xlsx'));

        self::assertEquals([
            [
                'col1' => "v1",
                'col2' => "v2",
                'col3' => null,
                'col4' => "v4",
            ],
            [
                'col1' => "v1",
                'col2' => null,
                'col3' => null,
                'col4' => "v4",
            ],
            [
                'col1' => null,
                'col2' => "v2",
                'col3' => "v3",
                'col4' => null,
            ]
        ], $data);
    }

    public function testReadEmptyCol2Xlsx(): void
    {
        $reader = new XlsxReader();
        $reader->assoc = true;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/empty-col-2.xlsx'));

        self::assertEquals([
            [
                'col1' => "v1",
                'col2' => "v2",
                'col3' => null,
                'col4' => "v4",
                'col5' => null,
                'col6' => null,
                'col7' => null,
            ],
            [
                'col1' => "v1",
                'col2' => null,
                'col3' => null,
                'col4' => "v4",
                'col5' => null,
                'col6' => null,
                'col7' => null,
            ],
            [
                'col1' => null,
                'col2' => "v2",
                'col3' => "v3",
                'col4' => null,
                'col5' => null,
                'col6' => null,
                'col7' => null,
            ]
        ], $data);
    }

    public function testReadEmptyXlsx(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/empty.xlsx'));
        self::assertCount(0, $data);
    }

    public function testReadLargeXlsx(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/large.xlsx'));
        self::assertGreaterThan(100, count($data));
    }

    public function testReadMultisheetXlsx(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/multisheet.xlsx'));
        self::assertNotEmpty($data);
    }

    public function testReadDemoTmpFileXlsx(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/demo-tmp-file.xlsx'));
        $decodedString = json_encode($data);
        self::assertStringContainsString('john.doe@example.com', $decodedString);
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

    public function testSkipEmptyLinesByDefault(): void
    {
        $reader = new \LeKoala\Baresheet\XlsxReader();
        // In XLSX, empty lines are usually not stored or handled by reader
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/basic.xlsx'));
        self::assertCount(1, $data);
    }

    public function testOffsetAndLimit(): void
    {
        $reader = new \LeKoala\Baresheet\XlsxReader();
        $reader->offset = 1;
        $reader->limit = 1;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/large.xlsx'));
        self::assertCount(1, $data);
        // large.xlsx has '1' in first data row, '2' in second (which is our first after offset 1)
        self::assertEquals('2', $data[0][0]);
    }

    public function testRead1904DateXlsx(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/date-1904.xlsx'));

        self::assertCount(2, $data);
        self::assertEquals('2019-09-02', $data[0][0]);
        self::assertEquals('2019-09-03', $data[0][1]);
        self::assertEquals('2019-09-02 22:23:00', $data[0][2]);
        self::assertEquals('1904-02-29 23:59:59', $data[1][0]);
        // Note: 1904-03-02 and 1904-03-01 11:00:00
        self::assertEquals('1904-03-02', $data[1][1]);
        self::assertEquals('1904-03-01 11:00:00', $data[1][2]);
    }

    public function testReadMultiNodeInlineStringsXlsx(): void
    {
        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/inline-strings-multi-node.xlsx'));

        self::assertCount(1, $data);
        self::assertEquals('VALUE 1 VALUE 2 VALUE 3 VALUE 4', $data[0][0]);
        self::assertEquals('s1 - B1', $data[0][1]);
    }

    public function testIsDateTimeFormatCode(): void
    {
        // Positive cases: Standard
        self::assertTrue(XlsxReader::isDateTimeFormatCode('yyyy-mm-dd'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('dd/mm/yyyy'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('m/d/yy'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('d-mmm-yy'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('mmmm d, yyyy'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('mm/dd'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('d/m/y'));

        // Case variations
        self::assertTrue(XlsxReader::isDateTimeFormatCode('YYYY-MM-DD'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('M/D/YY'));

        // Time and Duration
        self::assertTrue(XlsxReader::isDateTimeFormatCode('h:mm'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('hh:mm:ss'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('h:mm AM/PM'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('[h]:mm:ss'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('[mm]:ss'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('h:mm:ss.000'));

        // Special patterns
        self::assertTrue(XlsxReader::isDateTimeFormatCode('e/m/d'));
        self::assertTrue(XlsxReader::isDateTimeFormatCode('[$-409]yyyy-mm-dd'));

        // Negative cases
        self::assertFalse(XlsxReader::isDateTimeFormatCode('General'));
        self::assertFalse(XlsxReader::isDateTimeFormatCode('0'));
        self::assertFalse(XlsxReader::isDateTimeFormatCode('0.00'));
        self::assertFalse(XlsxReader::isDateTimeFormatCode('#,##0'));
        self::assertFalse(XlsxReader::isDateTimeFormatCode('#,##0.00'));
        self::assertFalse(XlsxReader::isDateTimeFormatCode('0%'));
        self::assertFalse(XlsxReader::isDateTimeFormatCode('0.00E+00'));
        self::assertFalse(XlsxReader::isDateTimeFormatCode('"$"#,##0.00'));
    }
}
