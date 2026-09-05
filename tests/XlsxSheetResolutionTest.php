<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\XlsxReader;
use ZipArchive;

/**
 * The XLSX reader must resolve the worksheet through the workbook and its
 * relationship target (relative or package-absolute), not by assuming a
 * hardcoded name. Broken references must fail loudly instead of silently
 * reading the wrong sheet.
 */
class XlsxSheetResolutionTest extends TestCase
{
    /**
     * @param array<string, string> $parts Archive path => content
     */
    private function buildArchive(array $parts): string
    {
        $file = $this->tempFile('xlsx');
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE);
        foreach ($parts as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();
        return $file;
    }

    private function workbook(string $sheetName, string $rId): string
    {
        return (
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="'
            . $sheetName
            . '" sheetId="1" state="visible" r:id="'
            . $rId
            . '"/></sheets></workbook>'
        );
    }

    private function rels(string ...$targets): string
    {
        $xml =
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($targets as $i => $target) {
            $id = 'rId' . ($i + 1);
            $xml .=
                '<Relationship Id="'
                . $id
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="'
                . $target
                . '"/>';
        }
        return $xml . '</Relationships>';
    }

    private function worksheet(string ...$values): string
    {
        $cells = '';
        foreach ($values as $i => $value) {
            $col = $i + 1;
            $cells .= '<c r="A' . $col . '"><v>' . $value . '</v></c>' . "\n";
        }
        return (
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1">'
            . "\n"
            . $cells
            . '</row></sheetData></worksheet>'
        );
    }

    public function testNoSelectionReadsFirstDeclaredSheet(): void
    {
        // The first sheet points to worksheets/sheet2.xml; there is no sheet1.xml.
        $file = $this->buildArchive([
            'xl/workbook.xml' => $this->workbook('Data', 'rId1'),
            'xl/_rels/workbook.xml.rels' => $this->rels('worksheets/sheet2.xml'),
            'xl/worksheets/sheet2.xml' => $this->worksheet('hello'),
        ]);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame([['hello']], $data);
    }

    public function testAbsoluteTargetResolvesWithoutDuplicatePrefix(): void
    {
        // Microsoft examples use a package-absolute target (/xl/worksheets/...);
        // it must not become xl//xl/worksheets/....
        $file = $this->buildArchive([
            'xl/workbook.xml' => $this->workbook('Data', 'rId1'),
            'xl/_rels/workbook.xml.rels' => $this->rels('/xl/worksheets/sheet1.xml'),
            'xl/worksheets/sheet1.xml' => $this->worksheet('hello'),
        ]);

        $reader = new XlsxReader();
        $reader->sheet = 'Data';
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame([['hello']], $data);
    }

    public function testRelativeTargetResolves(): void
    {
        $file = $this->buildArchive([
            'xl/workbook.xml' => $this->workbook('Data', 'rId1'),
            'xl/_rels/workbook.xml.rels' => $this->rels('worksheets/sheet2.xml'),
            'xl/worksheets/sheet2.xml' => $this->worksheet('hello'),
        ]);

        $reader = new XlsxReader();
        $reader->sheet = 'Data';
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame([['hello']], $data);
    }

    public function testBrokenRelationshipThrows(): void
    {
        // The workbook references rId9 but the relationships map only rId1.
        $file = $this->buildArchive([
            'xl/workbook.xml' => $this->workbook('Data', 'rId9'),
            'xl/_rels/workbook.xml.rels' => $this->rels('worksheets/sheet1.xml'),
            'xl/worksheets/sheet1.xml' => $this->worksheet('hello'),
        ]);

        $reader = new XlsxReader();
        try {
            iterator_to_array($reader->readFile($file));
            self::fail('Expected InvalidDocumentException for a broken relationship');
        } catch (InvalidDocumentException $e) {
            self::assertStringContainsString('not found in workbook relationships', $e->getMessage());
        } finally {
            unlink($file);
        }
    }

    public function testMissingRelsThrows(): void
    {
        // A workbook is present, so a missing relationships part can never be a
        // harmless minimal archive — silently falling back could import the
        // wrong sheet.
        $file = $this->buildArchive([
            'xl/workbook.xml' => $this->workbook('Data', 'rId1'),
            'xl/worksheets/sheet1.xml' => $this->worksheet('hello'),
        ]);

        $reader = new XlsxReader();
        try {
            iterator_to_array($reader->readFile($file));
            self::fail('Expected InvalidDocumentException for missing rels');
        } catch (InvalidDocumentException $e) {
            self::assertStringContainsString('Missing workbook relationships', $e->getMessage());
        } finally {
            unlink($file);
        }
    }

    public function testMinimalArchiveWithoutWorkbookFallsBackToSheet1(): void
    {
        // Worksheet-only archive (no workbook): the legacy single-sheet default
        // is the only sane interpretation.
        $file = $this->buildArchive([
            'xl/worksheets/sheet1.xml' => $this->worksheet('hello'),
        ]);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame([['hello']], $data);
    }
}
