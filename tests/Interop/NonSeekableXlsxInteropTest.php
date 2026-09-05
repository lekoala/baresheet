<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Interop;

use LeKoala\Baresheet\XlsxWriter;
use Shuchkin\SimpleXLSX;

/**
 * Reader interoperability for XLSX bytes emitted through the output API.
 */
class NonSeekableXlsxInteropTest extends InteropTestCase
{
    /** @var array<int, array<int, string>> */
    private const ROWS = [
        ['code', 'name'],
        ['A-1', 'Alice'],
        ['B-2', 'Bob'],
    ];

    public function testZipArchiveReadsOutput(): void
    {
        $file = $this->writeOutput();
        $zip = new \ZipArchive();

        self::assertTrue($zip->open($file, \ZipArchive::CHECKCONS));
        self::assertNotFalse($zip->getFromName('xl/worksheets/sheet1.xml'));

        $zip->close();
        unlink($file);
    }

    public function testBaresheetReadsOutput(): void
    {
        $file = $this->writeOutput();

        self::assertSame(self::ROWS, $this->readBaresheet($file));

        unlink($file);
    }

    public function testOpenSpoutReadsOutput(): void
    {
        $file = $this->writeOutput();

        self::assertSame(self::ROWS, $this->readOpenSpout('xlsx', $file));

        unlink($file);
    }

    public function testXlswriterReadsOutputWhenAvailable(): void
    {
        if (!extension_loaded('xlswriter') || !class_exists(\Vtiful\Kernel\Excel::class)) {
            self::markTestSkipped('The xlswriter extension is not available.');
        }

        $file = $this->writeOutput();
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

    public function testSimpleXlsxReadsOutput(): void
    {
        $file = $this->writeOutput();
        $xlsx = SimpleXLSX::parse($file);

        self::assertNotFalse($xlsx);
        self::assertSame(self::ROWS, $xlsx->rows());

        unlink($file);
    }

    private function writeOutput(): string
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
        self::assertSame(20, unpack('v', substr($bytes, 4, 2))[1], 'classic ZIP 2.0, never ZIP64');
        // Without this the readers below could be exercising a buffered archive
        // with patched headers, and prove nothing about the streamed layout.
        self::assertSame(8, unpack('v', substr($bytes, 6, 2))[1] & 8, 'entries carry a data descriptor');

        $file = $this->tempFile('xlsx');
        self::assertNotFalse(file_put_contents($file, $bytes));
        return $file;
    }
}
