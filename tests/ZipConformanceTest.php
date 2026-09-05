<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Internal\DirectZipWriter;
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
     * ODS streamed straight to php://output until this test was written, which
     * gave every entry a proactive ZIP64 header. Excel 365 refuses such an .ods
     * exactly as it refuses such an .xlsx, so ODS now buffers to a seekable
     * stream the way XLSX has since 0.7.1. The ODF import filter is no more
     * tolerant of ZIP64 than the OPC layer is.
     *
     * Buffering is how that was fixed, not why it had to be: ZIP64 was the
     * fatal part on its own, and a non-seekable output could stay classic
     * instead, since Excel accepts a trailing data descriptor. Either writer
     * may go back to streaming as long as it emits no ZIP64.
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
     * DirectZipWriter still has a non-seekable branch that advertises ZIP64 up
     * front. No public writer entry point reaches it since the writers build on
     * a seekable temporary stream first, but the branch remains capable of
     * producing the archive Excel refused. This proves the checker sees it, so
     * the guarantee does not rest on that routing alone.
     */
    public function testCheckerRejectsTheProactiveZip64OfANonSeekableStream(): void
    {
        $bytes = $this->capture(static function (): void {
            $stream = fopen('php://output', 'wb');
            self::assertIsResource($stream);

            $writer = new DirectZipWriter($stream);
            self::assertFalse($writer->isSeekable(), 'php://output under an output buffer must be non-seekable');
            $writer->addString('xl/worksheets/sheet1.xml', str_repeat('<row r="1"/>', 200));
            $writer->finish();
            fclose($stream);
        });

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
