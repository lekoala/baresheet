<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxReader;
use ZipArchive;

/**
 * Rich value cells (entities, linked data types, in-cell arrays) are not decoded yet:
 * the reader ignores `vm` and returns the cached error text. These tests pin that baseline.
 */
class XlsxRichValueTest extends TestCase
{
    private function writeRichValueXlsx(): string
    {
        $path = $this->tempFile('xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="inlineStr"><is><t>Brussels</t></is></c>'
            . '<c r="B1" t="e" vm="1"><v>#VALUE!</v></c></row>'
            . '</sheetData></worksheet>',
        );
        $zip->addFromString(
            'xl/metadata.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<metadata xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:xlrd="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata">'
            . '<metadataTypes count="1"><metadataType name="XLRICHVALUE" minSupportedVersion="120000"/></metadataTypes>'
            . '<futureMetadata name="XLRICHVALUE" count="1"><bk><extLst><ext uri="{3e2802c4-a4d2-4d8b-9148-e3be6c30e623}">'
            . '<xlrd:rvb i="0"/></ext></extLst></bk></futureMetadata>'
            . '<valueMetadata count="1"><bk><rc t="1" v="0"/></bk></valueMetadata>'
            . '</metadata>',
        );
        $zip->addFromString(
            'xl/richData/rdrichvalue.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<rvData xmlns="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata" count="1">'
            . '<rv s="0"><v>Belgium</v></rv></rvData>',
        );
        $zip->close();
        return $path;
    }

    public function testRichValueCellReadsAsCachedErrorText(): void
    {
        $path = $this->writeRichValueXlsx();
        foreach ([true, false] as $stringify) {
            $reader = new XlsxReader(new Options(stringifyValues: $stringify));
            $rows = iterator_to_array($reader->readFile($path));
            self::assertSame(['Brussels', '#VALUE!'], $rows[0]);
        }
    }
}
