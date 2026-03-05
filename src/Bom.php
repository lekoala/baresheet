<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use ValueError;

/**
 * Byte Order Mark sequences for parsing CSVs.
 */
enum Bom: string
{
    case Utf8 = "\xEF\xBB\xBF";
    case Utf16Be = "\xFE\xFF";
    case Utf16Le = "\xFF\xFE";
    case Utf32Be = "\x00\x00\xFE\xFF";
    case Utf32Le = "\xFF\xFE\x00\x00";

    /**
     * Get the length of the BOM sequence.
     */
    public function length(): int
    {
        return strlen($this->value);
    }

    /**
     * Get the iconv encoding name for this BOM.
     */
    public function encoding(): string
    {
        return match ($this) {
            self::Utf8 => 'UTF-8',
            self::Utf16Be => 'UTF-16BE',
            self::Utf16Le => 'UTF-16LE',
            self::Utf32Be => 'UTF-32BE',
            self::Utf32Le => 'UTF-32LE',
        };
    }

    /**
     * Check if the BOM represents a UTF-8 encoding.
     */
    public function isUtf8(): bool
    {
        return $this === self::Utf8;
    }

    /**
     * Check if the BOM represents a UTF-16 encoding.
     */
    public function isUtf16(): bool
    {
        return $this === self::Utf16Be || $this === self::Utf16Le;
    }

    /**
     * Check if the BOM represents a UTF-32 encoding.
     */
    public function isUtf32(): bool
    {
        return $this === self::Utf32Be || $this === self::Utf32Le;
    }

    /**
     * Try to match a BOM sequence from the start of a string or stream.
     * Note: Checks the longest sequences first.
     */
    public static function tryFromSequence(string $sequence): ?self
    {
        if (str_starts_with($sequence, self::Utf32Be->value)) {
            return self::Utf32Be;
        }
        if (str_starts_with($sequence, self::Utf32Le->value)) {
            return self::Utf32Le;
        }
        if (str_starts_with($sequence, self::Utf8->value)) {
            return self::Utf8;
        }
        if (str_starts_with($sequence, self::Utf16Be->value)) {
            return self::Utf16Be;
        }
        if (str_starts_with($sequence, self::Utf16Le->value)) {
            return self::Utf16Le;
        }

        return null;
    }

    /**
     * Match a BOM sequence from the start of a string or throw an exception.
     */
    public static function fromSequence(string $sequence): self
    {
        $bom = self::tryFromSequence($sequence);
        if ($bom === null) {
            throw new ValueError('No recognized BOM sequence found in string.');
        }

        return $bom;
    }
}
