<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use DateTimeImmutable;
use DateTimeZone;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Value\DurationValue;
use LeKoala\Baresheet\Value\TimeValue;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

/**
 * Core contract of the native-type evolution.
 *
 * Baresheet preserves fundamental spreadsheet value kinds where PHP has a
 * natural representation. Date and datetime values use `DateTimeImmutable`;
 * time-of-day and duration values are exposed as canonical strings.
 * Spreadsheet formatting semantics are not guaranteed to survive a generic
 * read/write round-trip.
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

        // Time-of-day cells come back as canonical strings, not TimeValue objects.
        self::assertSame('14:30:15', $rows[7][1]);

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

        self::assertSame('10:00:00', $data[0]['BestTime']);
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

    /**
     * Build a minimal XLSX archive containing the given worksheet XML.
     * The Baresheet XLSX reader falls back to xl/worksheets/sheet1.xml when
     * no sheet is selected, so this is enough for a focused cell test.
     */
    private function xlsxWithWorksheet(string $sheetXml): string
    {
        $file = $this->tempFile('xlsx');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();
        return $file;
    }

    public function testXlsxTypedDateCellDecodesToDateTimeImmutable(): void
    {
        $file = $this->xlsxWithWorksheet(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
              <sheetData>
                <row r="1">
                  <c r="A1" t="d"><v>1976-11-22T08:30:00Z</v></c>
                  <c r="B1" t="d"><v>1976-11-22T08:30:00+02:00</v></c>
                </row>
              </sheetData>
            </worksheet>
            XML);

        $reader = new XlsxReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($file));

        self::assertInstanceOf(DateTimeImmutable::class, $rows[0][0]);
        self::assertSame('1976-11-22 08:30:00', $rows[0][0]->format('Y-m-d H:i:s'));
        self::assertSame('+00:00', $rows[0][0]->format('P'));

        // Offsets are validated but neutralized: civil components are kept,
        // the timezone is dropped (08:30+02:00 reads back as 08:30 UTC).
        self::assertInstanceOf(DateTimeImmutable::class, $rows[0][1]);
        self::assertSame('1976-11-22 08:30:00', $rows[0][1]->format('Y-m-d H:i:s'));
        self::assertSame('+00:00', $rows[0][1]->format('P'));

        unlink($file);
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

        // Both a time of day and an elapsed duration under 24 hours surface as
        // the same canonical string; the ODS style distinction no longer maps
        // to a distinct PHP type.
        self::assertSame('12:00:00', $rows[0][0]);
        self::assertSame('12:00:00', $rows[0][1]);

        unlink($file);
    }

    public function testOdsTimeNormalizesOverflowComponents(): void
    {
        // External ODS files may carry PT90M instead of PT1H30M; the reader
        // normalizes out-of-range components instead of echoing them.
        $file = $this->odsWithContent(<<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
                xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"
                xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"
                office:version="1.3">
              <office:body><office:spreadsheet>
                <table:table table:name="Sheet1">
                  <table:table-row>
                    <table:table-cell office:value-type="time" office:time-value="PT90M"><text:p>90:00</text:p></table:table-cell>
                    <table:table-cell office:value-type="time" office:time-value="PT1H90M90.5S"><text:p>1:90:90.5</text:p></table:table-cell>
                  </table:table-row>
                </table:table>
              </office:spreadsheet></office:body>
            </office:document-content>
            XML);

        $reader = new OdsReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($file));

        self::assertSame('01:30:00', $rows[0][0]);
        self::assertSame('02:31:30.500000', $rows[0][1]);

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

        // A negative time is always a duration, surfaced as a canonical string.
        self::assertSame('-1:00:00', $rows[0][0]);

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
        // Durations come back as canonical strings; Time\Duration is a writer-only marker.
        self::assertSame('36:30:15', $rows[0][0]);

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
        self::assertSame('36:30:15', $rows[0][0]);

        unlink($tempFile);
    }

    public function testNumberIntFloatDistinctionIsNotRoundTripMetadata(): void
    {
        $tempFile = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->inferNumericStrings = false;
        $writer->writeFile([['int' => 12, 'float' => 12.0, 'decimal' => 12.5]], $tempFile);

        $reader = new XlsxReader();
        $reader->stringifyValues = false;
        $rows = iterator_to_array($reader->readFile($tempFile));

        // The spreadsheet has one Number type; int vs float is not round-trip metadata.
        self::assertSame(['int', 'float', 'decimal'], $rows[0]);
        self::assertSame(12, $rows[1][0]);
        self::assertSame(12, $rows[1][1]);
        self::assertSame(12.5, $rows[1][2]);

        unlink($tempFile);
    }

    public function testDurationValueRoundTrip(): void
    {
        foreach (['xlsx', 'ods'] as $ext) {
            $tempFile = $this->tempFile($ext);
            $writer = $ext === 'xlsx' ? new XlsxWriter() : new OdsWriter();
            $writer->inferNumericStrings = false;
            $writer->writeFile([[new DurationValue(36, 30, 15)]], $tempFile);

            $reader = $ext === 'xlsx' ? new XlsxReader() : new OdsReader();
            $reader->stringifyValues = false;
            $rows = iterator_to_array($reader->readFile($tempFile));
            self::assertSame('36:30:15', $rows[0][0], $ext);

            unlink($tempFile);
        }
    }
}
