<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use Exception;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Spread;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;

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
            description: 'A < B & C > D',
        );

        $tempFile = $this->tempFile('xlsx');
        $writer->writeFile([['Test']], $tempFile);

        $this->assertTrue(is_file($tempFile), 'XLSX file should be generated successfully.');

        // Extract docProps/core.xml
        $zip = new \ZipArchive();
        $zip->open($tempFile);
        $coreXml = $zip->getFromName('docProps/core.xml');
        $zip->close();

        $this->assertIsString($coreXml);

        // Assert that the malicious payload is NOT interpreted as XML nodes
        $this->assertStringContainsString(
            'Normal Title&lt;/dc:title&gt;&lt;dc:creator&gt;Injected Creator&lt;/dc:creator&gt;&lt;dc:title&gt;Rest',
            $coreXml,
        );
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

        $tempFile = $this->tempFile('xlsx');
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

    public function testPharDeserializationBlocked(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid stream wrapper: phar is not allowed');
        Spread::isSafePath('phar://test.phar');
    }

    public function testPharDeserializationBlockedCaseInsensitive(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid stream wrapper: phar is not allowed');
        Spread::isSafePath('PHAR://test.phar');
    }

    public function testPharDeserializationBlockedNested(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Phar deserialization is not allowed');
        Spread::isSafePath('php://filter/resource=phar://test.phar');
    }

    /**
     * Test that zipGetData rejects entries whose uncompressed size exceeds the
     * configured limit, preventing Zip Bomb DoS attacks.
     */
    public function testZipBombRejection(): void
    {
        $tempZip = $this->tempFile('zip');

        $zip = new \ZipArchive();
        $zip->open($tempZip, \ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', str_repeat('A', 200));
        $zip->close();

        $zip = new \ZipArchive();
        $zip->open($tempZip);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');

        Spread::zipGetData($zip, 'xl/workbook.xml', maxSize: 100);

        $zip->close();
        unlink($tempZip);
    }

    public static function falsifiedSizeProvider(): array
    {
        return [
            'xlsx' => [XlsxWriter::class, XlsxReader::class, 'xlsx', 'xl/worksheets/sheet1.xml'],
            'ods' => [OdsWriter::class, OdsReader::class, 'ods', 'content.xml'],
        ];
    }

    /**
     * A falsified declared entry size must not bypass maxWorksheetSize: the cap
     * applies to actual decompressed bytes, not statIndex()['size'].
     */
    #[DataProvider('falsifiedSizeProvider')]
    public function testFalsifiedZipEntrySizeRejected(
        string $writerClass,
        string $readerClass,
        string $ext,
        string $entry,
    ): void {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = ['alpha-' . $i, 'beta-' . $i, 'gamma-' . $i, 'delta-' . $i, 'epsilon-' . $i];
        }

        $file = $this->tempFile($ext);
        $writer = new $writerClass();
        $writer->writeFile($rows, $file);

        $patched = $this->falsifyDeclaredEntrySize($file, $entry, 1024);

        // Sanity check: the declared size is really what the old guard trusted.
        $zip = new \ZipArchive();
        $zip->open($patched);
        $stat = $zip->statName($entry);
        $zip->close();
        $this->assertSame(1024, $stat['size']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum allowed size');

        try {
            $reader = new $readerClass(new Options(maxWorksheetSize: 2048));
            iterator_to_array($reader->readFile($patched));
        } finally {
            unlink($file);
            unlink($patched);
        }
    }

    /**
     * Rewrite a file with the central directory's uncompressed-size field of
     * one entry set to a fake value. Compressed data is left untouched.
     */
    private function falsifyDeclaredEntrySize(string $file, string $entry, int $fakeSize): string
    {
        $data = file_get_contents($file);
        $this->assertIsString($data);

        $eocd = strrpos($data, "PK\x05\x06");
        $this->assertNotFalse($eocd, 'End of central directory not found');
        $cdOffset = unpack('V', substr($data, $eocd + 16, 4))[1];

        $pos = $cdOffset;
        while (substr($data, $pos, 4) === "PK\x01\x02") {
            $fileNameLen = unpack('v', substr($data, $pos + 28, 2))[1];
            $extraLen = unpack('v', substr($data, $pos + 30, 2))[1];
            $commentLen = unpack('v', substr($data, $pos + 32, 2))[1];
            if (substr($data, $pos + 46, $fileNameLen) === $entry) {
                $data = substr_replace($data, pack('V', $fakeSize), $pos + 24, 4);
                $out = $this->tempFile(pathinfo($file, PATHINFO_EXTENSION));
                file_put_contents($out, $data);
                return $out;
            }
            $pos += 46 + $fileNameLen + $extraLen + $commentLen;
        }

        $this->fail("Entry {$entry} not found in central directory");
    }
}
