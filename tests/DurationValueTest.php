<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Value\DurationValue;

class DurationValueTest extends TestCase
{
    public function testFromMicroseconds(): void
    {
        self::assertSame(131_415_000_000, DurationValue::fromMicroseconds(131_415_000_000)->microseconds);
    }

    public function testFromSeconds(): void
    {
        self::assertSame(5_000_000, DurationValue::fromSeconds(5)->microseconds);
        self::assertSame(5_000_123, DurationValue::fromSeconds(5, 123)->microseconds);
        self::assertSame(-5_000_000, DurationValue::fromSeconds(-5)->microseconds);
    }

    public function testFromTime(): void
    {
        self::assertSame(131_415_000_000, DurationValue::fromTime(36, 30, 15)->microseconds);
        self::assertSame(3_723_042_123, DurationValue::fromTime(1, 2, 3, 42_123)->microseconds);
        self::assertSame(93_600_000_000, DurationValue::fromTime(hours: 26)->microseconds);
    }

    public function testToString(): void
    {
        self::assertSame('36:30:15', (string) DurationValue::fromTime(36, 30, 15));
        self::assertSame('12:00:00', (string) DurationValue::fromTime(12));
        self::assertSame('-1:00:00', (string) DurationValue::fromTime(-1));
        // The canonical duration form trims trailing fractional zeros.
        self::assertSame('0:00:01.5', (string) DurationValue::fromMicroseconds(1_500_000));
    }
}
