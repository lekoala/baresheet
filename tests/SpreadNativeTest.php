<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use DateTimeImmutable;
use DateTimeZone;
use LeKoala\Baresheet\Spread;
use LeKoala\Baresheet\Value\TimeValue;
use PHPUnit\Framework\Attributes\DataProvider;

class SpreadNativeTest extends TestCase
{
    #[DataProvider('parseNumericProvider')]
    public function testParseNumericValue(string $value, int|float $expected): void
    {
        self::assertSame($expected, Spread::parseNumericValue($value));
    }

    public static function parseNumericProvider(): array
    {
        return [
            'integer' => ['123', 123],
            'zero' => ['0', 0],
            'negative' => ['-7', -7],
            'negative zero' => ['-0', 0],
            'decimal' => ['12.5', 12.5],
            'trailing zero decimal' => ['12.0', 12.0],
            'exponent' => ['1E+20', 1.0E+20],
            'lowercase exponent' => ['-4.56e-3', -4.56e-3],
            'leading zero becomes float' => ['0012', 12.0],
            'small decimal' => ['0.000000000000001', 1.0e-15],
            'PHP_INT_MAX' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'PHP_INT_MIN' => [(string) PHP_INT_MIN, PHP_INT_MIN],
            'overflow to float' => ['9223372036854775808', 9.223_372_036_854_776e18],
            'underflow to float' => ['-9223372036854775809', -9.223_372_036_854_776e18],
        ];
    }

    public function testNumberCollapseIsNotRoundTripMetadata(): void
    {
        // The spreadsheet has one Number type; the PHP int|float distinction is
        // a chosen representation, not round-trip metadata. These are the exact
        // string forms the writers emit via the (string) cast.
        self::assertSame(12, Spread::parseNumericValue((string) 12.0));
        self::assertSame(12.5, Spread::parseNumericValue((string) 12.5));
        self::assertSame(1000, Spread::parseNumericValue((string) 1e3));
        self::assertSame(0, Spread::parseNumericValue((string) -0.0));
    }

    #[DataProvider('classifyProvider')]
    public function testClassifyNumberFormat(string $format, string $expected): void
    {
        self::assertSame($expected, Spread::classifyNumberFormat($format));
    }

    public static function classifyProvider(): array
    {
        return [
            'date ymd' => ['yyyy-mm-dd', 'date'],
            'date dmy' => ['dd/mm/yyyy', 'date'],
            'date locale' => ['[$-409]yyyy-mm-dd', 'date'],
            'datetime' => ['yyyy-mm-dd hh:mm', 'datetime'],
            'datetime full' => ['yyyy-mm-dd hh:mm:ss', 'datetime'],
            'time hms' => ['hh:mm:ss', 'time'],
            'time ampm' => ['h:mm AM/PM', 'time'],
            'time fractional' => ['h:mm:ss.000', 'time'],
            'duration hours' => ['[h]:mm:ss', 'duration'],
            'duration minutes' => ['[mm]:ss', 'duration'],
            'number general' => ['General', 'number'],
            'number plain' => ['0', 'number'],
            'number currency' => ['"$"#,##0.00', 'number'],
            'number percent' => ['0%', 'number'],
            'number exponent' => ['0.00E+00', 'number'],
            'number quoted literal year' => ['"year" 0', 'number'],
            'number escaped hours' => ['0 \\h', 'number'],
            'number colour section' => ['0;[Red]0', 'number'],
            'week' => ['ww', 'date'],
            'm d y' => ['m/d/yy', 'date'],
        ];
    }

    #[DataProvider('dateToStringProvider')]
    public function testExcelDateToImmutableMatchesLegacy(float $serial): void
    {
        $immutable = Spread::excelDateToImmutable($serial);
        self::assertSame(
            Spread::excelDateToString($serial, null),
            Spread::stringifyValue($immutable),
            "serial {$serial}",
        );
    }

    public static function dateToStringProvider(): array
    {
        // Whole-second serials only: the legacy path rounds to whole seconds,
        // so fractional-second serials legitimately differ at microsecond scale.
        return [
            [1.0],
            [59.0],
            [60.0],
            [61.0],
            [46_233.0],
            [45_214.5],
            [45_292.25],
        ];
    }

