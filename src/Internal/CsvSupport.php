<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Internal;

use LeKoala\Baresheet\Exception\BaresheetException;
use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\WriteException;
use LogicException;

/**
 * Lean stream and scalar helpers used by the CSV reader/writer.
 *
 * Kept out of {@see \LeKoala\Baresheet\Spread} so a CSV-only code path does not
 * load Spread's full 1500-line surface (dates, durations, ZIP, XML, columns…).
 * Spread keeps its public methods and delegates here, preserving BC.
 */
final class CsvSupport
{
    /**
     * @throws InvalidDocumentException
     */
    public static function isSafePath(string $path): void
    {
        if (preg_match('/^([a-zA-Z0-9+\-.]+):\/\//', $path, $matches)) {
            $scheme = strtolower($matches[1]);
            $allowedSchemes = ['php', 'file', 'zip'];
            if (!in_array($scheme, $allowedSchemes, true)) {
                throw new InvalidDocumentException('Invalid stream wrapper: ' . $scheme . ' is not allowed');
            }
        }

        if (str_contains(strtolower($path), 'phar://')) {
            throw new InvalidDocumentException('Phar deserialization is not allowed');
        }

        // "php://filter/resource=..." (or "/read=..." etc.) chains an arbitrary inner
        // resource behind the php:// scheme, which defeats the scheme allow-list above.
        // Only the plain data streams are legitimate targets for a filename argument.
        if (preg_match('/^php:\/\//i', $path)) {
            $allowedPhpPaths = [
                'php://input',
                'php://output',
                'php://temp',
                'php://memory',
                'php://stdin',
                'php://stdout',
                'php://stderr',
            ];
            if (!in_array(strtolower($path), $allowedPhpPaths, true)) {
                throw new InvalidDocumentException("Invalid php:// stream: {$path} is not allowed");
            }
        }
    }

    /**
     * Uses php://temp with a 4 MB memory cap before spilling to disk.
     *
     * @return resource
     */
    public static function getMaxMemTempStream()
    {
        $mb = 4;
        $stream = fopen('php://temp/maxmemory:' . ($mb * 1024 * 1024), 'r+');
        if (!$stream) {
            throw new BaresheetException('Failed to open stream');
        }
        return $stream;
    }

    /**
     * @return resource
     * @throws WriteException
     */
    public static function getOutputStream(string $filename = 'php://output')
    {
        self::isSafePath($filename);
        $stream = @fopen($filename, 'w');
        if (!$stream) {
            throw new WriteException('Failed to open stream');
        }
        return $stream;
    }

    /**
     * @return resource
     * @throws InvalidDocumentException
     */
    public static function getInputStream(string $filename)
    {
        self::isSafePath($filename);
        $stream = @fopen($filename, 'r');
        if (!$stream) {
            throw new InvalidDocumentException('Failed to open stream');
        }
        return $stream;
    }

    public static function ensureExtension(string $filename, string $ext): string
    {
        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($fileExt !== strtolower($ext)) {
            $filename .= ".{$ext}";
        }
        return $filename;
    }

    public static function outputHeaders(string $contentType, string $filename, ?int $size = null): void
    {
        if (headers_sent()) {
            throw new LogicException('Headers already sent');
        }

        header('Content-Type: ' . $contentType);
        header(
            'Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\''
                . rawurlencode($filename),
        );
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        if ($size !== null && $size > 0) {
            header('Content-Length: ' . $size);
        }
    }

    /**
     * Serialize a finite float with up to 17 significant digits, using '.' as
     * the decimal separator regardless of the active LC_NUMERIC locale.
     *
     * @param float $value A finite value; callers reject NaN/INF first.
     */
    public static function serializeFloat(float $value): string
    {
        $s = sprintf('%.17G', $value);
        // %F is the only float specifier that ignores the locale; %G (like %g,
        // %e) can insert the locale decimal separator. localeconv() is read on
        // every call because the locale may change at any point in the process.
        $decimalPoint = localeconv()['decimal_point'];
        if ($decimalPoint !== '.') {
            $s = str_replace($decimalPoint, '.', $s);
        }
        return $s;
    }
}
