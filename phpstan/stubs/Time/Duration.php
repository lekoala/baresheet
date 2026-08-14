<?php

/**
 * PHPStan analysis stub for the PHP 8.6 Time API.
 *
 * This file is only read by PHPStan (via stubFiles in phpstan.neon.dist).
 * It is NOT autoloaded at runtime: PHP < 8.6 without the symfony/polyfill-time
 * package simply has no Time\Duration class, and Baresheet guards every use
 * behind class_exists('Time\\Duration').
 *
 * The polyfill (and PHP 8.6 core) declares Duration with a private constructor
 * and public readonly properties seconds/nanoseconds/negative.
 */

namespace Time;

class TimeException extends \Exception
{
}

final class Duration
{
    public readonly int $seconds;
    public readonly int $nanoseconds;
    public readonly bool $negative;

    private function __construct(int $seconds, int $nanoseconds, bool $negative)
    {
    }

    public static function fromSeconds(int $seconds, int $nanoseconds = 0): self
    {
        return new self(0, 0, false);
    }

    public static function fromNanoseconds(int $nanoseconds): self
    {
        return new self(0, 0, false);
    }

    public static function fromMicroseconds(int $microseconds): self
    {
        return new self(0, 0, false);
    }

    public static function fromMilliseconds(int $milliseconds): self
    {
        return new self(0, 0, false);
    }

    public static function fromMinutes(int $minutes): self
    {
        return new self(0, 0, false);
    }

    public static function fromHours(int $hours): self
    {
        return new self(0, 0, false);
    }

    public static function fromIso8601DurationString(string $specification): self
    {
        return new self(0, 0, false);
    }

    public function negate(): self
    {
        return new self(0, 0, false);
    }

    public function absolute(): self
    {
        return new self(0, 0, false);
    }

    public function add(self $duration): self
    {
        return new self(0, 0, false);
    }

    public function sub(self $duration): self
    {
        return new self(0, 0, false);
    }

    public function multiplyBy(int $factor): self
    {
        return new self(0, 0, false);
    }

    public function divideBy(int $divisor): self
    {
        return new self(0, 0, false);
    }

    public function __set(string $name, mixed $value): void
    {
    }
}