    public function testExcelDateToImmutableMicrosecondPrecision(): void
    {
        // The exact double for 12:30:00 gives 12:30:00.000000.
        $exact = Spread::excelDateToImmutable(45_214 + (45_000 / 86_400));
        self::assertSame('2023-10-15 12:30:00.000000', $exact->format('Y-m-d H:i:s.u'));

        // A 14-digit decimal that under-represents 12:30:00 keeps its true value.
        $imprecise = Spread::excelDateToImmutable(45_214.520_833_333_3);
        self::assertSame('2023-10-15 12:29:59.999997', $imprecise->format('Y-m-d H:i:s.u'));
    }

    #[DataProvider('isoDateProvider')]
    public function testParseIsoDate(string $value, ?string $expected): void
    {
        $parsed = Spread::parseIsoDate($value);
        if ($expected === null) {
            self::assertNull($parsed, $value);
            return;
        }
        self::assertInstanceOf(DateTimeImmutable::class, $parsed, $value);
        self::assertSame($expected, $parsed->format('Y-m-d H:i:s.u'), $value);
        // Spreadsheet dates are civil values: offsets never survive parsing.
        self::assertSame('+00:00', $parsed->format('P'), $value);
    }

    public static function isoDateProvider(): array
    {
        return [
            'date only' => ['1976-11-22', '1976-11-22 00:00:00.000000'],
            'datetime' => ['1976-11-22T08:30:00', '1976-11-22 08:30:00.000000'],
            'datetime utc' => ['1976-11-22T08:30:00Z', '1976-11-22 08:30:00.000000'],
            'datetime offset civil kept' => ['1976-11-22T08:30:00+02:00', '1976-11-22 08:30:00.000000'],
            'datetime fraction' => ['1976-11-22T08:30:00.123456Z', '1976-11-22 08:30:00.123456'],
            'datetime fraction offset' => ['1976-11-22T08:30:00.123456+02:00', '1976-11-22 08:30:00.123456'],
            'offset max +14' => ['1976-11-22T08:30:00+14:00', '1976-11-22 08:30:00.000000'],
            'offset max -14' => ['1976-11-22T08:30:00-14:00', '1976-11-22 08:30:00.000000'],
            'offset +13:59' => ['1976-11-22T08:30:00+13:59', '1976-11-22 08:30:00.000000'],
            'invalid offset +14:01' => ['1976-11-22T08:30:00+14:01', null],
            'invalid offset -14:01' => ['1976-11-22T08:30:00-14:01', null],
            'invalid offset +23:59' => ['1976-11-22T08:30:00+23:59', null],
            'invalid garbage' => ['not-a-date', null],
            'invalid month' => ['1976-13-01', null],
            'invalid day out of range' => ['1976-02-30', null],
            'invalid trailing data' => ['1976-11-22T08:30:00X', null],
            'invalid missing seconds' => ['1976-11-22T08:30Z', null],
        ];
    }

    public function testFormatTimeComponents(): void
    {
        self::assertSame('00:00:00', Spread::formatTimeComponents(0, 0, 0));
        self::assertSame('08:30:00', Spread::formatTimeComponents(8, 30, 0));
        self::assertSame('14:30:15', Spread::formatTimeComponents(14, 30, 15));
        self::assertSame('14:30:15.500000', Spread::formatTimeComponents(14, 30, 15, 500_000));
        self::assertSame('23:59:59.999999', Spread::formatTimeComponents(23, 59, 59, 999_999));
    }

    public function testExcelTimeToString(): void
    {
        self::assertSame('00:00:00', Spread::excelTimeToString(0.0));
        self::assertSame('08:30:00', Spread::excelTimeToString(8.5 / 24));
        self::assertSame('12:00:00', Spread::excelTimeToString(1.5));
        self::assertSame('14:30:15', Spread::excelTimeToString(52_215 / 86_400));
    }

    public function testDurationSerialToString(): void
    {
        self::assertSame('36:30:15', Spread::durationSerialToString(131_415 / 86_400));
        self::assertSame('-36:00:00', Spread::durationSerialToString(-1.5));
        self::assertSame('12:00:00', Spread::durationSerialToString(0.5));
    }

    public function testDurationSerialToStringRejectsNonFinite(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Spread::durationSerialToString(INF);
    }

