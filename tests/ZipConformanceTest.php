<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Tests\Support\ZipConformance;
use LeKoala\Baresheet\XlsxWriter;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Container-level conformance of every archive the writers can emit.
 *
 * Third-party readers accept far more than Excel does: an archive can be read
 * back by ZipArchive, OpenSpout and SimpleXLSX and still make Excel offer to
 * repair it. These tests assert the container itself, independently of the XML
 * it carries, so a packaging regression fails here rather than at a user's desk.
 */
class ZipConformanceTest extends TestCase
{
    /** Mixed types, significant whitespace and non-ASCII, to exercise shared strings and deflate. */
    private const ROWS = [
        ['id', 'label', 'amount', 'flag'],
        [1, 'Alice', 12.5, true],
        [2, '  leading and trailing  ', -3.25, false],
        [3, 'accentué € 漢字', 0, null],
    ];

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function emitters(): array
    {
        $cases = [];
        foreach (['xlsx', 'ods'] as $format) {
            foreach (['writeString', 'writeStream', 'writeFile', 'output', 'outputStream'] as $method) {
                $cases["{$format} via {$method}"] = [$format, $method];
            }
        }

        return $cases;
    }

    /**
     * Both HTTP output paths belong here, for both formats.
     *
     * These cover the two ways a workbook reaches a client, which produce
     * different containers for the same rows: output() with $stream = false
     * buffers and can therefore patch each header in place, while the streaming
     * path defers crc and sizes to a trailing data descriptor because it can
     * never seek back. Excel accepts both. What it refuses, in every form, is
     * ZIP64 — so neither path may promote to it, and that is what this asserts.
     */
    #[DataProvider('emitters')]
    public function testEmittedArchiveStaysInTheClassicZipSubset(string $format, string $method): void
    {
        $bytes = $this->emit($format, $method, self::ROWS);

        self::assertConformant($bytes);
    }

    /**
     * The streamed sheet path writes entries whose size is only known at the
     * end, which is exactly where proactive ZIP64 crept in before 0.7.1.
     */
    #[DataProvider('formats')]
    public function testStreamedSheetStaysInTheClassicZipSubset(string $format): void
    {
        $rows = [['id', 'label', 'amount']];
        for ($i = 1; $i <= 5000; $i++) {
            $rows[] = [$i, "row {$i} accentué", $i / 7];
        }

        self::assertConformant($this->emit($format, 'writeFile', $rows));
    }

