<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;

class OdsTest extends TestCase
{
    public function testWriteFilePreservesTempPathBehavior(): void
    {
        $tempPath = sys_get_temp_dir() . '/baresheet_ods_stage_' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tempPath));
        $file = $this->tempFile('ods');

        try {
            $writer = new OdsWriter();
            $writer->tempPath = $tempPath;
            self::assertTrue($writer->writeFile([['A', 'B'], ['1', '2']], $file));
            self::assertFileExists($file);
            self::assertSame([['A', 'B'], ['1', '2']], iterator_to_array((new OdsReader())->readFile($file)));
            $stagedFiles = scandir($tempPath);
            self::assertIsArray($stagedFiles);
            self::assertSame([], array_values(array_diff($stagedFiles, ['.', '..'])));
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
            rmdir($tempPath);
        }
    }

    public function testReadString(): void
    {
        $writer = new OdsWriter();
        $original = [
            ['Alice', 'alice@example.com', '24'],
            ['Bob',   'bob@example.com',   '35'],
        ];

        $output = $writer->writeString($original);

        $reader = new OdsReader();
        $readBack = iterator_to_array($reader->readString($output));

        self::assertCount(2, $readBack);
        self::assertEquals('Alice', $readBack[0][0]);
        self::assertEquals('bob@example.com', $readBack[1][1]);
        self::assertEquals('35', $readBack[1][2]);
    }

    public function testWriteAndReadBack(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $original = [
            ['John Doe', 'john@example.com', '42'],
            ['Jane Doe', 'jane@example.com', '99'],
        ];

        $writer->writeFile($original, $tempFile);
        self::assertTrue(is_file($tempFile));

        $reader = new OdsReader();
        $readBack = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $readBack);
        self::assertEquals('John Doe', $readBack[0][0]);
        self::assertEquals('john@example.com', $readBack[0][1]);
        self::assertEquals('42', $readBack[0][2]);
        self::assertEquals('99', $readBack[1][2]);

        unlink($tempFile);
    }

    public function testOdsRoundTripPreservesEmojiAndScientificNotation(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $original = [
            ['type',           'value'],
            ['emoji',          'Hello 😀🎉👍'],
            ['scientific',     '1.23E+5'],
            ['scientific_neg', '-4.56e-3'],
            ['multiline',      "line 1\nline 2\nline 3"],
            ['leading_zero',   '007'],
        ];

        $writer->writeFile($original, $tempFile);

        $reader = new OdsReader();
        $readBack = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(6, $readBack);
        self::assertSame('type', $readBack[0][0]);
        self::assertSame('Hello 😀🎉👍', $readBack[1][1]);
        self::assertSame('1.23E+5', $readBack[2][1]);
        self::assertSame('-4.56e-3', $readBack[3][1]);
        self::assertSame("line 1\nline 2\nline 3", $readBack[4][1]);
        self::assertSame('007', $readBack[5][1]);

        unlink($tempFile);
    }

    public function testOdsBooleanRoundTrip(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([
            ['name', 'active'],
            ['Alice', true],
            ['Bob', false],
        ], $tempFile);

        $reader = new OdsReader();
        $readBack = iterator_to_array($reader->readFile($tempFile));
        self::assertSame(
            [
                ['name', 'active'],
                ['Alice', '1'],
                ['Bob', '0'],
            ],
            $readBack,
        );

        unlink($tempFile);
    }

    public function testWriteToString(): void
    {
        $writer = new OdsWriter();
        $output = $writer->writeString([
            ['hello', 'world'],
        ]);

        $ext = \LeKoala\Baresheet\Spread::getExtensionForContent($output);
        self::assertEquals('ods', $ext);
    }

    public function testAssocMode(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->headers = ['name', 'email'];
        $writer->writeFile([
            ['John', 'john@example.com'],
            ['Jane', 'jane@example.com'],
        ], $tempFile);

        $reader = new OdsReader();
        $reader->assoc = true;
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);
        self::assertArrayHasKey('name', $data[0]);
        self::assertEquals('John', $data[0]['name']);

        unlink($tempFile);
    }

    public function testWithCreatorAndTitle(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(creator: 'TestCreator', title: 'TestTitle');
        $writer->writeFile([['data']], $tempFile);

        // Verify the meta.xml contains creator
        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $meta = $zip->getFromName('meta.xml');
        $zip->close();

        self::assertStringContainsString('TestCreator', $meta);
        self::assertStringContainsString('TestTitle', $meta);

        unlink($tempFile);
    }

    public function testWithAllMetaProperties(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->meta = new \LeKoala\Baresheet\Meta(
            title: 'MyTitle',
            subject: 'MySubject',
            creator: 'MyCreator',
            keywords: 'php, spreadsheet ,test',
            description: 'MyDescription',
            language: 'fr-FR',
        );
        $writer->writeFile([['data']], $tempFile);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $meta = $zip->getFromName('meta.xml');
        $zip->close();

        self::assertStringContainsString('MyTitle', $meta);
        self::assertStringContainsString('MySubject', $meta);
        self::assertStringContainsString('MyCreator', $meta);
        self::assertStringContainsString('<meta:keyword>php</meta:keyword>', $meta);
        self::assertStringContainsString('<meta:keyword>spreadsheet</meta:keyword>', $meta);
        self::assertStringContainsString('<meta:keyword>test</meta:keyword>', $meta);
        self::assertStringContainsString('MyDescription', $meta);
        self::assertStringContainsString('fr-FR', $meta);

        // Verify getProperties reads back keywords correctly
        $props = \LeKoala\Baresheet\Spread::getProperties($tempFile);
        self::assertEquals('php, spreadsheet, test', $props['meta']['keywords'] ?? null);

        unlink($tempFile);
    }

    public function testSheetName(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->sheet = 'MyData';
        $writer->writeFile([['test']], $tempFile);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $content = $zip->getFromName('content.xml');
        $zip->close();

        self::assertStringContainsString('MyData', $content);

        unlink($tempFile);
    }

    public function testLimitOption(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([
            ['row1'],
            ['row2'],
            ['row3'],
            ['row4'],
        ], $tempFile);

        $reader = new OdsReader();
        $reader->limit = 2;
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);

        unlink($tempFile);
    }

    public function testBaresheetFacadeOds(): void
    {
        $tempFile = $this->tempFile('ods');
        $original = [
            ['Alpha', 'Beta'],
        ];

        Baresheet::write($original, $tempFile);
        self::assertTrue(is_file($tempFile));

        $readBack = iterator_to_array(Baresheet::read($tempFile));
        self::assertCount(1, $readBack);
        self::assertEquals('Alpha', $readBack[0][0]);

        unlink($tempFile);
    }

    public function testNumericValues(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([
            [1, 2.5, 'text'],
        ], $tempFile);

        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertEquals('1', $data[0][0]);
        self::assertEquals('2.5', $data[0][1]);
        self::assertEquals('text', $data[0][2]);

        unlink($tempFile);
    }

    public function testDateTimeSupport(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $dt = new \DateTime('2025-06-15 10:30:00');
        $writer->writeFile([
            [$dt, 'text'],
        ], $tempFile);

        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertStringContainsString('2025-06-15', $data[0][0]);

        unlink($tempFile);
    }

    public function testContentDetection(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([['test']], $tempFile);

        $contents = file_get_contents($tempFile);
        $ext = \LeKoala\Baresheet\Spread::getExtensionForContent($contents);
        self::assertEquals('ods', $ext);

        unlink($tempFile);
    }

    public function testOptionsPassThrough(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        (new Options(
            meta: new \LeKoala\Baresheet\Meta(
                title: 'OptTitle',
                creator: 'OptCreator',
            ),
            sheet: 'OptSheet',
        ))->applyTo($writer);
        $writer->writeFile([['data']], $tempFile);

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
        self::assertNotEmpty(
            ($props['meta']['creator'] ?? '') . ($props['meta']['title'] ?? ''),
            'Expected at least creator or title to be set',
        );
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
        $output = $writer->writeString([['test']]);

        $tempFile = $this->tempFile('ods');
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
        $output = $writer->writeString([['Header'], ['Value']]);

        $tempFile = $this->tempFile('ods');
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
        self::assertStringNotContainsString(
            'table:style-name="bold" office:value-type="string"><text:p>Value</text:p>',
            $content,
        );
    }

    public function testHierarchicalHeadersRoundtrip(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->headers = [
            'Info' => ['Name', 'Age'],
            'Stats' => ['Score'],
        ];
        $writer->writeFile([
            ['Alice', '30', '95'],
            ['Bob', '25', '87'],
        ], $tempFile);
        self::assertTrue(is_file($tempFile));

        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(4, $data);

        self::assertEquals('Info', $data[0][0]);
        self::assertEquals('', $data[0][1]);
        self::assertEquals('Stats', $data[0][2]);

        self::assertEquals('Name', $data[1][0]);
        self::assertEquals('Age', $data[1][1]);
        self::assertEquals('Score', $data[1][2]);

        self::assertEquals('Alice', $data[2][0]);
        self::assertEquals('30', $data[2][1]);
        self::assertEquals('95', $data[2][2]);

        self::assertEquals('Bob', $data[3][0]);
        self::assertEquals('25', $data[3][1]);
        self::assertEquals('87', $data[3][2]);

        unlink($tempFile);
    }

    public function testHierarchicalHeadersRoundtripAssoc(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->headers = [
            'Info' => ['Name', 'Age'],
            'Stats' => ['Score'],
        ];
        $writer->writeFile([
            ['Alice', '30', '95'],
            ['Bob', '25', '87'],
        ], $tempFile);

        $reader = new OdsReader();
        $reader->assoc = true;
        $reader->headerRows = 2;
        $data = iterator_to_array($reader->readFile($tempFile));
        self::assertCount(2, $data);

        self::assertSame(['Name' => 'Alice', 'Age' => '30'], $data[0]['Info']);
        self::assertSame(['Score' => '95'], $data[0]['Stats']);

        self::assertSame(['Name' => 'Bob', 'Age' => '25'], $data[1]['Info']);
        self::assertSame(['Score' => '87'], $data[1]['Stats']);

        unlink($tempFile);
    }

    public function testHierarchicalHeadersBold(): void
    {
        $writer = new OdsWriter();
        $writer->boldHeaders = true;
        $writer->headers = [
            'Info' => ['Name', 'Age'],
            'Stats' => ['Score'],
        ];
        $output = $writer->writeString([['Alice', 30, 95]]);

        $tempFile = $this->tempFile('ods');
        file_put_contents($tempFile, $output);

        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $content = $zip->getFromName('content.xml');
        $zip->close();
        unlink($tempFile);

        $rows = explode('</table:table-row>', $content);

        self::assertStringContainsString('table:style-name="bold"', $rows[0]);
        self::assertStringContainsString('<text:p>Info</text:p>', $rows[0]);

        self::assertStringContainsString('table:style-name="bold"', $rows[1]);
        self::assertStringContainsString('<text:p>Name</text:p>', $rows[1]);

        self::assertStringNotContainsString('table:style-name="bold"', $rows[2]);
        self::assertStringContainsString('<text:p>Alice</text:p>', $rows[2]);
    }

    public function testMaxWorksheetSizeLimitOds(): void
    {
        $fixture = __DIR__ . '/data/large.ods';

        // A tiny limit of 100 bytes should trigger an exception
        $readerTiny = new OdsReader(new Options(maxWorksheetSize: 100));

        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');
        iterator_to_array($readerTiny->readFile($fixture));
    }

    public function testMaxWorksheetSizeUnlimitedAndSufficientLimitOds(): void
    {
        $fixture = __DIR__ . '/data/large.ods';

        // An unlimited (null) limit should successfully parse
        $readerNull = new OdsReader(new Options(maxWorksheetSize: null));
        $dataNull = iterator_to_array($readerNull->readFile($fixture));
        self::assertNotEmpty($dataNull);

        // A large limit should successfully parse
        $readerLarge = new OdsReader(new Options(maxWorksheetSize: 10_000_000));
        $dataLarge = iterator_to_array($readerLarge->readFile($fixture));
        self::assertNotEmpty($dataLarge);
    }
}