    #[DataProvider('civilRoundTripProvider')]
    public function testCivilRoundTrip(string $dateTime): void
    {
        $dt = new DateTimeImmutable($dateTime, new DateTimeZone('UTC'));
        $serial = Spread::dateToExcel($dt);
        $back = Spread::excelDateToImmutable($serial);
        self::assertSame($dt->format('Y-m-d H:i:s.u'), $back->format('Y-m-d H:i:s.u'), $dateTime);
    }

    public static function civilRoundTripProvider(): array
    {
        return [
            'pre gregorian 1545' => ['1545-01-15 00:00:00.000000'],
            'pre gregorian 1242' => ['1242-09-16 00:00:00.000000'],
            'pre gregorian 1742' => ['1742-09-16 00:00:00.000000'],
            '1899' => ['1899-09-16 00:00:00.000000'],
            '1900 feb 28' => ['1900-02-28 00:00:00.000000'],
            '1900 mar 1' => ['1900-03-01 00:00:00.000000'],
            'modern noon' => ['2023-10-15 12:00:00.000000'],
            'modern midnight' => ['2026-08-13 00:00:00.000000'],
            'modern microseconds' => ['2026-08-13 14:30:15.500000'],
            'far future 2955' => ['2955-12-10 00:00:00.000000'],
            'far future 4111' => ['4111-09-16 00:00:00.000000'],
        ];
    }

    public function testSerialRoundTrip(): void
    {
        foreach ([0.0, 1.0, 59.0, 61.0, 46_233.0, 45_214.5, 45_292.25, 0.604_166_666_666_666_6] as $serial) {
            $serialBack = Spread::dateToExcel(Spread::excelDateToImmutable($serial));
            self::assertEqualsWithDelta($serial, $serialBack, 1e-9, "serial {$serial}");
        }
    }

    public function testLotusFakeDay60Collapses(): void
    {
        $dt = Spread::excelDateToImmutable(60.0);
        self::assertSame('1900-02-28', $dt->format('Y-m-d'));
        // The fake 1900-02-29 cannot be re-encoded; it maps back to serial 59.
        self::assertSame(59.0, Spread::dateToExcel($dt));
    }

    public function test1904RoundTrip(): void
    {
        $dt = new DateTimeImmutable('2019-09-02 22:23:00', new DateTimeZone('UTC'));
        $serial = Spread::dateToExcel($dt, true);
        $back = Spread::excelDateToImmutable($serial, true);
        self::assertSame('2019-09-02 22:23:00', $back->format('Y-m-d H:i:s'));
        self::assertSame(Spread::dateToExcel($back, true), $serial);
    }

    #[DataProvider('timeProvider')]
    public function testExcelTimeToTimeValue(float $fraction, TimeValue $expected): void
    {
        self::assertEquals($expected, Spread::excelTimeToTimeValue($fraction));
    }

    public static function timeProvider(): array
    {
        return [
            'midnight' => [0.0, new TimeValue(0, 0, 0)],
            '08:30' => [8.5 / 24, new TimeValue(8, 30, 0)],
            '23:59:59' => [((23 * 3600) + (59 * 60) + 59) / 86_400, new TimeValue(23, 59, 59)],
            'wrap past a day' => [1.5, new TimeValue(12, 0, 0)],
            'fractional' => [0.604_166_666_666_666_6, new TimeValue(14, 30, 0)],
        ];
    }

    public function testTimeToExcelIsInverse(): void
    {
        $time = new TimeValue(14, 30, 15, 500_000);
        $fraction = Spread::timeToExcel($time);
        self::assertEquals($time, Spread::excelTimeToTimeValue($fraction));
    }

    #[DataProvider('endOfDayProvider')]
    public function testEndOfDayFraction(float $secondsFloat, TimeValue $expected): void
    {
        self::assertEquals($expected, Spread::excelTimeToTimeValue($secondsFloat / 86_400));
    }

    public static function endOfDayProvider(): array
    {
        return [
            '23:59:59.499999' => [86_399.499_999, new TimeValue(23, 59, 59, 499_999)],
            '23:59:59.500000' => [86_399.5, new TimeValue(23, 59, 59, 500_000)],
            '23:59:59.600000' => [86_399.6, new TimeValue(23, 59, 59, 600_000)],
            '23:59:59.999999' => [86_399.999_999, new TimeValue(23, 59, 59, 999_999)],
        ];
    }

