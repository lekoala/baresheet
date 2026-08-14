<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Value;

use LeKoala\Baresheet\Spread;

/**
 * An elapsed duration used as an explicit writer marker.
 *
 * This is a serialization marker, not a time library: it only tells the
 * writer "write this cell as spreadsheet duration". It has no arithmetic,
 * comparison or calendar semantics. Elapsed durations may exceed 24 hours and
 * may be negative.
 *
 * Baresheet represents temporal values at microsecond precision.
 */
final class DurationValue implements \Stringable
{
    public function __construct(
        public readonly int $microseconds,
    ) {}

    public static function fromMicroseconds(int $microseconds): self
    {
        return new self($microseconds);
    }

    public static function fromSeconds(int $seconds, int $microseconds = 0): self
    {
        return new self(($seconds * 1_000_000) + $microseconds);
    }

    public static function fromTime(
        int $hours = 0,
        int $minutes = 0,
        int $seconds = 0,
        int $microseconds = 0,
    ): self {
        return new self(
            ($hours * 3_600_000_000) + ($minutes * 60_000_000) + ($seconds * 1_000_000) + $microseconds,
        );
    }

    public function __toString(): string
    {
        return Spread::formatDurationMicroseconds($this->microseconds);
    }
}
