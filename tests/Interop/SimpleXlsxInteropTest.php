<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Interop;

use LeKoala\Baresheet\XlsxWriter;
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

/**
 * Interop between Baresheet XLSX and shuchkin/simplexlsx + simplexlsxgen.
 */
class SimpleXlsxInteropTest extends InteropTestCase
{
    public function testBaresheetReadsSimpleXLSXGen(): void
    {
        $file = $this->tempFile('xlsx');
        SimpleXLSXGen::fromArray([
            ['id',    'code', 'amount', 'notes'],
            ['00123', '007',  3.14,     'Hello 😀'],
            [42,      '42',   0,        "ligne 1\nligne 2"],
        ])->saveAs($file);

        $rows = $this->readBaresheet($file);

        self::assertSame(
            [
                ['id',    'code', 'amount', 'notes'],
                ['00123', '007',  '3.14',   'Hello 😀'],
                ['42',    '42',   '0',      "ligne 1\nligne 2"],
            ],
            $rows,
        );

        unlink($file);
    }

    public function testSimpleXLSXReadsBaresheet(): void
    {
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->writeFile([
            ['id',    'code', 'amount', 'notes'],
            ['00123', '007',  3.14,     'Hello 😀'],
            [42,      '42',   0,        "ligne 1\nligne 2"],
        ], $file);

        $xlsx = SimpleXLSX::parse($file);
        self::assertNotFalse($xlsx);

        $rows = $xlsx->rows();

        self::assertSame(
            [
                ['id',    'code', 'amount', 'notes'],
                ['00123', '007',  3.14,     'Hello 😀'],
                [42,      42,     0,        "ligne 1\nligne 2"],
            ],
            $rows,
        );

        unlink($file);
    }
}
