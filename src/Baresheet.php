<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Exception;
use Generator;

/**
 * Lightweight helper for reading/writing without knowing the format upfront.
 *
 * Detects file type by file extension or content inspection and delegates
 * to the appropriate reader/writer. No adapter-routing machinery — just
 * concrete implementations.
 */
class Baresheet
{
    private const EXT_CSV = 'csv';
    private const EXT_XLSX = 'xlsx';
    private const EXT_ODS = 'ods';

    /**
     * Read a file, auto-detecting format from extension.
     *
     * @return Generator<mixed>
     */
    public static function read(string $filename, ?Options $options = null): Generator
    {
        $ext = self::getExtension($filename);
        $reader = self::getReader($ext);
        return $reader->readFile($filename, $options);
    }

    /**
     * Read from a raw string, detecting format from content bytes or explicit ext.
     *
     * @param string|null $ext  Force extension ('csv', 'xlsx', or 'ods'). Auto-detects if null.
     * @return Generator<mixed>
     */
    public static function readString(string $contents, ?string $ext = null, ?Options $options = null): Generator
    {
        $ext ??= Spread::getExtensionForContent($contents);
        $reader = self::getReader($ext);
        return $reader->readString($contents, $options);
    }

    /**
     * Write data to a file, format determined by extension.
     *
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public static function write(iterable $data, string $filename, ?Options $options = null): bool
    {
        $ext = self::getExtension($filename);
        $writer = self::getWriter($ext);
        return $writer->writeFile($data, $filename, $options);
    }

    /**
     * Write data to a string. Extension is required.
     *
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     * @param string $ext  'csv', 'xlsx', or 'ods'
     */
    public static function writeString(iterable $data, string $ext, ?Options $options = null): string
    {
        $writer = self::getWriter($ext);
        return $writer->writeString($data, $options);
    }

    /**
     * Stream data as a download, format determined by extension.
     *
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public static function output(iterable $data, string $filename, ?Options $options = null): void
    {
        $ext = self::getExtension($filename);
        $writer = self::getWriter($ext);
        $writer->output($data, $filename, $options);
    }

    /**
     * Get a reader instance for the given extension.
     */
    public static function getReader(string $ext): ReaderInterface
    {
        return match (strtolower($ext)) {
            self::EXT_CSV => new CsvReader(),
            self::EXT_XLSX => new XlsxReader(),
            self::EXT_ODS => new OdsReader(),
            default => throw new Exception("Unsupported format: $ext"),
        };
    }

    /**
     * Get a writer instance for the given extension.
     */
    public static function getWriter(string $ext): WriterInterface
    {
        return match (strtolower($ext)) {
            self::EXT_CSV => new CsvWriter(),
            self::EXT_XLSX => new XlsxWriter(),
            self::EXT_ODS => new OdsWriter(),
            default => throw new Exception("Unsupported format: $ext"),
        };
    }

    protected static function getExtension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!$ext) {
            if (is_file($filename)) {
                $header = file_get_contents($filename, false, null, 0, 8192);
                if ($header !== false) {
                    return Spread::getExtensionForContent($header);
                }
            }
            throw new Exception("Cannot determine format: file has no extension");
        }
        return $ext;
    }
}
