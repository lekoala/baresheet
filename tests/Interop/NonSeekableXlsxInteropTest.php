<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Interop;

use LeKoala\Baresheet\XlsxWriter;
use Shuchkin\SimpleXLSX;

/**
 * Reader interoperability for XLSX bytes emitted to a non-seekable output.
 */
class NonSeekableXlsxInteropTest extends InteropTestCase
{
    /** @var array<int, array<int, string>> */
    private const ROWS = [
        ['code', 'name'],
        ['A-1', 'Alice'],
        ['B-2', 'Bob'],
    ];

    public function testZipArchiveReadsNonSeekableOutput(): void
    {
        $file = $this->writeNonSeekableOutput();
        $zip = new \ZipArchive();

        self::assertTrue($zip->open($file, \ZipArchive::CHECKCONS));
        self::assertNotFalse($zip->getFromName('xl/worksheets/sheet1.xml'));

        $zip->close();
        unlink($file);
    }

    public function testBaresheetReadsNonSeekableOutput(): void
    {
        $file = $this->writeNonSeekableOutput();

        self::assertSame(self::ROWS, $this->readBaresheet($file));

        unlink($file);
    }

    public function testOpenSpoutReadsNonSeekableOutput(): void
    {
        $file = $this->writeNonSeekableOutput();

        self::assertSame(self::ROWS, $this->readOpenSpout('xlsx', $file));

        unlink($file);
    }

    public function testXlswriterReadsNonSeekableOutputWhenAvailable(): void
    {
        if (!extension_loaded('xlswriter') || !class_exists(\Vtiful\Kernel\Excel::class)) {
            self::markTestSkipped('The xlswriter extension is not available.');
        }

        $file = $this->writeNonSeekableOutput();
        $excel = new \Vtiful\Kernel\Excel(['path' => dirname($file)]);
        $excel->openFile(basename($file))->openSheet();

        $rows = [];
        while (($row = $excel->nextRow()) !== null) {
            $rows[] = $row;
        }

        self::assertSame(self::ROWS, $rows);

        unset($excel);
        unlink($file);
    }

    public function testSimpleXlsxNonSeekableOutputLimitationIsDocumented(): void
    {
        $file = $this->writeNonSeekableOutput();
        $xlsx = SimpleXLSX::parse($file);

        self::assertFalse(
            $xlsx,
            'SimpleXLSX unexpectedly accepted ZIP64 data descriptors; update the interoperability matrix.',
        );

        unlink($file);
    }

    private function writeNonSeekableOutput(): string
    {
        $writer = new XlsxWriter();

        ob_start();
        try {
            $writer->output(self::ROWS, 'non-seekable.xlsx');
            $bytes = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        self::assertIsString($bytes);
        self::assertSame(45, unpack('v', substr($bytes, 4, 2))[1]);
        self::assertSame(0x0808, unpack('v', substr($bytes, 6, 2))[1]);

        $file = $this->tempFile('xlsx');
        self::assertNotFalse(file_put_contents($file, $bytes));
        return $file;
    }
}
