<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Interop;

use DateTime;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Spread;
use LeKoala\Baresheet\XlsxWriter;

/**
 * Interop between Baresheet XLSX/ODS and OpenSpout.
 */
class OpenSpoutInteropTest extends InteropTestCase
{
    public function testOpenSpoutReadsBaresheetBasicXlsx(): void
    {
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $data = [
            ['id',    'name',        'amount', 'notes'],
            ['00123', 'José "Pepe"', 12.5,     "ligne 1\nligne 2"],
            ['42',    'Müller',      0,        ''],
            ['7',     'Jane',        3.14,     null],
        ];
        $writer->writeFile($data, $file);

        $rows = $this->readOpenSpout('xlsx', $file);

        self::assertSame(
            [
                ['id',    'name',        'amount', 'notes'],
                ['00123', 'José "Pepe"', 12.5,     "ligne 1\nligne 2"],
                [42,      'Müller',      0,        ''],
                [7,       'Jane',        3.14,     ''],
            ],
            $rows,
        );

        unlink($file);
    }

    public function testOpenSpoutReadsBothBaresheetStringModes(): void
    {
        $expected = [
            ['name',    'notes'],
            ['José 😀', "ligne 1\nligne 2"],
            ['Müller',  ''],
        ];

        foreach ([false, true] as $sharedStrings) {
            $file = $this->tempFile('xlsx');
            $writer = new XlsxWriter();
            $writer->sharedStrings = $sharedStrings;
            $writer->writeFile($expected, $file);

            self::assertSame(
                $expected,
                $this->readOpenSpout('xlsx', $file),
                'sharedStrings=' . ($sharedStrings ? 'true' : 'false'),
            );

            unlink($file);
        }
    }

    public function testBaresheetReadsOpenSpoutBasicXlsx(): void
    {
        $file = $this->tempFile('xlsx');
        $this->writeOpenSpout('xlsx', $file, [
            ['id',    'name',        'amount', 'active'],
            ['00123', 'José "Pepe"', 12.5,     true],
            ['42',    'Müller',      0,        false],
            ['7',     'Jane',        3.14,     true],
        ]);

        $rows = $this->readBaresheet($file);

        self::assertSame(
            [
                ['id',    'name',        'amount', 'active'],
                ['00123', 'José "Pepe"', '12.5',   '1'],
                ['42',    'Müller',      '0',      '0'],
                ['7',     'Jane',        '3.14',   '1'],
            ],
            $rows,
        );

        unlink($file);
    }

    public function testBaresheetPreservesExplicitTextAndLeadingZeros(): void
    {
        $file = $this->tempFile('xlsx');
        $this->writeOpenSpout('xlsx', $file, [
            ['id', 'code'],
            ['00123', '007'],
            ['42', 'text'],
        ]);

        $rows = $this->readBaresheet($file);

        self::assertSame(
            [
                ['id', 'code'],
                ['00123', '007'],
                ['42', 'text'],
            ],
            $rows,
        );

        unlink($file);
    }

    public function testDatesAreInteroperableInBothDirections(): void
    {
        // Baresheet writes, OpenSpout reads
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->writeFile([
            ['name', 'created'],
            ['Alice', new DateTime('2026-07-30 14:00:00')],
            ['Bob', new DateTime('2024-01-15 10:30:00')],
        ], $file);

        $rows = $this->readOpenSpout('xlsx', $file);
        self::assertSame(
            [
                ['name',  'created'],
                ['Alice', '2026-07-30 14:00:00'],
                ['Bob',   '2024-01-15 10:30:00'],
            ],
            $rows,
        );
        unlink($file);

        // OpenSpout writes, Baresheet reads
        $file = $this->tempFile('xlsx');
        $this->writeOpenSpout('xlsx', $file, [
            ['name', 'created'],
            ['Alice', new \DateTimeImmutable('2026-07-30 14:00:00')],
            ['Bob', new \DateTimeImmutable('2024-01-15 10:30:00')],
        ]);

        $rows = $this->readBaresheet($file);
        self::assertSame(
            [
                ['name',  'created'],
                ['Alice', '2026-07-30 14:00:00'],
                ['Bob',   '2024-01-15 10:30:00'],
            ],
            $rows,
        );
        unlink($file);
    }

