<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use InvalidArgumentException;
use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Internal\CsvSupport;

/**
 * Zero-dependency CSV writer using native PHP fputcsv.
 *
 * @phpstan-import-type WritableRow from WriterInterface
 */
class CsvWriter implements WriterInterface
{
    public const MIMETYPE = 'text/csv';

    private const FORMULA_ESCAPE_CHARS = "=+-@\t\r";

    public string $separator = ',';
    public string $enclosure = '"';
    public string $escape = '';
    public string $eol = "\r\n";
    public bool|Bom|string $bom = true;
    public bool $stream = true;
    public bool $strict = false;
    /**
     * @var bool|callable If true, escapes formulas starting with `=`, `+`, `-`, or `@` to prevent injection.
     *                    If a callable, it receives (string $cell, int $colIndex) and should return the processed cell.
     */
    public $escapeFormulas = false;
    public ?string $outputEncoding = null;
    /**
     * @var string[]
     */
    public array $headers = [];

    public function __construct(?Options $options = null)
    {
        $options?->applyTo($this);
    }

    /**
     * @param iterable<WritableRow> $data
     * @return resource The opened stream containing the data. It is the caller's responsibility to close it.
     */
    public function writeStream(iterable $data)
    {
        $stream = CsvSupport::getMaxMemTempStream();
        $this->writeInternal($stream, $data);
        rewind($stream);
        return $stream;
    }

