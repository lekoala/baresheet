<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Value\TimeValue;
use PHPUnit\Framework\Attributes\DataProvider;

class TimeValueTest extends TestCase
{
    public function testToAndFromMicroseconds(): void
    {
        $time = new TimeValue(14, 30, 15);
        self::assertSame(52_215_000_000, $time->toMicroseconds());
        self::assertEquals($time, TimeValue::fromMicroseconds(52_215_000_000));
    }

    public function testFromMicrosecondsMidnightAndEndOfDay(): void
    {
        self::assertSame(0, TimeValue::fromMicroseconds(0)->toMicroseconds());
        self::assertSame(86_399_999_999, TimeValue::fromMicroseconds(86_399_999_999)->toMicroseconds());
        self::assertEquals(new TimeValue(23, 59, 59, 999_999), TimeValue::fromMicroseconds(86_399_999_999));
    }

    public function testToString(): void
    {
        self::assertSame('08:30:00', (string) new TimeValue(8, 30));
        self::assertSame('23:15:42', (string) new TimeValue(23, 15, 42));
        self::assertSame('14:30:15.500000', (string) new TimeValue(14, 30, 15, 500_000));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidValuesThrow(int $hour, int $minute, int $second, int $microsecond): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TimeValue($hour, $minute, $second, $microsecond);
    }

    public static function invalidProvider(): array
    {
        return [
            'hour too high' => [24, 0, 0, 0],
            'hour negative' => [-1, 0, 0, 0],
            'minute too high' => [0, 60, 0, 0],
            'second too high' => [0, 0, 60, 0],
            'microsecond too high' => [0, 0, 0, 1_000_000],
        ];
    }

    #[DataProvider('outOfRangeProvider')]
    public function testFromMicrosecondsOutOfRangeThrows(int $microseconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeValue::fromMicroseconds($microseconds);
    }

    public static function outOfRangeProvider(): array
    {
        return [
            'one day' => [86_400_000_000],
            'negative' => [-1],
        ];
    }

    public function testMicrosecondsAreCarriedAcrossComponents(): void
    {
        $time = TimeValue::fromMicroseconds(3_723_042_123);
        self::assertEquals(new TimeValue(1, 2, 3, 42_123), $time);
        self::assertSame(3_723_042_123, $time->toMicroseconds());
    }
}
