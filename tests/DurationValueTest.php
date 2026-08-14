<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Value\DurationValue;
use PHPUnit\Framework\Attributes\DataProvider;

class DurationValueTest extends TestCase
{
    public function testConstructorComponents(): void
    {
        $d = new DurationValue(36, 30, 15);
        self::assertSame(36, $d->hours);
        self::assertSame(30, $d->minutes);
        self::assertSame(15, $d->seconds);
        self::assertSame(0, $d->microsecond);
        self::assertFalse($d->negative);
    }

    public function testNegativeFlagIsExplicit(): void
    {
        $d = new DurationValue(36, 30, 15, negative: true);
        self::assertTrue($d->negative);
        self::assertSame(36, $d->hours);
        self::assertSame(30, $d->minutes);
    }

    public function testToString(): void
    {
        self::assertSame('36:30:15', (string) new DurationValue(36, 30, 15));
        self::assertSame('12:00:00', (string) new DurationValue(12));
        self::assertSame('-1:30:00', (string) new DurationValue(1, 30, negative: true));
        self::assertSame('0:00:01.500000', (string) new DurationValue(0, 0, 1, 500_000));
        self::assertSame('26:00:00', (string) new DurationValue(26));
        // A zero duration is never negative.
        self::assertSame('0:00:00', (string) new DurationValue(0, negative: true));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidComponentsThrow(int $hours, int $minutes, int $seconds, int $microsecond): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DurationValue($hours, $minutes, $seconds, $microsecond);
    }

    public static function invalidProvider(): array
    {
        return [
            'negative hours' => [-1, 0, 0, 0],
            'minutes too high' => [0, 60, 0, 0],
            'seconds too high' => [0, 0, 60, 0],
            'microsecond too high' => [0, 0, 0, 1_000_000],
            'microsecond negative' => [0, 0, 0, -1],
        ];
    }
}
