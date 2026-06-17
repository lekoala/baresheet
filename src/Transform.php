<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTime;
use DateTimeInterface;
use Generator;

/**
 * Data transformation pipelines for spreadsheet rows.
 *
 * These are generator-based transformers that compose cleanly with
 * reader/writer pipelines. They add zero overhead when not used and
 * chain naturally with the PHP 8.5 pipe operator.
 *
 * All methods accept iterable<array> and yield arrays lazily.
 */
class Transform
{
    /**
     * Trim whitespace from string cell values.
     *
     * @param iterable<array<mixed>> $data
     * @return Generator<array<mixed>>
     */
    public static function trim(iterable $data, bool $trimKeys = false): Generator
    {
        foreach ($data as $row) {
            $trimmed = [];
            foreach ($row as $k => $v) {
                $v = is_string($v) ? trim($v) : $v;
                if ($trimKeys && is_string($k)) {
                    $k = trim($k);
                }
                $trimmed[$k] = $v;
            }
            yield $trimmed;
        }
    }

    /**
     * Replace null values with a string representation.
     *
     * @param iterable<array<mixed>> $data
     * @return Generator<array<mixed>>
     */
    public static function nullAs(iterable $data, string $value): Generator
    {
        foreach ($data as $row) {
            $replaced = [];
            foreach ($row as $k => $v) {
                $replaced[$k] = $v === null ? $value : $v;
            }
            yield $replaced;
        }
    }

    /**
     * Replace boolean values with string representations.
     *
     * @param iterable<array<mixed>> $data
     * @return Generator<array<mixed>>
     */
    public static function boolAs(iterable $data, string $true, string $false): Generator
    {
        foreach ($data as $row) {
            $replaced = [];
            foreach ($row as $k => $v) {
                if (is_bool($v)) {
                    $replaced[$k] = $v ? $true : $false;
                } else {
                    $replaced[$k] = $v;
                }
            }
            yield $replaced;
        }
    }

    /**
     * Apply a custom transformation to each cell.
     *
     * Callback receives (mixed $cell, int|string $column) and returns the new value.
     *
     * @param iterable<array<mixed>> $data
     * @param callable(mixed, int|string): mixed $fn
     * @return Generator<array<mixed>>
     */
    public static function map(iterable $data, callable $fn): Generator
    {
        foreach ($data as $row) {
            $mapped = [];
            foreach ($row as $k => $v) {
                $mapped[$k] = $fn($v, $k);
            }
            yield $mapped;
        }
    }

    /**
     * Filter rows based on a predicate.
     *
     * Callback receives (array $row, int $index) and must return bool.
     * Only rows where the callback returns true are yielded.
     *
     * @param iterable<array<mixed>> $data
     * @param callable(array<mixed>, int): bool $fn
     * @return Generator<array<mixed>>
     */
    public static function filter(iterable $data, callable $fn): Generator
    {
        $index = 0;
        foreach ($data as $row) {
            if ($fn($row, $index)) {
                yield $row;
            }
            $index++;
        }
    }

    /**
     * Cast columns to specific types.
     *
     * $types maps column names (assoc mode) or indices (non-assoc) to type strings:
     * 'int', 'float', 'bool', 'string', 'date'.
     *
     * For 'date', values are parsed as ISO 8601 strings and returned as DateTimeInterface.
     *
     * @param iterable<array<mixed>> $data
     * @param array<int|string, string> $types
     * @return Generator<array<mixed>>
     */
    public static function cast(iterable $data, array $types): Generator
    {
        foreach ($data as $row) {
            $casted = [];
            foreach ($row as $k => $v) {
                if (isset($types[$k])) {
                    $v = self::castValue($v, $types[$k]);
                }
                $casted[$k] = $v;
            }
            yield $casted;
        }
    }

    /**
     * Batch rows into chunks of a given size.
     *
     * Yields arrays of up to $size rows. The last chunk may be smaller.
     *
     * @param iterable<array<mixed>> $data
     * @return Generator<array<array<mixed>>>
     */
    public static function chunk(iterable $data, int $size): Generator
    {
        $chunk = [];
        foreach ($data as $row) {
            $chunk[] = $row;
            if (count($chunk) >= $size) {
                yield $chunk;
                $chunk = [];
            }
        }
        if (!empty($chunk)) {
            yield $chunk;
        }
    }

    /**
     * Cast a single value to the requested type.
     *
     * Supports nullable prefixes: ?int, ?float, ?bool, ?string, ?date
     *
     * Non-nullable types always return their type, never null:
     *   '' or null → PHP default (0, 0.0, false, '')
     * Nullable types return null for '' or null input.
     * Date is always effectively nullable (can't construct from nothing).
     */
    private static function castValue(mixed $value, string $type): mixed
    {
        $isNullable = str_starts_with($type, '?');
        $baseType = $isNullable ? substr($type, 1) : $type;

        if ($value === null || $value === '') {
            if ($isNullable || $baseType === 'date') {
                return null;
            }

            return match ($baseType) {
                'string' => '',
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                default => $value,
            };
        }

        $str = is_scalar($value) || $value instanceof \Stringable
            ? (string) $value
            : '';

        return match ($baseType) {
            'int' => self::castInt($str, $isNullable),
            'float' => self::castFloat($str, $isNullable),
            'bool' => filter_var($str, FILTER_VALIDATE_BOOLEAN),
            'string' => $str,
            'date' => self::castDate($str),
            default => $value,
        };
    }

    /**
     * Cast a string to int, respecting nullability.
     */
    private static function castInt(string $str, bool $isNullable): ?int
    {
        if (is_numeric($str)) {
            return (int) $str;
        }

        return $isNullable ? null : 0;
    }

    /**
     * Cast a string to float, respecting nullability.
     */
    private static function castFloat(string $str, bool $isNullable): ?float
    {
        if (is_numeric($str)) {
            return (float) $str;
        }

        return $isNullable ? null : 0.0;
    }

    /**
     * Cache for parsed dates to avoid object allocation overhead.
     * @var array<string, ?DateTimeInterface>
     */
    private static array $dateCache = [];

    /**
     * Parse a string as a date.
     */
    private static function castDate(string $value): ?DateTimeInterface
    {
        if (isset(self::$dateCache[$value])) {
            return clone self::$dateCache[$value];
        }

        if (array_key_exists($value, self::$dateCache)) {
            return null;
        }

        // Limit cache size to prevent memory leaks from millions of unique dates
        if (count(self::$dateCache) > 10000) {
            self::$dateCache = [];
        }

        try {
            $date = new DateTime($value);
            self::$dateCache[$value] = clone $date;
            return $date;
        } catch (\Exception) {
            self::$dateCache[$value] = null;
            return null;
        }
    }
}
