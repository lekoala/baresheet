<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use DateTimeImmutable;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Value\DurationValue;
use LeKoala\Baresheet\Value\TimeValue;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

class XlsxColumnFormatsTest extends TestCase
{
    public function testTextColumnPreservesPlusAndLeadingZero(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter(new Options(columnFormats: ['A' => '@', 'B' => '@']));
        $writer->writeFile([
            ['+972543912345', '0123'],
        ], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $styles = (string) $zip->getFromName('xl/styles.xml');
            $zip->close();

            self::assertStringContainsString('numFmtId="49"', $styles);
            self::assertStringContainsString('<c r="A1" t="inlineStr" s="5">', $sheet);
            self::assertStringContainsString('+972543912345', $sheet);
            self::assertStringNotContainsString('<v>+972543912345</v>', $sheet);

            $reader = new XlsxReader();
            $data = iterator_to_array($reader->readFile($tempFile));
            self::assertSame('+972543912345', $data[0][0]);
            self::assertSame('0123', $data[0][1]);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testTextFormatForcesNumericValuesToText(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter(new Options(columnFormats: [0 => '@']));
        $writer->writeFile([[12_345], ['67890'], [12.5]], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            self::assertStringNotContainsString('t="n"', $sheet);
            self::assertStringContainsString('t="inlineStr"', $sheet);

            $reader = new XlsxReader();
            $data = iterator_to_array($reader->readFile($tempFile));
            self::assertSame('12345', (string) $data[0][0]);
            self::assertSame('67890', (string) $data[1][0]);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testTextFormatForcesNativeValuesToText(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter(new Options(columnFormats: [0 => '@']));
        $writer->writeFile([
            [true],
            [false],
            [new DateTimeImmutable('2026-09-25 14:30:15')],
            [new TimeValue(9, 30, 5)],
            [new DurationValue(36, 15)],
        ], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            self::assertStringNotContainsString('t="b"', $sheet);
            self::assertStringNotContainsString('t="n"', $sheet);
            self::assertSame(5, substr_count($sheet, 't="inlineStr"'));

            $reader = new XlsxReader();
            $data = iterator_to_array($reader->readFile($tempFile));
            self::assertSame(
                ['1', '0', '2026-09-25 14:30:15', '09:30:05', '36:15:00'],
                array_column($data, 0),
            );
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testNumericFormatKeepsNumericCells(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter(new Options(columnFormats: ['A' => '0.00']));
        $writer->writeFile([[1234.5]], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $styles = (string) $zip->getFromName('xl/styles.xml');
            $zip->close();

            self::assertStringContainsString('formatCode="0.00"', $styles);
            self::assertStringContainsString('<c r="A1" t="n" s="5">', $sheet);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testBoldHeadersCombineWithFormat(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter(new Options(boldHeaders: true, columnFormats: ['A' => '@']));
        $writer->writeFile([['phone'], ['+972543912345']], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            // Header uses the bold+text variant (s=6), body the plain text variant (s=5).
            self::assertStringContainsString('<c r="A1" t="inlineStr" s="6">', $sheet);
            self::assertStringContainsString('<c r="A2" t="inlineStr" s="5">', $sheet);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testColStyleMergedWithAutoWidth(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter(new Options(autoWidth: true, columnFormats: ['A' => '@']));
        $writer->writeFile([['+972543912345']], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            self::assertMatchesRegularExpression('/<col min="1" max="1"[^>]*style="5"[^>]*\/>/', $sheet);
            self::assertStringContainsString('customWidth="true"', $sheet);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testWriteStringStreamingKeepsText(): void
    {
        $writer = new XlsxWriter(new Options(columnFormats: ['A' => '@']));
        $xlsx = $writer->writeString([['+972543912345']]);

        $tempFile = $this->tempFile('xlsx');
        file_put_contents($tempFile, $xlsx);
        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            self::assertStringContainsString('t="inlineStr"', $sheet);
            self::assertStringContainsString('+972543912345', $sheet);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testInvalidColumnFormatsThrow(): void
    {
        foreach ([['XFE' => '@'], [-1 => '@'], ['A' => '']] as $bad) {
            try {
                new Options(columnFormats: $bad);
                self::fail('columnFormats ' . json_encode($bad) . ' should throw');
            } catch (\InvalidArgumentException) {
                // expected
            }
        }

        $opts = new Options(columnFormats: ['a' => '@', 1 => '0.00']);
        self::assertSame(['a' => '@', 1 => '0.00'], $opts->columnFormats);
    }

    public function testInvalidColumnWidthsKeyThrowsOnWrite(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->columnWidths = ['XFE' => 20];
        try {
            $this->expectException(\InvalidArgumentException::class);
            $writer->writeFile([['a']], $tempFile);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
