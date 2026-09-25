<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use DateTimeImmutable;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Value\DurationValue;
use LeKoala\Baresheet\Value\TimeValue;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

class ForceTextTest extends TestCase
{
    /** @return array<int, bool|float|int|string|DateTimeImmutable|TimeValue|DurationValue|null> */
    private static function values(): array
    {
        return [
            42,
            12.5,
            '0012',
            true,
            false,
            new DateTimeImmutable('2026-09-25 14:30:15'),
            new TimeValue(9, 30, 5),
            new DurationValue(36, 15),
            null,
            '',
        ];
    }

    /** @return list<string> */
    private static function expected(): array
    {
        return ['42', '12.5', '0012', '1', '0', '2026-09-25 14:30:15', '09:30:05', '36:15:00', '', ''];
    }

    public function testXlsxForceTextStoresEveryNonEmptyValueAsText(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter(new Options(forceText: true));
        $writer->writeFile([self::values()], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            self::assertStringNotContainsString('t="n"', $sheet);
            self::assertStringNotContainsString('t="b"', $sheet);
            self::assertSame(8, substr_count($sheet, 't="inlineStr"'));

            $reader = new XlsxReader(new Options(stringifyValues: false, skipEmptyLines: false));
            $data = iterator_to_array($reader->readFile($tempFile));
            self::assertSame(self::expected(), $data[0]);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }

    public function testOdsForceTextStoresEveryNonEmptyValueAsText(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter(new Options(forceText: true));
        $writer->writeFile([self::values()], $tempFile);

        try {
            $zip = new \ZipArchive();
            $zip->open($tempFile);
            $content = (string) $zip->getFromName('content.xml');
            $zip->close();

            self::assertStringNotContainsString('office:value-type="float"', $content);
            self::assertStringNotContainsString('office:value-type="boolean"', $content);
            self::assertStringNotContainsString('office:value-type="date"', $content);
            self::assertStringNotContainsString('office:value-type="time"', $content);
            self::assertSame(8, substr_count($content, 'office:value-type="string"'));

            $reader = new OdsReader(new Options(stringifyValues: false, skipEmptyLines: false));
            $data = iterator_to_array($reader->readFile($tempFile));
            $expected = self::expected();
            $expected[8] = null;
            $expected[9] = null;
            self::assertSame($expected, $data[0]);
        } finally {
            if (is_file($tempFile)) {
                unlink($tempFile);
            }
        }
    }
}