    public function testSparseCellsAndRowsAreInteroperable(): void
    {
        // Baresheet writes with holes; OpenSpout reads them back as empty strings
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->writeFile([
            ['a', 'b', 'c'],
            ['x', null, 'z'],
            ['', 'y', ''],
        ], $file);

        $rows = $this->readOpenSpout('xlsx', $file);
        self::assertSame(
            [
                ['a', 'b', 'c'],
                ['x', '', 'z'],
                ['', 'y', ''],
            ],
            $rows,
        );
        unlink($file);

        // OpenSpout writes holes; Baresheet reads them as null
        $file = $this->tempFile('xlsx');
        $this->writeOpenSpout('xlsx', $file, [
            ['a', 'b', 'c'],
            ['x', null, 'z'],
            ['', 'y', null],
        ]);

        $rows = $this->readBaresheet($file);
        self::assertSame(
            [
                ['a', 'b', 'c'],
                ['x', null, 'z'],
                [null, 'y', null],
            ],
            $rows,
        );
        unlink($file);
    }

    public function testHierarchicalHeadersWorkInBothDirections(): void
    {
        // Baresheet writes a hierarchical header, OpenSpout reads the physical rows
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->headers = [
            'Patient' => ['ID', 'Nom'],
            'Domaine' => ['Date', 'Statut', 'ICD10'],
        ];
        $writer->writeFile([
            ['123', 'Jane', '2026-07-30', 'Actif', 'A01'],
        ], $file);

        $rows = $this->readOpenSpout('xlsx', $file);
        self::assertSame(
            [
                ['Patient', '',     'Domaine',    '',       ''],
                ['ID',      'Nom',  'Date',       'Statut', 'ICD10'],
                [123,       'Jane', '2026-07-30', 'Actif',  'A01'],
            ],
            $rows,
        );
        unlink($file);

        // OpenSpout writes the physical rows, Baresheet rebuilds the nested headers
        $file = $this->tempFile('xlsx');
        $this->writeOpenSpout('xlsx', $file, [
            ['Patient', 'Patient', 'Domaine',    'Domaine', 'Domaine'],
            ['ID',      'Nom',     'Date',       'Statut',  'ICD10'],
            ['123',     'Jane',    '2026-07-30', 'Actif',   'A01'],
        ]);

        $rows = $this->readBaresheet($file, new Options(assoc: true, headerRows: 2));
        self::assertSame(
            [
                [
                    'Patient' => ['ID' => '123', 'Nom' => 'Jane'],
                    'Domaine' => ['Date' => '2026-07-30', 'Statut' => 'Actif', 'ICD10' => 'A01'],
                ],
            ],
            $rows,
        );
        unlink($file);
    }

    public function testSheetNameIsInteroperable(): void
    {
        // Baresheet writes a custom sheet name, OpenSpout reads it
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->sheet = 'Mon Sheet é';
        $writer->writeFile([['a', 'b']], $file);

        self::assertSame('Mon Sheet é', $this->openSpoutSheetName('xlsx', $file));
        unlink($file);

        // OpenSpout writes a custom sheet name, Baresheet reads it
        $file = $this->tempFile('xlsx');
        $this->writeOpenSpout('xlsx', $file, [['a', 'b']], 'Données 2026');

        self::assertSame(['Données 2026'], Spread::getSheetNames($file));
        unlink($file);
    }

    public function testOpenSpoutReadsBaresheetOds(): void
    {
        $file = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([
            ['name', 'value'],
            ['Alice', 42],
            ['Bob', 3.14],
            ['Carol', ''],
        ], $file);

        $rows = $this->readOpenSpout('ods', $file);

        self::assertSame(
            [
                ['name', 'value'],
                ['Alice', 42],
                ['Bob', 3.14],
                ['Carol', ''],
            ],
            $rows,
        );

        unlink($file);
    }

    public function testBaresheetReadsOpenSpoutOds(): void
    {
        $file = $this->tempFile('ods');
        $this->writeOpenSpout('ods', $file, [
            ['name', 'value'],
            ['Alice', 42],
            ['Bob', 3.14],
            ['Carol', ''],
        ]);

        $rows = $this->readBaresheet($file);

        self::assertSame(
            [
                ['name', 'value'],
                ['Alice', '42'],
                ['Bob', '3.14'],
                ['Carol', null],
            ],
            $rows,
        );

        unlink($file);
    }
}
