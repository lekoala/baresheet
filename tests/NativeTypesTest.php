<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use DateTimeImmutable;
use DateTimeZone;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Value\TimeValue;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

/**
 * Core contract of the native-type evolution: readers preserve the semantic
 * value type of the source, writers preserve PHP value types, and a full
 * read -> write -> read round trip preserves both types and values.
 *
 * These tests run in native mode explicitly (stringifyValues=false,
 * inferNumericStrings=false), independent of the interim BC defaults.
 */
class NativeTypesTest extends TestCase
{
    /**
     * @return array{0: list<array{0: string, 1: mixed}>}
     */
    private function matrix(): array
    {
        return [
            ['number_int', 123],
            ['number_decimal', 12.5],
            ['text_numeric', '123'],
            ['leading_zero', '00123'],
            ['boolean', true],
            ['date', new DateTimeImmutable('2026-08-13 00:00:00', new DateTimeZone('UTC'))],
            ['datetime', new DateTimeImmutable('2026-08-13 14:30:15', new DateTimeZone('UTC'))],
            ['time', new TimeValue(14, 30, 15)],
            ['empty', null],
            ['datetime_micros', new DateTimeImmutable('2026-08-13 14:30:15.123456', new DateTimeZone('UTC'))],
        ];
    }

    public function testXlsxNativeRoundTrip(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->inferNumericStrings = false;
        $writer->writeFile($this->matrix(), $tempFile);

        $reader = new XlsxReader();
        $reader->stringifyValues = false;
        $first = iterator_to_array($reader->readFile($tempFile));
        $this->assertTypedMatrix($first);

        // Second leg: write the typed values again, read again.
        $writer2 = new XlsxWriter();
        $writer2->inferNumericStrings = false;
        $writer2->writeFile($first, $tempFile);
        $second = iterator_to_array($reader->readFile($tempFile));
        $this->assertTypedMatrix($second);

        unlink($tempFile);
    }

    public function testOdsNativeRoundTrip(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->inferNumericStrings = false;
        $writer->writeFile($this->matrix(), $tempFile);

        $reader = new OdsReader();
        $reader->stringifyValues = false;
        $first = iterator_to_array($reader->readFile($tempFile));
        $this->assertTypedMatrix($first);

        $writer2 = new OdsWriter();
        $writer2->inferNumericStrings = false;
        $writer2->writeFile($first, $tempFile);
        $second = iterator_to_array($reader->readFile($tempFile));
        $this->assertTypedMatrix($second);

        unlink($tempFile);
    }

    /**
     * @param array<int, array{0: string, 1: mixed}> $rows
     */
    private function assertTypedMatrix(array $rows): void
    {
        self::assertCount(10, $rows);

        self::assertSame(123, $rows[0][1]);
        self::assertSame(12.5, $rows[1][1]);
        self::assertSame('123', $rows[2][1]);
        self::assertSame('00123', $rows[3][1]);
        self::assertSame(true, $rows[4][1]);

        $date = $rows[5][1];
        self::assertInstanceOf(DateTimeImmutable::class, $date);
        self::assertSame('2026-08-13', $date->format('Y-m-d'));

        $datetime = $rows[6][1];
        self::assertInstanceOf(DateTimeImmutable::class, $datetime);
        self::assertSame('2026-08-13 14:30:15', $datetime->format('Y-m-d H:i:s'));

        $time = $rows[7][1];
        self::assertInstanceOf(TimeValue::class, $time);
        self::assertSame(52_215_000_000, $time->toMicroseconds());

        self::assertTrue($rows[8][1] === null || $rows[8][1] === '');

        $datetimeMicros = $rows[9][1];
        self::assertInstanceOf(DateTimeImmutable::class, $datetimeMicros);
        self::assertSame('2026-08-13 14:30:15.123456', $datetimeMicros->format('Y-m-d H:i:s.u'));
    }

    public function testXlsxNativeNumberTextRoundTrip(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->inferNumericStrings = false;
        $writer->writeFile([['n', 't'], [123, '123']], $tempFile);

        $reader = new XlsxReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($tempFile));
        self::assertSame(['n', 't'], $rows[0]);
        self::assertSame(123, $rows[1][0]);
        self::assertSame('123', $rows[1][1]);

