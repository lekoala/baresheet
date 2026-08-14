<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Value;

use InvalidArgumentException;

/**
 * A time of day without a date or timezone, bounded to a single day.
 *
 * Represents a position within a day (00:00:00 <= t < 24:00:00), not an
 * elapsed amount of time. Elapsed durations that may exceed 24 hours belong
 * to `DurationValue` (a Baresheet marker) or the PHP 8.6 `Time\Duration` API.
 */
final class TimeValue implements \Stringable
{
    public const MICROSECONDS_PER_DAY = 86_400_000_000;

    public readonly int $hour;
    public readonly int $minute;
    public readonly int $second;
    public readonly int $microsecond;

    public function __construct(
        int $hour,
        int $minute,
        int $second = 0,
        int $microsecond = 0,
    ) {
        if ($hour < 0 || $hour > 23) {
            throw new InvalidArgumentException('Hour must be between 0 and 23, got ' . $hour);
        }
        if ($minute < 0 || $minute > 59) {
            throw new InvalidArgumentException('Minute must be between 0 and 59, got ' . $minute);
        }
        if ($second < 0 || $second > 59) {
            throw new InvalidArgumentException('Second must be between 0 and 59, got ' . $second);
        }
        if ($microsecond < 0 || $microsecond > 999_999) {
            throw new InvalidArgumentException('Microsecond must be between 0 and 999999, got ' . $microsecond);
        }
        $this->hour = $hour;
        $this->minute = $minute;
        $this->second = $second;
        $this->microsecond = $microsecond;
    }

    /**
     * Build from microseconds elapsed since midnight (the natural intermediate
     * representation of an Excel day fraction).
     *
     * @internal Requires 64-bit integers.
     * @throws InvalidArgumentException If out of the [0, 86400000000) range.
     */
    public static function fromMicroseconds(int $microseconds): self
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('TimeValue microsecond conversions require 64-bit PHP.');
        }
        if ($microseconds < 0 || $microseconds >= self::MICROSECONDS_PER_DAY) {
            throw new InvalidArgumentException(
                'Microseconds must be in range [0, 86400000000), got ' . $microseconds,
            );
        }
        $hour = intdiv($microseconds, 3_600_000_000);
        $microseconds %= 3_600_000_000;
        $minute = intdiv($microseconds, 60_000_000);
        $microseconds %= 60_000_000;
        $second = intdiv($microseconds, 1_000_000);
        $microsecond = $microseconds % 1_000_000;
        return new self($hour, $minute, $second, $microsecond);
    }

    /** @internal Requires 64-bit integers. */
    public function toMicroseconds(): int
    {
        if (PHP_INT_SIZE < 8) {
            throw new \RuntimeException('TimeValue microsecond conversions require 64-bit PHP.');
        }
        return (
            ($this->hour * 3_600_000_000)
            + ($this->minute * 60_000_000)
            + ($this->second * 1_000_000)
            + $this->microsecond
        );
    }

    public function __toString(): string
    {
        return \LeKoala\Baresheet\Spread::formatTimeComponents(
            $this->hour,
            $this->minute,
            $this->second,
            $this->microsecond,
        );
    }
}