    public function testEndOfDayDoesNotAdvanceTheDate(): void
    {
        // Serial 46233 is 2026-07-30; 23:59:59.6 must stay on the same date.
        $dt = Spread::excelDateToImmutable(46_233 + (86_399.6 / 86_400));
        self::assertSame('2026-07-30 23:59:59.600000', $dt->format('Y-m-d H:i:s.u'));
    }

    public function testTimeValueEndOfDayRoundTrip(): void
    {
        $time = new TimeValue(23, 59, 59, 999_999);
        self::assertEquals($time, Spread::excelTimeToTimeValue(Spread::timeToExcel($time)));
    }

    #[DataProvider('stringifyProvider')]
    public function testStringifyValue(mixed $value, string $expected): void
    {
        self::assertSame($expected, Spread::stringifyValue($value));
    }

    public static function stringifyProvider(): array
    {
        return [
            'int' => [123, '123'],
            'float' => [12.5, '12.5'],
            'bool true' => [true, '1'],
            'bool false' => [false, '0'],
            'string' => ['abc', 'abc'],
            'null' => [null, ''],
            'time' => [new TimeValue(8, 30), '08:30:00'],
            'date midnight' => [new DateTimeImmutable('2026-08-13 00:00:00', new DateTimeZone('UTC')), '2026-08-13'],
            'datetime' => [
                new DateTimeImmutable('2026-08-13 14:30:15', new DateTimeZone('UTC')),
                '2026-08-13 14:30:15',
            ],
        ];
    }

    public function testStringifyDuration(): void
    {
        $positive = \Time\Duration::fromMicroseconds(129_600_000_000);
        self::assertSame('36:00:00', Spread::stringifyDuration($positive));

        $negative = $positive->negate();
        self::assertSame('-36:00:00', Spread::stringifyDuration($negative));

        $halfDay = \Time\Duration::fromMicroseconds(43_200_000_000);
        self::assertSame('12:00:00', Spread::stringifyDuration($halfDay));
    }

    #[DataProvider('isoDurationProvider')]
    public function testParseIsoDuration(string $iso, array $expected): void
    {
        self::assertSame($expected, Spread::parseIsoDuration($iso));
    }

    public static function isoDurationProvider(): array
    {
        return [
            'h m s' => [
                'PT14H30M15S',
                ['negative' => false, 'days' => 0, 'hours' => 14, 'minutes' => 30, 'seconds' => 15, 'microsecond' => 0],
            ],
            'over a day' => [
                'PT36H30M15S',
                ['negative' => false, 'days' => 0, 'hours' => 36, 'minutes' => 30, 'seconds' => 15, 'microsecond' => 0],
            ],
            'day plus hours' => [
                'P1DT2H',
                ['negative' => false, 'days' => 1, 'hours' => 2, 'minutes' => 0, 'seconds' => 0, 'microsecond' => 0],
            ],
            'fractional second' => [
                'PT0.5S',
                [
                    'negative' => false,
                    'days' => 0,
                    'hours' => 0,
                    'minutes' => 0,
                    'seconds' => 0,
                    'microsecond' => 500_000,
                ],
            ],
            'negative' => [
                '-PT1H',
                ['negative' => true, 'days' => 0, 'hours' => 1, 'minutes' => 0, 'seconds' => 0, 'microsecond' => 0],
            ],
            'm s only' => [
                'PT1M30S',
                ['negative' => false, 'days' => 0, 'hours' => 0, 'minutes' => 1, 'seconds' => 30, 'microsecond' => 0],
            ],
            'day only without T' => [
                'P1D',
                ['negative' => false, 'days' => 1, 'hours' => 0, 'minutes' => 0, 'seconds' => 0, 'microsecond' => 0],
            ],
            'overflow minutes' => [
                'PT90M',
                ['negative' => false, 'days' => 0, 'hours' => 1, 'minutes' => 30, 'seconds' => 0, 'microsecond' => 0],
            ],
            'overflow seconds' => [
                'PT1M90S',
                ['negative' => false, 'days' => 0, 'hours' => 0, 'minutes' => 2, 'seconds' => 30, 'microsecond' => 0],
            ],
            'overflow mixed' => [
                'PT1H90M90.5S',
                [
                    'negative' => false,
                    'days' => 0,
                    'hours' => 2,
                    'minutes' => 31,
                    'seconds' => 30,
                    'microsecond' => 500_000,
                ],
            ],
            'negative zero normalized' => [
                '-PT0H',
                [
                    'negative' => false,
                    'days' => 0,
                    'hours' => 0,
                    'minutes' => 0,
                    'seconds' => 0,
                    'microsecond' => 0,
                ],
            ],
        ];
    }