    /**
     * @param iterable<WritableRow> $data
     * @param resource              $stream The stream to write to. The caller owns the stream (no rewind or close is performed).
     */
    public function writeToStream(iterable $data, $stream): void
    {
        $this->writeInternal($stream, $data);
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function writeString(iterable $data): string
    {
        $stream = $this->writeStream($data);
        $contents = stream_get_contents($stream);
        fclose($stream);
        return $contents !== false ? $contents : '';
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function writeFile(iterable $data, string $filename): bool
    {
        $filename = CsvSupport::ensureExtension($filename, 'csv');

        $stream = CsvSupport::getOutputStream($filename);
        $this->writeInternal($stream, $data);
        fclose($stream);
        return true;
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function output(iterable $data, string $filename): void
    {
        $filename = CsvSupport::ensureExtension($filename, 'csv');

        if ($this->stream) {
            $this->outputStream($data, $filename);
            return;
        }

        $content = $this->writeString($data);
        CsvSupport::outputHeaders(self::MIMETYPE, $filename, strlen($content));
        echo $content;
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function outputStream(iterable $data, string $filename): void
    {
        CsvSupport::outputHeaders(self::MIMETYPE, $filename);
        $stream = CsvSupport::getOutputStream();
        $this->writeInternal($stream, $data);
        fclose($stream);
    }

    // -- Internal --

    /**
     * @param resource $stream
     * @param iterable<WritableRow> $data
     */
    private function writeInternal($stream, iterable $data): void
    {
        /** @var resource|false|null $streamFilter */
        $streamFilter = null;

        // The stream filter must be removed before returning: for writeToStream()
        // the stream belongs to the caller, and leaving a filter attached would
        // transcode (or double-transcode) its subsequent writes. Removing it also
        // flushes the filter's buffered bytes, which is required for writeString()
        // before the rewind.
        try {
            $bomToWrite = $this->resolveBomToWrite();
            $outputEncoding = $this->outputEncoding;
            if ($outputEncoding === '') {
                // An empty outputEncoding means "no conversion", like null.
                $outputEncoding = null;
            }

            $this->assertValidEncodingOptions($bomToWrite, $outputEncoding);

            // Transcoding now targets a single encoding for the whole stream and is
            // delegated to a convert.iconv write filter, so the separator, enclosure
            // and end-of-line bytes are encoded too. Per-cell mb_convert_encoding
            // used to leave those structure bytes in ASCII, producing files that
            // mixed the target encoding with ASCII (reproduced as 61002c62000d0a
            // with UTF-16LE). The target encoding is resolved once and the filter is
            // attached for every allowed path: a non-UTF-8 Bom configures it from
            // its own encoding, otherwise a non-UTF-8 outputEncoding configures it
            // whether the header is absent or a raw string. A UTF-8 outputEncoding
            // is a no-op because fputcsv already emits UTF-8.
            $transcodeEncoding = null;

            if ($bomToWrite !== null) {
                if ($bomToWrite instanceof Bom) {
                    $result = fwrite($stream, $bomToWrite->value);
                } else {
                    $result = fwrite($stream, $bomToWrite);
                }
                if ($result === false) {
                    throw new WriteException('Failed to write BOM to stream');
                }

                // If we are writing a non-UTF-8 BOM, we assume the user intends
                // the entire file to be encoded as such. We apply a stream filter
                // so fputcsv (which expects single-byte ASCII compatible sequences)
                // writes UTF-8 internally, but the filter transcodes it before it hits the stream.
                if ($bomToWrite instanceof Bom && !$bomToWrite->isUtf8()) {
                    $streamFilter = self::appendTranscodeFilter($stream, $bomToWrite->encoding(), 'BOM encoding');
                    $transcodeEncoding = $bomToWrite->encoding();
                }
            }

            if ($outputEncoding !== null) {
                if (self::isUtf8Encoding($outputEncoding)) {
                    $outputEncoding = null;
                } else {
                    $streamFilter = self::appendTranscodeFilter($stream, $outputEncoding, 'outputEncoding');
                    $transcodeEncoding = $outputEncoding;
                }
            }

            $separator = $this->separator;
            // For writer, "auto" means php default separator
            if ($separator === 'auto') {
                $separator = ',';
            }
            $escapeFormulas = $this->escapeFormulas;
            $doEscape = (bool) $escapeFormulas;

            // Narrow the callable type once for PHPStan
            /** @var callable(string, int): string|null $escapeFn */
            $escapeFn = is_callable($escapeFormulas) ? $escapeFormulas : null;

            // Headers: inline processing
            $headerSchema = !empty($this->headers) ? HeaderSchema::fromDefinition($this->headers) : null;

            if ($headerSchema !== null) {
                $headerRows = $headerSchema->headerRows();

                foreach ($headerRows as $row) {
                    $row = self::processRow($row, $doEscape, $escapeFn, $transcodeEncoding);
                    /** @var array<int|string, bool|float|int|string|null> $row */
                    $result = fputcsv($stream, $row, $separator, $this->enclosure, $this->escape, $this->eol);
                    if ($result === false) {
                        throw new WriteException('Failed to write headers to stream');
                    }
                }
            }

            // Data rows: inline processing
            $expectedWidth = $this->strict && $headerSchema !== null ? $headerSchema->columnCount() : null;
            foreach ($data as $row) {
                // Validate before flattening — raw row must match expected width
                if ($this->strict) {
                    $width = count($row);
                    if ($expectedWidth === null) {
                        $expectedWidth = $width;
                    } elseif ($width !== $expectedWidth) {
                        throw new WriteException("Row has {$width} columns, expected {$expectedWidth}");
                    }
                }

                // Flatten hierarchical rows according to the schema
                if ($headerSchema !== null) {
                    $row = $headerSchema->flattenRow((array) $row);
                }

                $row = self::processRow($row, $doEscape, $escapeFn, $transcodeEncoding);
                /** @var array<int|string, bool|float|int|string|null> $row */
                $result = fputcsv($stream, $row, $separator, $this->enclosure, $this->escape, $this->eol);
                if ($result === false) {
                    throw new WriteException('Failed to write line');
                }
            }
        } finally {
            if (is_resource($streamFilter)) {
                stream_filter_remove($streamFilter);
            }
        }
    }

    /**
     * Single flat pass that prepares a row for fputcsv: converts Stringable cells
     * to text, applies formula escaping (opt-in), serializes numbers (bool ->
     * "1"/"0", float via {@see CsvSupport::serializeFloat()}, non-finite floats are
     * rejected like the XLSX/ODS writers, null -> empty) and, when the whole
     * stream is transcoded, validates each text cell so invalid UTF-8 or an
     * unrepresentable character raises an explicit error instead of being
     * silently dropped by the stream filter.
     *
     * The loop iterates by value and only writes back cells that actually change,
     * so a row made of plain cells (string/int/null) is not copied. Numbers are
     * never mistaken for formulas: a negative float keeps its leading "-". The
     * colIndex counter keeps the escapeFormulas callable contract.
     *
     * @param array<mixed>                       $row
     * @param callable(string, int): string|null $escapeFn Custom escaper, or null for the default one.
     * @param string|null                        $transcodeEncoding Active full-stream target encoding, if any.
     * @return array<mixed>
     * @throws WriteException For non-finite floats, invalid UTF-8 or unrepresentable text.
     */
    private static function processRow(array $row, bool $escapeFormulas, $escapeFn, ?string $transcodeEncoding): array
    {
        $chars = self::FORMULA_ESCAPE_CHARS;
        $colIndex = 0;
        foreach ($row as $key => $cell) {
            if ($cell instanceof \Stringable) {
                $cell = (string) $cell;
                $row[$key] = $cell;
            }
            if (is_string($cell) || $cell === null || is_int($cell)) {
                if (is_string($cell) && $escapeFormulas) {
                    if ($escapeFn !== null) {
                        $cell = $escapeFn($cell, $colIndex);
                        $row[$key] = $cell;
                    } elseif ($cell !== '' && str_contains($chars, $cell[0])) {
                        $cell = "'" . $cell;
                        $row[$key] = $cell;
                    }
                }
                if (is_string($cell) && $transcodeEncoding !== null) {
                    if (preg_match('//u', $cell) !== 1) {
                        throw new WriteException('Invalid UTF-8 in CSV cell');
                    }
                    if (@iconv('UTF-8', $transcodeEncoding, $cell) === false) {
                        throw new WriteException('CSV cell cannot be represented in ' . $transcodeEncoding);
                    }
                }
                $colIndex++;
                continue;
            }
            if (is_bool($cell)) {
                $row[$key] = $cell ? '1' : '0';
            } elseif (is_float($cell)) {
                if (!is_finite($cell)) {
                    throw new WriteException('Cannot write a non-finite numeric value');
                }
                $row[$key] = CsvSupport::serializeFloat($cell);
            }
            $colIndex++;
        }
        return $row;
    }

    /**
     * Attach a UTF-8 -> $encoding write stream filter, verifying ext-iconv and the
     * encoding name up front so failures surface as explicit exceptions.
     *
     * @param resource $stream
     * @return resource
     * @throws WriteException If ext-iconv is unavailable.
     * @throws InvalidArgumentException If the encoding name is unknown or the filter cannot be created.
     */
    private static function appendTranscodeFilter($stream, string $encoding, string $context)
    {
        if (!extension_loaded('iconv')) {
            throw new WriteException("{$context} to {$encoding} requires the iconv extension.");
        }
        if (@iconv('UTF-8', $encoding, 'a') === false) {
            throw new InvalidArgumentException("Unknown encoding '{$encoding}' for {$context}.");
        }
        $filter = @stream_filter_append($stream, 'convert.iconv.UTF-8/' . $encoding, STREAM_FILTER_WRITE);
        if (!is_resource($filter)) {
            throw new InvalidArgumentException("Failed to attach {$context} filter for encoding '{$encoding}'.");
        }
        return $filter;
    }

    private function resolveBomToWrite(): Bom|string|null
    {
        if ($this->bom === true) {
            return Bom::Utf8;
        }
        if ($this->bom instanceof Bom) {
            return $this->bom;
        }
        if (is_string($this->bom) && $this->bom !== '') {
            return $this->bom;
        }
        return null;
    }

    private function assertValidEncodingOptions(Bom|string|null $bomToWrite, ?string $outputEncoding): void
    {
        if ($outputEncoding === null || $outputEncoding === '') {
            return;
        }
        if (!$bomToWrite instanceof Bom) {
            return;
        }
        if ($bomToWrite->isUtf8()) {
            if (!self::isUtf8Encoding($outputEncoding)) {
                throw new InvalidArgumentException(
                    'Do not combine a UTF-8 BOM with a non-UTF-8 outputEncoding. Disable the BOM or use UTF-8 output.',
                );
            }
            return;
        }
        throw new InvalidArgumentException(
            'Do not combine a non-UTF-8 BOM with outputEncoding; the BOM already configures stream transcoding.',
        );
    }

    private static function isUtf8Encoding(string $encoding): bool
    {
        return in_array(
            strtoupper(str_replace(['_', '-'], '', $encoding)),
            ['UTF8'],
            true,
        );
    }
}
