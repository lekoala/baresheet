<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Value;

use InvalidArgumentException;
use LeKoala\Baresheet\Spread;

/**
 * An elapsed duration used as an explicit writer marker.
 *
 * This is a serialization marker, not a time library: it only tells the
 * writer "write this cell as spreadsheet duration". It has no arithmetic,
 * comparison or calendar semantics. Elapsed durations may exceed 24 hours and
 * may be negative (via the $negative flag).
 *
 * Baresheet represents temporal values at microsecond precision.
 */
final class DurationValue implements \Stringable
{
    public function __construct(
        public readonly int $hours,
        public readonly int $minutes = 0,
        public readonly int $seconds = 0,
        public readonly int $microsecond = 0,
        public readonly bool $negative = false,
    ) {
        if ($hours < 0) {
            throw new InvalidArgumentException('Hours must be >= 0, got ' . $hours);
        }
        if ($minutes < 0 || $minutes > 59) {
            throw new InvalidArgumentException('Minutes must be between 0 and 59, got ' . $minutes);
        }
        if ($seconds < 0 || $seconds > 59) {
            throw new InvalidArgumentException('Seconds must be between 0 and 59, got ' . $seconds);
        }
        if ($microsecond < 0 || $microsecond > 999_999) {
            throw new InvalidArgumentException('Microsecond must be between 0 and 999999, got ' . $microsecond);
        }
    }

    public function __toString(): string
    {
        return Spread::formatDurationComponents(
            $this->negative,
            $this->hours,
            $this->minutes,
            $this->seconds,
            $this->microsecond,
        );
    }
}
