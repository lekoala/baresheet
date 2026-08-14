<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Interop;

use LeKoala\Baresheet\OdsWriter;

/**
 * Reader interoperability for ODS bytes emitted to a non-seekable output.
 */
class NonSeekableOdsInteropTest extends InteropTestCase
{
    /** @var array<int, array<int, string>> */
    private const ROWS = [
        ['code', 'name'],
        ['A-1', 'Alice'],
        ['B-2', 'Bob'],
    ];

    public function testPackageLayoutAndZipArchiveInterop(): void
    {
        [$file, $bytes] = $this->writeNonSeekableOutput();
        $mime = OdsWriter::MIMETYPE;

        self::assertSame(20, unpack('v', substr($bytes, 4, 2))[1]);
        self::assertSame(0x0800, unpack('v', substr($bytes, 6, 2))[1]);
        self::assertSame(0, unpack('v', substr($bytes, 8, 2))[1]);
        self::assertSame(0, unpack('v', substr($bytes, 28, 2))[1]);
        self::assertSame('mimetype', substr($bytes, 30, 8));
        self::assertSame($mime, substr($bytes, 38, strlen($mime)));

        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file, \ZipArchive::CHECKCONS));
        self::assertSame('mimetype', $zip->getNameIndex(0));
        self::assertSame($mime, $zip->getFromIndex(0));
        self::assertSame(\ZipArchive::CM_STORE, $zip->statIndex(0)['comp_method'] ?? null);
        self::assertNotFalse($zip->getFromName('content.xml'));
        $zip->close();

        unlink($file);
    }

    public function testBaresheetReadsNonSeekableOutput(): void
    {
        [$file] = $this->writeNonSeekableOutput();

        self::assertSame(self::ROWS, $this->readBaresheet($file));

        unlink($file);
    }

    public function testOpenSpoutReadsNonSeekableOutput(): void
    {
        [$file] = $this->writeNonSeekableOutput();

        self::assertSame(self::ROWS, $this->readOpenSpout('ods', $file));

        unlink($file);
    }

    /** @return array{0:string, 1:string} */
    private function writeNonSeekableOutput(): array
    {
        $writer = new OdsWriter();

        ob_start();
        try {
            $writer->output(self::ROWS, 'non-seekable.ods');
            $bytes = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        self::assertIsString($bytes);
        $file = $this->tempFile('ods');
        self::assertNotFalse(file_put_contents($file, $bytes));
        return [$file, $bytes];
    }
}