    #[DataProvider('isoGarbageProvider')]
    public function testParseIsoDurationRejectsGarbage(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Spread::parseIsoDuration($bad);
    }

    public static function isoGarbageProvider(): array
    {
        return [
            'trailing garbage' => ['PT1Hgarbage'],
            'bare P' => ['P'],
            'empty T' => ['PT'],
            'week component' => ['P1W'],
            'month component' => ['P1M'],
            'year component' => ['PT1Y'],
            'trailing junk' => ['PT0H0M0S0X'],
            'too many fraction digits' => ['PT1.1234567S'],
            'unconsumed minute' => ['PT1HX'],
        ];
    }

    public function testParseIsoDurationAcceptsZero(): void
    {
        self::assertSame(
            ['negative' => false, 'days' => 0, 'hours' => 0, 'minutes' => 0, 'seconds' => 0, 'microsecond' => 0],
            Spread::parseIsoDuration('PT0H'),
        );
    }

    #[DataProvider('isoFormatProvider')]
    public function testFormatIsoDurationComponents(array $components, string $expected): void
    {
        self::assertSame($expected, Spread::formatIsoDurationComponents(...$components));
    }

    #[DataProvider('isoDurationObjectProvider')]
    public function testFormatIsoDuration(\Time\Duration $duration, string $expected): void
    {
        self::assertSame($expected, Spread::formatIsoDuration($duration));
    }

    public static function isoDurationObjectProvider(): array
    {
        return [
            'h m s' => [
                \Time\Duration::fromMicroseconds(((14 * 3600) + (30 * 60) + 15) * 1_000_000), // 14:30:15
                'PT14H30M15S',
            ],
            'over a day' => [
                \Time\Duration::fromMicroseconds(((36 * 3600) + (30 * 60) + 15) * 1_000_000), // 36:30:15
                'PT36H30M15S',
            ],
            'fraction' => [
                \Time\Duration::fromMicroseconds(500_000), // 0.5s
                'PT0H0M0.500000S',
            ],
            'negative' => [
                \Time\Duration::fromMicroseconds(3_600_000_000)->negate(), // -1 hour
                '-PT1H0M0S',
            ],
            'zero' => [
                \Time\Duration::fromMicroseconds(0),
                'PT0H0M0S',
            ],
        ];
    }

    public static function isoFormatProvider(): array
    {
        return [
            'h m s' => [[false, 14, 30, 15, 0], 'PT14H30M15S'],
            'over a day' => [[false, 36, 30, 15, 0], 'PT36H30M15S'],
            'fraction' => [[false, 0, 0, 0, 500_000], 'PT0H0M0.500000S'],
            'negative' => [[true, 1, 0, 0, 0], '-PT1H0M0S'],
        ];
    }

    public function testParseIsoDurationRoundTrip(): void
    {
        foreach (['PT14H30M15S', 'PT36H30M15.5S', 'P1DT2H'] as $iso) {
            $c = Spread::parseIsoDuration($iso);
            $canonical = Spread::formatIsoDurationComponents(
                $c['negative'],
                ($c['days'] * 24) + $c['hours'],
                $c['minutes'],
                $c['seconds'],
                $c['microsecond'],
            );
            $back = Spread::parseIsoDuration($canonical);
            $backCanonical = Spread::formatIsoDurationComponents(
                $back['negative'],
                ($back['days'] * 24) + $back['hours'],
                $back['minutes'],
                $back['seconds'],
                $back['microsecond'],
            );
            self::assertSame($canonical, $backCanonical, $iso);
        }
    }
}