        unlink($tempFile);
    }

    public function testXlsxStringifyCompatMode(): void
    {
        // stringifyValues reproduces the legacy CSV-like strings.
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->inferNumericStrings = false;
        $writer->writeFile($this->matrix(), $tempFile);

        $reader = new XlsxReader();
        $reader->stringifyValues = true;
        $rows = iterator_to_array($reader->readFile($tempFile));

        self::assertSame('123', $rows[0][1]);
        self::assertSame('12.5', $rows[1][1]);
        self::assertSame('123', $rows[2][1]);
        self::assertSame('00123', $rows[3][1]);
        self::assertSame('1', $rows[4][1]);
        self::assertSame('2026-08-13', $rows[5][1]);
        self::assertSame('2026-08-13 14:30:15', $rows[6][1]);
        self::assertSame('14:30:15', $rows[7][1]);

        unlink($tempFile);
    }

    public function testNativeReadsRealDateFixture(): void
    {
        $reader = new XlsxReader();
        $reader->stringifyValues = false;
        $reader->assoc = true;
        $data = array_values(iterator_to_array($reader->readFile(__DIR__ . '/data/date.xlsx')));

        $date = static fn(mixed $v): string => $v instanceof DateTimeImmutable ? $v->format('Y-m-d') : '';
        self::assertSame('2016-10-14', $date($data[0]['BirthDate']));
        self::assertSame('1545-01-15', $date($data[1]['BirthDate']));
        self::assertSame('2955-12-10', $date($data[2]['BirthDate']));
        self::assertSame('1242-09-16', $date($data[3]['BirthDate']));
        self::assertSame('1742-09-16', $date($data[4]['BirthDate']));
        self::assertSame('1900-09-16', $date($data[5]['BirthDate']));
        self::assertSame('1899-09-16', $date($data[6]['BirthDate']));
        self::assertSame('4111-09-16', $date($data[7]['BirthDate']));
        // Cells without a value and invalid dates stay strings.
        self::assertSame('', $data[8]['BirthDate']);
        self::assertSame('00/00/0000', $data[9]['BirthDate']);

        $created = $data[0]['Created'];
        self::assertInstanceOf(DateTimeImmutable::class, $created);
        self::assertSame('2025-01-01 10:00:00', $created->format('Y-m-d H:i:s'));

        $bestTime = $data[0]['BestTime'];
        self::assertInstanceOf(TimeValue::class, $bestTime);
        self::assertSame(10 * 3_600_000_000, $bestTime->toMicroseconds());
    }

    public function testNativeReads1904Fixture(): void
    {
        $reader = new XlsxReader();
        $reader->stringifyValues = false;
        $data = iterator_to_array($reader->readFile(__DIR__ . '/data/date-1904.xlsx'));

        $fmt = static fn(mixed $v): string => $v instanceof DateTimeImmutable ? $v->format('Y-m-d H:i:s') : '';
        self::assertSame('2019-09-02 00:00:00', $fmt($data[0][0]));
        self::assertSame('2019-09-03 00:00:00', $fmt($data[0][1]));
        self::assertSame('2019-09-02 22:23:00', $fmt($data[0][2]));
        self::assertSame('1904-02-29 23:59:59', $fmt($data[1][0]));
        self::assertSame('1904-03-02 00:00:00', $fmt($data[1][1]));
        self::assertSame('1904-03-01 11:00:00', $fmt($data[1][2]));
    }

    /**
     * Build a minimal ODS archive containing the given content.xml.
     * The Baresheet ODS reader only reads content.xml.
     */
    private function odsWithContent(string $contentXml): string
    {
        $file = $this->tempFile('ods');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('content.xml', $contentXml);
        $zip->close();
        return $file;
    }

    public function testOdsStringifyPreservesLegacyLexicalForms(): void
    {
        $file = $this->odsWithContent(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
                xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
                xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
                office:version="1.3">
              <office:body><office:spreadsheet>
                <table:table table:name="Sheet1">
                  <table:table-row>
                    <table:table-cell office:value-type="date" office:date-value="2026-08-13T14:30:15"><text:p>2026-08-13 14:30:15</text:p></table:table-cell>
                    <table:table-cell office:value-type="time" office:time-value="PT14H30M15S"><text:p>14:30:15</text:p></table:table-cell>
                    <table:table-cell office:value-type="float" office:value="42"><text:p>42</text:p></table:table-cell>
                  </table:table-row>
                </table:table>
              </office:spreadsheet></office:body>
            </office:document-content>
            XML);

        $reader = new OdsReader();
        $reader->stringifyValues = true;
        $rows = iterator_to_array($reader->readFile($file));
        self::assertSame(['2026-08-13T14:30:15', 'PT14H30M15S', '42'], $rows[0]);

        unlink($file);
    }

    public function testOdsDistinguishesTimeOfDayFromDurationUnder24h(): void
    {
        $file = $this->odsWithContent(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
                xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
                xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
                xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
                xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"
                office:version="1.3">
              <office:automatic-styles>
                <style:style style:name="ce-time" style:family="table-cell" style:data-style-name="timeOfDay"/>
                <style:style style:name="ce-duration" style:family="table-cell" style:data-style-name="durationTime"/>
                <number:time-style style:name="timeOfDay">
                  <number:hours number:style="long"/><number:text>:</number:text>
                  <number:minutes number:style="long"/><number:text>:</number:text>
                  <number:seconds number:style="long"/>
                </number:time-style>
                <number:time-style style:name="durationTime" number:truncate-on-overflow="false">
                  <number:hours number:style="long"/><number:text>:</number:text>
                  <number:minutes number:style="long"/><number:text>:</number:text>
                  <number:seconds number:style="long"/>
                </number:time-style>
              </office:automatic-styles>
              <office:body><office:spreadsheet>
                <table:table table:name="Sheet1">
                  <table:table-row>
                    <table:table-cell table:style-name="ce-time" office:value-type="time" office:time-value="PT12H"><text:p>12:00:00</text:p></table:table-cell>
                    <table:table-cell table:style-name="ce-duration" office:value-type="time" office:time-value="PT12H"><text:p>12:00:00</text:p></table:table-cell>
                  </table:table-row>
                </table:table>
              </office:spreadsheet></office:body>
            </office:document-content>
            XML);

        $reader = new OdsReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($file));

        self::assertInstanceOf(TimeValue::class, $rows[0][0]);
        self::assertSame(12 * 3_600_000_000, $rows[0][0]->toMicroseconds());

        // A duration style marks an elapsed duration even under 24 hours.
        $duration = $rows[0][1];
        self::assertInstanceOf(\Time\Duration::class, $duration);
        self::assertSame(43_200, $duration->seconds);
        self::assertSame(0, $duration->nanoseconds);
        self::assertFalse($duration->negative);

        unlink($file);
    }

    public function testOdsNegativeTimeWithoutDurationStyleIsDuration(): void
    {
        // A negative time value cannot be a time of day: it must be a duration,
        // even when no recognizable duration style is present (external files).
        $file = $this->odsWithContent(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
                xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
                xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
                office:version="1.3">
              <office:body><office:spreadsheet>
                <table:table table:name="Sheet1">
                  <table:table-row>
                    <table:table-cell office:value-type="time" office:time-value="-PT1H"><text:p>-01:00:00</text:p></table:table-cell>
                  </table:table-row>
                </table:table>
              </office:spreadsheet></office:body>
            </office:document-content>
            XML);

        $reader = new OdsReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($file));

        self::assertInstanceOf(\Time\Duration::class, $rows[0][0]);
        self::assertTrue($rows[0][0]->negative);
        self::assertSame(3_600, $rows[0][0]->seconds);

        unlink($file);
    }

    public function testXlsxDurationRoundTrip(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->inferNumericStrings = false;
        $writer->writeFile([[\Time\Duration::fromMicroseconds(131_415_000_000)]], $tempFile);

        $reader = new XlsxReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($tempFile));
        self::assertInstanceOf(\Time\Duration::class, $rows[0][0]);
        self::assertSame(131_415, $rows[0][0]->seconds);
        self::assertSame(0, $rows[0][0]->nanoseconds);
        self::assertFalse($rows[0][0]->negative);

        unlink($tempFile);
    }

    public function testOdsDurationRoundTrip(): void
    {
        $tempFile = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->inferNumericStrings = false;
        $writer->writeFile([[\Time\Duration::fromMicroseconds(131_415_000_000)]], $tempFile);

        $reader = new OdsReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($tempFile));
        self::assertInstanceOf(\Time\Duration::class, $rows[0][0]);
        self::assertSame(131_415, $rows[0][0]->seconds);
        self::assertSame(0, $rows[0][0]->nanoseconds);
        self::assertFalse($rows[0][0]->negative);

        unlink($tempFile);
    }
}
