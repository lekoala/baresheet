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
        self::assertCount(9, $rows);

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
}
