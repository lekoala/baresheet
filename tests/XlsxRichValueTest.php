<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxReader;
use ZipArchive;

/**
 * Rich value cells (`vm` attribute) read as their fallback: `fb` when present,
 * otherwise `_DisplayString`. Unresolvable cells keep the cached "#VALUE!".
 */
class XlsxRichValueTest extends TestCase
{
    private const GEOGRAPHY = __DIR__ . '/data/rich-geography.xlsx';

    /** @return list<array<int|string, mixed>> */
    private static function read(string $path, bool $stringify): array
    {
        $reader = new XlsxReader(new Options(stringifyValues: $stringify));
        return array_values(iterator_to_array($reader->readFile($path)));
    }

    public function testGeographyLinkedEntitiesReadAsDisplayString(): void
    {
        $expected = [
            ['Country',  'Description'],
            ['Belgique', 'More French fries'],
            ['France',   'More Baguette'],
            ['Italie',   'More Pizzas'],
        ];
        self::assertSame($expected, self::read(self::GEOGRAPHY, true));
        self::assertSame($expected, self::read(self::GEOGRAPHY, false));
    }

    /**
     * @param string $richValues <rv> elements
     * @param bool $withRels Declare the rich data parts in the workbook relationships.
     */
    private function writeRichValueXlsx(string $cells, string $richValues, bool $withRels = true): string
    {
        $path = $this->tempFile('xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString(
            'xl/workbook.xml',
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
        );
        $rels =
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>';
        if ($withRels) {
            $rels .=
                '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sheetMetadata"'
                . ' Target="metadata.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.microsoft.com/office/2017/06/relationships/rdRichValue"'
                . ' Target="richData/rdrichvalue.xml"/>'
                . '<Relationship Id="rId4" Type="http://schemas.microsoft.com/office/2017/06/relationships/rdRichValueStructure"'
                . ' Target="richData/rdrichvaluestructure.xml"/>';
        }
        $zip->addFromString(
            'xl/_rels/workbook.xml.rels',
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>',
        );
        $zip->addFromString(
            'xl/worksheets/sheet1.xml',
            '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1">'
            . $cells
            . '</row></sheetData></worksheet>',
        );
        // vm="1" -> rich value 1, vm="2" -> rich value 2, vm="3" -> rich value 3.
        $zip->addFromString(
            'xl/metadata.xml',
            '<metadata xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:xlrd="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata">'
            . '<metadataTypes count="1"><metadataType name="XLRICHVALUE"/></metadataTypes>'
            . '<futureMetadata name="XLRICHVALUE" count="3">'
            . '<bk><extLst><ext uri="{3e2802c4-a4d2-4d8b-9148-e3be6c30e623}"><xlrd:rvb i="1"/></ext></extLst></bk>'
            . '<bk><extLst><ext uri="{3e2802c4-a4d2-4d8b-9148-e3be6c30e623}"><xlrd:rvb i="2"/></ext></extLst></bk>'
            . '<bk><extLst><ext uri="{3e2802c4-a4d2-4d8b-9148-e3be6c30e623}"><xlrd:rvb i="3"/></ext></extLst></bk>'
            . '</futureMetadata>'
            . '<valueMetadata count="3"><bk><rc t="1" v="0"/></bk><bk><rc t="1" v="1"/></bk><bk><rc t="1" v="2"/></bk></valueMetadata>'
            . '</metadata>',
        );
        $zip->addFromString(
            'xl/richData/rdrichvaluestructure.xml',
            '<rvStructures xmlns="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata" count="2">'
            . '<s t="_linkedentity2"><k n="%EntityServiceId" t="i"/><k n="_DisplayString" t="s"/></s>'
            . '<s t="_webimage"><k n="WebImageIdentifier" t="i"/></s>'
            . '</rvStructures>',
        );
        $zip->addFromString(
            'xl/richData/rdrichvalue.xml',
            '<rvData xmlns="http://schemas.microsoft.com/office/spreadsheetml/2017/richdata">'
            . $richValues
            . '</rvData>',
        );
        $zip->close();
        return $path;
    }

    private const RICH_VALUES =
        '<rv s="0"><v>1</v><v>Unused</v></rv>'
            . '<rv s="0"><fb>42.5</fb><v>1</v><v>Ignored display</v></rv>'
            . '<rv s="0"><v>1</v><v>Brussels</v></rv>'
            . '<rv s="1"><v>0</v></rv>';

    public function testFallbackElementWinsAndKeepsItsType(): void
    {
        $path = $this->writeRichValueXlsx(
            '<c r="A1" t="e" vm="1"><v>#VALUE!</v></c><c r="B1" t="e" vm="2"><v>#VALUE!</v></c>',
            self::RICH_VALUES,
        );
        self::assertSame([[42.5, 'Brussels']], self::read($path, false));
        self::assertSame([['42.5', 'Brussels']], self::read($path, true));
    }

    public function testRichValueWithoutFallbackOrDisplayStringKeepsCachedError(): void
    {
        $path = $this->writeRichValueXlsx('<c r="A1" t="e" vm="3"><v>#VALUE!</v></c>', self::RICH_VALUES);
        self::assertSame([['#VALUE!']], self::read($path, false));
    }

    public function testUndeclaredRichDataPartsKeepCachedError(): void
    {
        $path = $this->writeRichValueXlsx('<c r="A1" t="e" vm="2"><v>#VALUE!</v></c>', self::RICH_VALUES, false);
        self::assertSame([['#VALUE!']], self::read($path, false));
    }
}
