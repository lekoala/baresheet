<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Baresheet;

class OdsTest extends TestCase
{
    public function testWriteAndReadBack(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_' . time() . '.ods';
        $writer = new OdsWriter();
        $original = [
            ["John Doe", "john@example.com", "42"],
            ["Jane Doe", "jane@example.com", "99"],
        ];

        $writer->writeFile($original, $tempFile);
        self::assertTrue(is_file($tempFile));

        $reader = new OdsReader();
        $readBack = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $readBack);
        self::assertEquals("John Doe", $readBack[0][0]);
        self::assertEquals("john@example.com", $readBack[0][1]);
        self::assertEquals("42", $readBack[0][2]);
        self::assertEquals("99", $readBack[1][2]);

        unlink($tempFile);
    }

    public function testWriteToString(): void
    {
        $writer = new OdsWriter();
        $output = $writer->writeString([
            ["hello", "world"]
        ]);

        $ext = \LeKoala\Baresheet\Spread::getExtensionForContent($output);
        self::assertEquals('ods', $ext);
    }

    public function testAssocMode(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_assoc_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->headers = ['name', 'email'];
        $writer->writeFile([
            ["John", "john@example.com"],
            ["Jane", "jane@example.com"],
        ], $tempFile);

        $reader = new OdsReader();
        $reader->assoc = true;
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);
        self::assertArrayHasKey('name', $data[0]);
        self::assertEquals("John", $data[0]['name']);

        unlink($tempFile);
    }

    public function testWithCreatorAndTitle(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_meta_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(creator: 'TestCreator', title: 'TestTitle');
        $writer->writeFile([["data"]], $tempFile);

        // Verify the meta.xml contains creator
        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $meta = $zip->getFromName('meta.xml');
        $zip->close();

        self::assertStringContainsString('TestCreator', $meta);
        self::assertStringContainsString('TestTitle', $meta);

        unlink($tempFile);
    }

    public function testSheetName(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_sheet_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->sheet = 'MyData';
        $writer->writeFile([["test"]], $tempFile);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $content = $zip->getFromName('content.xml');
        $zip->close();

        self::assertStringContainsString('MyData', $content);

        unlink($tempFile);
    }

    public function testLimitOption(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_limit_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->writeFile([
            ["row1"],
            ["row2"],
            ["row3"],
            ["row4"],
        ], $tempFile);

        $reader = new OdsReader();
        $reader->limit = 2;
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);

        unlink($tempFile);
    }

    public function testBaresheetFacadeOds(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_facade_' . time() . '.ods';
        $original = [
            ["Alpha", "Beta"],
        ];

        Baresheet::write($original, $tempFile);
        self::assertTrue(is_file($tempFile));

        $readBack = iterator_to_array(Baresheet::read($tempFile));
        self::assertCount(1, $readBack);
        self::assertEquals("Alpha", $readBack[0][0]);

        unlink($tempFile);
    }

    public function testNumericValues(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_num_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->writeFile([
            [1, 2.5, "text"],
        ], $tempFile);

        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertEquals("1", $data[0][0]);
        self::assertEquals("2.5", $data[0][1]);
        self::assertEquals("text", $data[0][2]);

        unlink($tempFile);
    }

    public function testDateTimeSupport(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_date_' . time() . '.ods';
        $writer = new OdsWriter();
        $dt = new \DateTime('2025-06-15 10:30:00');
        $writer->writeFile([
            [$dt, "text"],
        ], $tempFile);

        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertStringContainsString('2025-06-15', $data[0][0]);

        unlink($tempFile);
    }

    public function testContentDetection(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_detect_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->writeFile([["test"]], $tempFile);

        $contents = file_get_contents($tempFile);
        $ext = \LeKoala\Baresheet\Spread::getExtensionForContent($contents);
        self::assertEquals('ods', $ext);

        unlink($tempFile);
    }

    public function testOptionsPassThrough(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_ods_opts_' . time() . '.ods';
        $writer = new OdsWriter();
        $writer->writeFile([["data"]], $tempFile, new Options(
            meta: new \LeKoala\Baresheet\Meta(
                title: 'OptTitle',
                creator: 'OptCreator'
            ),
            sheet: 'OptSheet',
        ));

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $meta = $zip->getFromName('meta.xml');
        $content = $zip->getFromName('content.xml');
        $zip->close();

        self::assertStringContainsString('OptCreator', $meta);
        self::assertStringContainsString('OptTitle', $meta);
        self::assertStringContainsString('OptSheet', $content);

        unlink($tempFile);
    }
    // -- Fixture-based tests (real ODS files in tests/data/) --

    public function testReadDateFixture(): void
    {
        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/date.ods'));
        self::assertNotEmpty($data);
        // Skip header row if present, date should be in the first data row (column 3)
        $dateStr = isset($data[1]) ? $data[1][3] : $data[0][3];
        // Date cells should contain ISO 8601 date strings or formatted date
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $dateStr);
    }

    public function testReadLargeFixture(): void
    {
        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/large.ods'));
        // Large file should have many rows
        self::assertGreaterThan(10, count($data));
    }

    public function testReadMultisheetFixture(): void
    {
        $reader = new OdsReader();
        // Read default (first) sheet
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/multisheet.ods'));
        self::assertNotEmpty($data);

        // Verify sheet names
        $sheets = \LeKoala\Baresheet\Spread::getSheetNames(__DIR__ . '/data/multisheet.ods');
        self::assertGreaterThan(1, count($sheets));

        // Read second sheet by index
        $reader2 = new OdsReader();
        $reader2->sheet = 1;
        $data2 = iterator_to_array($reader2->readFile(__DIR__ . '/data/multisheet.ods'));
        self::assertNotEmpty($data2);

        // Read by name
        $reader3 = new OdsReader();
        $reader3->sheet = $sheets[1];
        $data3 = iterator_to_array($reader3->readFile(__DIR__ . '/data/multisheet.ods'));
        self::assertNotEmpty($data3);
    }

    public function testReadEmptyWithPropsFixture(): void
    {
        $props = \LeKoala\Baresheet\Spread::getProperties(__DIR__ . '/data/empty-with-props.ods');
        self::assertEquals('ods', $props['format']);
        // File should have metadata set
        self::assertNotEmpty(($props['meta']['creator'] ?? '') . ($props['meta']['title'] ?? ''), 'Expected at least creator or title to be set');
    }

    public function testSkipEmptyLinesByDefault(): void
    {
        $reader = new \LeKoala\Baresheet\OdsReader();
        // ODS like XLSX skips empty rows by default
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/date.ods'));
        self::assertNotEmpty($data);
    }

    public function testOffsetAndLimit(): void
    {
        $reader = new \LeKoala\Baresheet\OdsReader();
        $reader->offset = 1;
        $reader->limit = 1;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/large.ods'));
        self::assertCount(1, $data);
        // large.ods has '1' in first data row, '2' in second
        self::assertEquals('2', $data[0][0]);
    }

    public function testStylesAreAlwaysDefined(): void
    {
        $writer = new OdsWriter();
        // boldHeaders is false by default
        $output = $writer->writeString([["test"]]);

        $tempFile = sys_get_temp_dir() . '/test_styles_' . time() . '.ods';
        file_put_contents($tempFile, $output);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $content = $zip->getFromName('content.xml');
        $zip->close();
        unlink($tempFile);

        self::assertStringContainsString('style:name="ta1"', $content);
        self::assertStringContainsString('style:name="bold"', $content);
        self::assertStringContainsString('fo:font-weight="bold"', $content);
    }

    public function testBoldHeadersReferenceBoldStyle(): void
    {
        $writer = new OdsWriter();
        $writer->boldHeaders = true;
        $output = $writer->writeString([["Header"], ["Value"]]);

        $tempFile = sys_get_temp_dir() . '/test_bold_ref_' . time() . '.ods';
        file_put_contents($tempFile, $output);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $content = $zip->getFromName('content.xml');
        $zip->close();
        unlink($tempFile);

        // First row should have the bold style
        self::assertStringContainsString('table:style-name="bold"', $content);
        self::assertStringContainsString('<text:p>Header</text:p>', $content);
        // Second row should NOT have the bold style (in that specific context)
        self::assertStringContainsString('<text:p>Value</text:p>', $content);
        // We can check it doesn't have it right before Value
        self::assertStringNotContainsString('table:style-name="bold" office:value-type="string"><text:p>Value</text:p>', $content);
    }
}
