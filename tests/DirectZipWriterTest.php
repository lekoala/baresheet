<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use Closure;
use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Internal\DirectZipWriter;
use LeKoala\Baresheet\Tests\Support\FailingFlushStream;
use LeKoala\Baresheet\Tests\Support\FailingWriteStream;
use PHPUnit\Framework\Attributes\DataProvider;

class DirectZipWriterTest extends TestCase
{
    private function readEntry(string $file, string $name): string
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file), 'ZipArchive must open the produced archive');
        $contents = $zip->getFromName($name);
        $zip->close();
        self::assertIsString($contents, "entry '{$name}' must exist");
        return $contents;
    }

    private function entryCount(string $file): int
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        $count = $zip->numFiles;
        $zip->close();
        return $count;
    }

    public function testSmallStoredEntry(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('stored.txt', "stored content\n", store: true);
        $w->finish();

        self::assertSame("stored content\n", $this->readEntry($file, 'stored.txt'));
        @unlink($file);
    }

    public function testKnownStoredStringHasCompleteHeaderOnNonSeekableOutput(): void
    {
        $name = 'mimetype';
        $contents = 'application/vnd.oasis.opendocument.spreadsheet';

        ob_start();
        $stream = fopen('php://output', 'wb');
        self::assertIsResource($stream);
        $writer = new DirectZipWriter($stream);
        self::assertFalse($writer->isSeekable());
        $writer->addString($name, $contents, store: true);
        $writer->finish();
        fclose($stream);
        $bytes = ob_get_clean();

        self::assertIsString($bytes);
        self::assertSame(0x0403_4b50, unpack('V', substr($bytes, 0, 4))[1]);
        self::assertSame(20, unpack('v', substr($bytes, 4, 2))[1]);
        self::assertSame(0x0800, unpack('v', substr($bytes, 6, 2))[1]);
        self::assertSame(0, unpack('v', substr($bytes, 8, 2))[1]);
        self::assertSame((int) hexdec(hash('crc32b', $contents)), unpack('V', substr($bytes, 14, 4))[1]);
        self::assertSame(strlen($contents), unpack('V', substr($bytes, 18, 4))[1]);
        self::assertSame(strlen($contents), unpack('V', substr($bytes, 22, 4))[1]);
        self::assertSame(strlen($name), unpack('v', substr($bytes, 26, 2))[1]);
        self::assertSame(0, unpack('v', substr($bytes, 28, 2))[1]);
        self::assertSame($name, substr($bytes, 30, strlen($name)));
        self::assertSame($contents, substr($bytes, 38, strlen($contents)));
        self::assertSame(
            38 + strlen($contents),
            strpos($bytes, pack('V', 0x0201_4b50)),
            'A known STORE entry must have no data descriptor before the central directory',
        );
    }

    public function testSmallDeflatedEntry(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('deflated.txt', "deflated content\n");
        $w->finish();

        self::assertSame("deflated content\n", $this->readEntry($file, 'deflated.txt'));
        @unlink($file);
    }

    public function testEmptyEntry(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('empty.txt', '');
        $w->finish();

        self::assertSame('', $this->readEntry($file, 'empty.txt'));
        @unlink($file);
    }

    public function testMultipleEntries(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('a.txt', 'AAA');
        $w->addString('b/c.txt', 'BBB');
        $w->addString('d.txt', 'DDD');
        $w->finish();

        self::assertSame(3, $this->entryCount($file));
        self::assertSame('AAA', $this->readEntry($file, 'a.txt'));
        self::assertSame('BBB', $this->readEntry($file, 'b/c.txt'));
        self::assertSame('DDD', $this->readEntry($file, 'd.txt'));
        @unlink($file);
    }

    public function testUtf8EntryName(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('café ☕ 日本語.txt', 'unicode content');
        $w->finish();

        self::assertSame('unicode content', $this->readEntry($file, 'café ☕ 日本語.txt'));
        @unlink($file);
    }

    public function testChunkedCallbackEntry(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $expected = '';
        $w->addCallback('chunks.bin', static function (Closure $write) use (&$expected): void {
            for ($i = 0; $i < 500; $i++) {
                $chunk = str_repeat("line {$i}\n", 10);
                $expected .= $chunk;
                $write($chunk);
            }
        });
        $w->finish();

        self::assertSame($expected, $this->readEntry($file, 'chunks.bin'));
        @unlink($file);
    }

    public function testStreamEntry(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $source = fopen('php://temp', 'r+');
        fwrite($source, str_repeat("stream content\n", 1000));
        rewind($source);
        $w->addStream('stream.txt', $source, readChunkSize: 4096);
        $w->finish();
        fclose($source);

        self::assertSame(str_repeat("stream content\n", 1000), $this->readEntry($file, 'stream.txt'));
        @unlink($file);
    }

    public function testZip64ArchiveLevelByEntryCount(): void
    {
        // More than 65535 entries forces the archive-level ZIP64 EOCD records.
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        for ($i = 0; $i < 65_536; $i++) {
            $w->addString("e{$i}.txt", "entry {$i}");
        }
        $w->finish();

        self::assertSame(65_536, $this->entryCount($file));
        self::assertSame('entry 0', $this->readEntry($file, 'e0.txt'));
        self::assertSame('entry 65535', $this->readEntry($file, 'e65535.txt'));
        @unlink($file);
    }

    /**
     * A streamed entry announces its sizes afterwards, but stays classic.
     *
     * Excel opens an archive whose local header defers crc and sizes to a
     * trailing descriptor; what it refuses is ZIP64, in every form. So the
     * non-seekable path sets general purpose bit 3 and nothing else: version
     * 2.0, no ZIP64 extra field, and a 32-bit descriptor after the data.
     */
    public function testNonSeekableOutputUsesClassicDataDescriptor(): void
    {
        ob_start();
        $stream = fopen('php://output', 'wb');
        self::assertIsResource($stream);
        $writer = new DirectZipWriter($stream);
        self::assertFalse($writer->isSeekable());
        $writer->addString('hello.txt', "hello\n");
        $writer->finish();
        fclose($stream);
        $bytes = ob_get_clean();

        self::assertIsString($bytes);
        self::assertSame(20, unpack('v', substr($bytes, 4, 2))[1], 'classic version needed to extract');
        self::assertSame(0x0808, unpack('v', substr($bytes, 6, 2))[1], 'UTF-8 names plus data descriptor');
        self::assertSame(0, unpack('V', substr($bytes, 18, 4))[1], 'compressed size deferred, not a sentinel');
        self::assertSame(0, unpack('V', substr($bytes, 22, 4))[1], 'uncompressed size deferred, not a sentinel');
        self::assertSame(0, unpack('v', substr($bytes, 28, 2))[1], 'no local extra field');

        // The descriptor is 16 bytes: signature, crc, then two 32-bit sizes.
        $descriptorOffset = strpos($bytes, pack('V', 0x0807_4b50));
        self::assertIsInt($descriptorOffset);
        self::assertSame(6, unpack('V', substr($bytes, $descriptorOffset + 12, 4))[1], 'uncompressed size');

        $centralOffset = strpos($bytes, pack('V', 0x0201_4b50));
        self::assertIsInt($centralOffset);
        self::assertSame(20, unpack('v', substr($bytes, $centralOffset + 6, 2))[1]);
        self::assertSame(0, unpack('v', substr($bytes, $centralOffset + 30, 2))[1], 'no central extra field');
        self::assertNotSame(0xFFFF_FFFF, unpack('V', substr($bytes, $centralOffset + 20, 4))[1]);
        self::assertNotSame(0xFFFF_FFFF, unpack('V', substr($bytes, $centralOffset + 24, 4))[1]);

        $file = $this->tempFile('zip');
        file_put_contents($file, $bytes);
        self::assertSame("hello\n", $this->readEntry($file, 'hello.txt'));
        @unlink($file);
    }

    public function testDoubleFinishThrows(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('a.txt', 'A');
        $w->finish();

        $this->expectException(WriteException::class);
        $w->finish();
        @unlink($file);
    }

    public function testWriteAfterFinishThrows(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->finish();

        $this->expectException(WriteException::class);
        $w->addString('late.txt', 'too late');
        @unlink($file);
    }

    public function testFinishFlushFailureThrows(): void
    {
        $scheme = 'baresheetfailflush';
        stream_wrapper_register($scheme, FailingFlushStream::class);

        try {
            $stream = fopen($scheme . '://zip', 'w+b');
            self::assertIsResource($stream);

            $writer = new DirectZipWriter($stream);
            $writer->addString('a.txt', 'AAA');

            try {
                $writer->finish();
                self::fail('finish() must report a failed flush');
            } catch (WriteException) {
            }

            // Once the flush failed the archive is indeterminate: no further
            // writes or finish attempts may succeed.
            try {
                $writer->addString('late.txt', 'too late');
                self::fail('addString() must be rejected after a failed flush');
            } catch (WriteException) {
            }

            try {
                $writer->finish();
                self::fail('finish() must be rejected after a failed flush');
            } catch (WriteException) {
            }
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function appendModes(): iterable
    {
        yield 'a' => ['a'];
        yield 'ab' => ['ab'];
        yield 'a+b' => ['a+b'];
    }

    #[DataProvider('appendModes')]
    public function testAppendModeRejected(string $mode): void
    {
        $file = $this->tempFile('zip');
        $stream = fopen($file, $mode);
        self::assertIsResource($stream);

        try {
            $this->expectException(WriteException::class);
            new DirectZipWriter($stream);
        } finally {
            fclose($stream);
            @unlink($file);
        }
    }

    public function testProducerExceptionMarksWriterFailed(): void
    {
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('before.txt', 'BEFORE');

        try {
            $w->addCallback('broken.txt', static function (Closure $write): void {
                $write('partial payload');
                throw new \RuntimeException('Simulated producer failure');
            });
            self::fail('The producer exception must propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('Simulated producer failure', $e->getMessage());
        }

        // The entry was partially written; the archive can no longer be used.
        try {
            $w->addString('late.txt', 'too late');
            self::fail('addString() must be rejected after a partial entry failure');
        } catch (WriteException $e) {
            self::assertStringContainsString('failed state', $e->getMessage());
        }

        try {
            $w->finish();
            self::fail('finish() must be rejected after a partial entry failure');
        } catch (WriteException $e) {
            self::assertStringContainsString('failed state', $e->getMessage());
        }

        @unlink($file);
    }

    public function testCPlusBStreamProducesValidArchive(): void
    {
        // 'c+b' is not an append mode: fseek() is honored and bytes may be
        // replaced, so the seekable patch strategy works end to end.
        $file = $this->tempFile('zip');
        $stream = fopen($file, 'c+b');
        self::assertIsResource($stream);

        $writer = new DirectZipWriter($stream);
        self::assertTrue($writer->isSeekable(), 'c+b mode must allow seeking and patching');
        $writer->addString('stored.txt', "stored content\n", store: true);
        $writer->finish();
        fclose($stream);

        self::assertSame("stored content\n", $this->readEntry($file, 'stored.txt'));
        @unlink($file);
    }

    public function testFinalizationWriteFailureMarksWriterFailed(): void
    {
        $scheme = 'baresheetfailwrite';
        stream_wrapper_register($scheme, FailingWriteStream::class);

        try {
            $stream = fopen($scheme . '://zip', 'w+b');
            self::assertIsResource($stream);

            $writer = new DirectZipWriter($stream);
            $writer->addString('a.txt', 'AAA');

            $wrapper = stream_get_meta_data($stream)['wrapper_data'];
            self::assertInstanceOf(FailingWriteStream::class, $wrapper);
            $wrapper->exhaustBudget();

            // The first central-directory write must fail and poison the writer.
            try {
                $writer->finish();
                self::fail('finish() must report a failed central-directory write');
            } catch (WriteException $e) {
                self::assertStringContainsString('write', $e->getMessage());
            }

            try {
                $writer->addString('late.txt', 'too late');
                self::fail('addString() must be rejected after a failed finalization');
            } catch (WriteException $e) {
                self::assertStringContainsString('failed state', $e->getMessage());
            }

            try {
                $writer->finish();
                self::fail('finish() must be rejected after a failed finalization');
            } catch (WriteException $e) {
                self::assertStringContainsString('failed state', $e->getMessage());
            }
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function testExact65535EntriesUsesClassicEocd(): void
    {
        // 65535 is both the classic EOCD capacity and the ZIP64 sentinel value.
        // An archive with exactly that many entries must stay classic (no ZIP64
        // records) and remain readable by an independent reader.
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        for ($i = 0; $i < 65_535; $i++) {
            $w->addString("e{$i}.txt", "entry {$i}");
        }
        $w->finish();

        $raw = (string) file_get_contents($file);
        self::assertFalse(
            str_contains($raw, pack('V', 0x0706_4b50)),
            'a 65535-entry archive must not carry a ZIP64 locator',
        );

        $eocd = substr($raw, -22);
        self::assertSame(0x0605_4b50, unpack('V', substr($eocd, 0, 4))[1]);
        self::assertSame(65_535, unpack('v', substr($eocd, 8, 2))[1], 'classic entries-on-disk count');
        self::assertSame(65_535, unpack('v', substr($eocd, 10, 2))[1], 'classic total entry count');

        self::assertSame(65_535, $this->entryCount($file));
        self::assertSame('entry 0', $this->readEntry($file, 'e0.txt'));
        self::assertSame('entry 65534', $this->readEntry($file, 'e65534.txt'));
        @unlink($file);
    }

    public function testInvalidCompressionLevelThrows(): void
    {
        $file = $this->tempFile('zip');
        $stream = fopen($file, 'w+b');
        $this->expectException(WriteException::class);
        new DirectZipWriter($stream, compressionLevel: 42);
        fclose($stream);
        @unlink($file);
    }

    public function testClassicEntryWritesClassicHeaderWithGrowthHint(): void
    {
        // A small entry must stay a classic ZIP entry (version 20, real 32-bit
        // sizes) and carry the reserved OPC growth-hint extra field.
        $file = $this->tempFile('zip');
        $w = DirectZipWriter::create($file);
        $w->addString('data.bin', 'payload');
        $w->finish();

        $raw = (string) file_get_contents($file);
        $version = unpack('v', substr($raw, 4, 2))[1];
        $crcField = unpack('V', substr($raw, 14, 4))[1];
        $compressedField = unpack('V', substr($raw, 18, 4))[1];
        $uncompressedField = unpack('V', substr($raw, 22, 4))[1];
        $extraLen = unpack('v', substr($raw, 28, 2))[1];
        $extraTag = unpack('v', substr($raw, 30 + strlen('data.bin'), 2))[1];

        self::assertSame(20, $version, 'classic entry must declare version 2.0');
        self::assertSame(strlen('payload'), $uncompressedField, 'uncompressed size must be real');
        self::assertNotSame(0xFFFF_FFFF, $compressedField, 'classic sizes must not use the ZIP64 sentinel');
        self::assertSame(20, $extraLen, 'the 20-byte reserved extra field must be present');
        self::assertSame(0xA220, $extraTag, 'the reserved field must be the OPC growth hint');

        @unlink($file);
    }

    public function testLocalHeaderMetadataClassic(): void
    {
        [$version, $zip64, $sizePatch, $extraPatch] = DirectZipWriter::localHeaderMetadata(0xDEAD_BEEF, 1024, 2048);

        self::assertSame(20, $version);
        self::assertFalse($zip64);
        self::assertSame(0xDEAD_BEEF, unpack('V', substr($sizePatch, 0, 4))[1]);
        self::assertSame(1024, unpack('V', substr($sizePatch, 4, 4))[1]);
        self::assertSame(2048, unpack('V', substr($sizePatch, 8, 4))[1]);
        self::assertNull($extraPatch, 'classic entries keep the growth hint, no ZIP64 field');
    }

    public function testLocalHeaderMetadataZip64Boundary(): void
    {
        // Synthetic ZIP64 decision at the 4 GiB boundary, without a real payload.
        [$version, $zip64, $sizePatch, $extraPatch] = DirectZipWriter::localHeaderMetadata(
            123,
            0x1_0000_0000, // > 4 GiB compressed
            456,
        );

        self::assertSame(45, $version, 'ZIP64 entries must declare version 4.5');
        self::assertTrue($zip64);
        self::assertSame(0xFFFF_FFFF, unpack('V', substr($sizePatch, 4, 4))[1], 'compressed sentinel');
        self::assertSame(0xFFFF_FFFF, unpack('V', substr($sizePatch, 8, 4))[1], 'uncompressed sentinel');

        self::assertNotNull($extraPatch);
        self::assertSame(0x0001, unpack('v', substr($extraPatch, 0, 2))[1], 'ZIP64 extra field id');
        self::assertSame(16, unpack('v', substr($extraPatch, 2, 2))[1], 'ZIP64 extra field size');
        self::assertSame(456, unpack('P', substr($extraPatch, 4, 8))[1], 'ZIP64 uncompressed size');
        self::assertSame(0x1_0000_0000, unpack('P', substr($extraPatch, 12, 8))[1], 'ZIP64 compressed size');
    }

    public function testLocalHeaderMetadataZip64ExactLimit(): void
    {
        // 0xFFFF_FFFF itself fits in classic ZIP; the next value requires ZIP64.
        // Both sizes at the exact sentinel must stay real 32-bit values, not be
        // mistaken for ZIP64 markers.
        [$version, $zip64, $sizePatch] = DirectZipWriter::localHeaderMetadata(0, 0xFFFF_FFFF, 0xFFFF_FFFF);
        self::assertSame(20, $version);
        self::assertFalse($zip64);
        self::assertSame(0xFFFF_FFFF, unpack('V', substr($sizePatch, 4, 4))[1]);
        self::assertSame(0xFFFF_FFFF, unpack('V', substr($sizePatch, 8, 4))[1]);

        [$versionZip64, $zip64True] = DirectZipWriter::localHeaderMetadata(0, 0x1_0000_0000, 0);
        self::assertSame(45, $versionZip64);
        self::assertTrue($zip64True);
    }
}