    #[DataProvider('formats')]
    public function testPackageCarriesItsEntryPoints(string $format): void
    {
        $expected = $format === 'ods'
            ? ['mimetype', 'META-INF/manifest.xml', 'content.xml']
            : ['[Content_Types].xml', '_rels/.rels', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml'];

        $names = ZipConformance::entryNames($this->emit($format, 'writeString', self::ROWS));

        foreach ($expected as $name) {
            self::assertContains($name, $names);
        }
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function formats(): array
    {
        return ['xlsx' => ['xlsx'], 'ods' => ['ods']];
    }

    /**
     * The defect that shipped, kept reproducible.
     *
     * No writer emits ZIP64 for an ordinary workbook any more, so the archive
     * Excel refused has to be built by hand here. That is the better test: it
     * measures the checker against the shape of the bug rather than against a
     * branch that may be rewritten, and it keeps working once nothing in the
     * library can produce that shape at all.
     */
    public function testCheckerRejectsAZip64Archive(): void
    {
        $bytes = self::zip64Archive('xl/worksheets/sheet1.xml', str_repeat('<row r="1"/>', 200));

        // ZipArchive reads this archive without complaint; Excel does not.
        $archive = new \ZipArchive();
        $file = $this->tempFile('zip');
        self::assertNotFalse(file_put_contents($file, $bytes));
        self::assertTrue($archive->open($file, \ZipArchive::CHECKCONS));
        $archive->close();
        unlink($file);

        $violations = implode("\n", ZipConformance::violations($bytes));

        self::assertStringContainsString('ZIP64 extra field', $violations);
        self::assertStringContainsString('version 45 to extract', $violations);
        self::assertStringContainsString('ZIP64 end-of-central-directory', $violations);
    }

    /**
     * A one-entry archive using ZIP64 everywhere it can: sentinels and 0x0001
     * extra fields in both headers, plus ZIP64 end records the classic one
     * points at. Stored, so the payload needs no compression.
     */
    private static function zip64Archive(string $name, string $contents): string
    {
        $crc = crc32($contents);
        $size = strlen($contents);
        $sentinel = 0xFFFF_FFFF;

        $localExtra = pack('vv', 0x0001, 16) . pack('PP', $size, $size);
        $local =
            pack(
                'VvvvvvVVVvv',
                0x0403_4b50,
                45,
                0x0800,
                0,
                0,
                0,
                $crc,
                $sentinel,
                $sentinel,
                strlen($name),
                strlen($localExtra),
            )
            . $name
            . $localExtra
            . $contents;

        $centralExtra = pack('vv', 0x0001, 24) . pack('PPP', $size, $size, 0);
        $central =
            pack(
                'VvvvvvvVVVvvvvvVV',
                0x0201_4b50,
                45,
                45,
                0x0800,
                0,
                0,
                0,
                $crc,
                $sentinel,
                $sentinel,
                strlen($name),
                strlen($centralExtra),
                0,
                0,
                0,
                0,
                $sentinel,
            )
            . $name
            . $centralExtra;

        $cdOffset = strlen($local);
        $cdSize = strlen($central);

        $end = pack('VPvvVVPPPP', 0x0606_4b50, 44, 45, 45, 0, 0, 1, 1, $cdSize, $cdOffset);
        $end .= pack('VVPV', 0x0706_4b50, 0, $cdOffset + $cdSize, 1);
        $end .= pack('VvvvvVVv', 0x0605_4b50, 0, 0, 1, 1, $cdSize, $sentinel, 0);

        return $local . $central . $end;
    }

    public function testCheckerRejectsBytesAppendedAfterTheEndRecord(): void
    {
        $bytes = $this->emit('xlsx', 'writeString', self::ROWS) . 'trailing';

        self::assertContains(
            '8 byte(s) follow the end-of-central-directory record',
            ZipConformance::violations($bytes),
        );
    }

    private static function assertConformant(string $bytes): void
    {
        $violations = ZipConformance::violations($bytes);

        self::assertSame(
            [],
            $violations,
            "ZIP container is outside the classic subset:\n" . implode("\n", $violations),
        );
    }

    /**
     * @param iterable<array<mixed>> $rows
     */
    private function emit(string $format, string $method, iterable $rows): string
    {
        $writer = $format === 'ods' ? new OdsWriter() : new XlsxWriter();
        $filename = "conformance.{$format}";

        switch ($method) {
            case 'writeString':
                return $writer->writeString($rows);

            case 'writeStream':
                $stream = $writer->writeStream($rows);
                rewind($stream);
                $bytes = stream_get_contents($stream);
                fclose($stream);
                self::assertIsString($bytes);
                return $bytes;

            case 'writeFile':
                $file = $this->tempFile($format);
                self::assertTrue($writer->writeFile($rows, $file));
                $bytes = file_get_contents($file);
                unlink($file);
                self::assertIsString($bytes);
                return $bytes;

            case 'output':
                return $this->capture(static fn() => $writer->output($rows, $filename));

            case 'outputStream':
                return $this->capture(static fn() => $writer->outputStream($rows, $filename));

            default:
                self::fail("Unknown emitter '{$method}'");
        }
    }

    private function capture(callable $emit): string
    {
        ob_start();
        try {
            $emit();
            $bytes = ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        self::assertIsString($bytes);
        return $bytes;
    }
}
