<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

class SharedStringsTest extends TestCase
{
    /**
     * Build a minimal XLSX archive containing only sharedStrings.xml and a single worksheet.
     */
    private function buildXlsx(string $sharedStringsXml, int $stringCount): string
    {
        $cells = '';
        for ($i = 0; $i < $stringCount; $i++) {
            $col = chr(ord('A') + $i);
            $cells .= '<c r="' . $col . '1" t="s"><v>' . $i . '</v></c>';
        }

        $worksheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData><row r="1">' . $cells . '</row></sheetData></worksheet>';

        $tmp = $this->tempFile('xlsx');

        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $worksheet);
        $zip->close();

        $bytes = file_get_contents($tmp);
        unlink($tmp);

        if ($bytes === false) {
            throw new \RuntimeException('Unable to read temp file');
        }
        return $bytes;
    }

    public function testReadsRichTextWhitespaceAndSkipsPhoneticRuns(): void
    {
        $ss = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="5" uniqueCount="5">'
            . '<si><t xml:space="preserve">  leading/trailing  </t></si>'
            . '<si><r><t>Hello</t></r><r><t> World</t></r></si>'
            . '<si><t>こんにちは</t><rPh sb="0" eb="5"><t>コン</t></rPh><phoneticPr fontId="1"/></si>'
            . '<si></si>'
            . '<si><t xml:space="preserve">   </t></si>'
            . '</sst>';

        $bytes = $this->buildXlsx($ss, 5);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readString($bytes));

        self::assertSame(
            [['  leading/trailing  ', 'Hello World', 'こんにちは', '', '   ']],
            $data,
        );
    }

    public function testSharedStringsRoundTripViaWriter(): void
    {
        $writer = new XlsxWriter();
        $writer->sharedStrings = true;
        $bytes = $writer->writeString([
            ['alpha', 'beta', 'alpha'],
            ['beta', '  spaced  ', 'alpha'],
        ]);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readString($bytes));

        self::assertSame(
            [
                ['alpha', 'beta', 'alpha'],
                ['beta', '  spaced  ', 'alpha'],
            ],
            $data,
        );
    }
}
