<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use Exception;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Spread;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    /**
     * Test that Core Properties (title, creator, description, etc) are correctly escaped
     * in XlsxWriter to prevent XML Injection that could break the docProps/core.xml file.
     */
    public function testXlsxCorePropertiesXmlInjection(): void
    {
        $writer = new XlsxWriter();
        // Malicious properties
        $writer->meta = new \LeKoala\Baresheet\Meta(
            title: 'Normal Title</dc:title><dc:creator>Injected Creator</dc:creator><dc:title>Rest',
            creator: 'Attacker" onclick="alert(1)',
            description: 'A < B & C > D'
        );

        $tempFile = sys_get_temp_dir() . '/test_security_props_' . time() . '.xlsx';
        $writer->writeFile([['Test']], $tempFile);

        $this->assertTrue(is_file($tempFile), 'XLSX file should be generated successfully.');

        // Extract docProps/core.xml
        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $coreXml = $zip->getFromName('docProps/core.xml');
        $zip->close();

        $this->assertIsString($coreXml);

        // Assert that the malicious payload is NOT interpreted as XML nodes
        $this->assertStringContainsString('Normal Title&lt;/dc:title&gt;&lt;dc:creator&gt;Injected Creator&lt;/dc:creator&gt;&lt;dc:title&gt;Rest', $coreXml);
        $this->assertStringNotContainsString('<dc:creator>Injected Creator</dc:creator>', $coreXml);

        // Assert description properly escapes generic entities
        $this->assertStringContainsString('A &lt; B &amp; C &gt; D', $coreXml);

        unlink($tempFile);
    }

    /**
     * Test that Xlsx sheet names and autofilters (which are placed inside XML attributes)
     * are properly escaped so that double quotes (") do not break out of the attribute.
     */
    public function testXlsxAttributeInjection(): void
    {
        $writer = new XlsxWriter();
        // Sheet is placed inside <sheet name="...">
        $writer->sheet = 'Evil"Sheet';
        // Autofilter is placed inside <autoFilter ref="...">
        $writer->autofilter = 'A1:B2" evilattr="true';

        $tempFile = sys_get_temp_dir() . '/test_security_attrs_' . time() . '.xlsx';
        $writer->writeFile([['Test', 'Data'], ['Row', '1']], $tempFile);

        $this->assertTrue(is_file($tempFile), 'XLSX file should be generated successfully.');

        // Extract workbook.xml (contains the sheet name attribute)
        // Extract xl/worksheets/sheet1.xml (contains the autofilter attribute)
        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertIsString($workbookXml);
        $this->assertIsString($sheetXml);

        // Check that the double quote was escaped to &quot; in Sheet name
        $this->assertStringContainsString('name="Evil&quot;Sheet"', $workbookXml);
        // Check that the invalid autofilter was OMITTED due to validation
        $this->assertStringNotContainsString('<autoFilter', $sheetXml);

        unlink($tempFile);
    }

    /**
     * Confirm Zip files that are non-existent or corrupted correctly throw exceptions
     * instead of silently ignoring failures.
     */
    public function testZipArchiveErrorHandling(): void
    {
        $invalidFile = __DIR__ . '/data/auto.csv'; // A CSV is not a valid ZIP file

        // Readers should throw
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Failed to open zip archive/');

        // Testing XlsxReader
        $reader = new XlsxReader();
        iterator_to_array($reader->readFile($invalidFile));
    }
}
