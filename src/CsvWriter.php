<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use InvalidArgumentException;
use LeKoala\Baresheet\Exception\WriteException;

/**
 * Zero-dependency CSV writer using native PHP fputcsv.
 *
 * @phpstan-import-type WritableRow from WriterInterface
 */
class CsvWriter implements WriterInterface
{
    public const MIMETYPE = 'text/csv';

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
        $stream = Spread::getMaxMemTempStream();
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
        $filename = Spread::ensureExtension($filename, 'csv');

        $stream = Spread::getOutputStream($filename);
        $this->writeInternal($stream, $data);
        fclose($stream);
        return true;
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function output(iterable $data, string $filename): void
    {
        $filename = Spread::ensureExtension($filename, 'csv');

        if ($this->stream) {
            $this->outputStream($data, $filename);
            return;
        }

        $content = $this->writeString($data);
        Spread::outputHeaders(self::MIMETYPE, $filename, strlen($content));
        echo $content;
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function outputStream(iterable $data, string $filename): void
    {
        Spread::outputHeaders(self::MIMETYPE, $filename);
        $stream = Spread::getOutputStream();
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

            $this->assertValidEncodingOptions($bomToWrite, $outputEncoding);

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
                }
            }

            // outputEncoding used to be applied per cell with mb_convert_encoding,
            // which left the CSV structure bytes (separator, enclosure, EOL) in
            // ASCII — producing files that mixed the target encoding with ASCII
            // (reproduced as 61002c62000d0a with UTF-16LE). When no BOM already
            // configures a stream filter, transcode the whole stream instead, like
            // the non-UTF-8 BOM path does. Per-cell encoding is then skipped so
            // the bytes are not converted twice. A UTF-8 outputEncoding is a no-op
            // because fputcsv already emits UTF-8.
            $transcodeEncoding = null;
            if ($outputEncoding !== null && $outputEncoding !== '') {
                if (self::isUtf8Encoding($outputEncoding)) {
                    $outputEncoding = null;
                } elseif ($bomToWrite === null) {
                    $streamFilter = self::appendTranscodeFilter($stream, $outputEncoding, 'outputEncoding');
                    $transcodeEncoding = $outputEncoding;
                    $outputEncoding = null;
                }
            }

            $separator = $this->separator;
            // For writer, "auto" means php default separator
            if ($separator === 'auto') {
                $separator = ',';
            }
            $escapeFormulas = $this->escapeFormulas;

            // Determine processing needs to avoid repetitive checks in the loop
            $hasEncoding = $outputEncoding !== null;
            $isCallable = is_callable($escapeFormulas);

        // NOTE: We intentionally inline the processing logic for both headers and data rows
        // instead of using a closure or generator. Benchmarks show that closures and generators
        // add ~150% overhead in tight loops for CSV writing. The code duplication below is
        // a deliberate trade-off for maximum performance when processing large datasets.

        // Narrow the callable type once for PHPStan
            /** @var callable(string, int): string|null $escapeFn */
            $escapeFn = $isCallable ? $escapeFormulas : null;

            // Headers: inline processing
            $headerSchema = !empty($this->headers) ? HeaderSchema::fromDefinition($this->headers) : null;

            if ($headerSchema !== null) {
                $headerRows = $headerSchema->headerRows();

                foreach ($headerRows as $row) {
                    if ($escapeFormulas || $hasEncoding) {
                        $row = self::stringifyStringables($row);
                        if ($escapeFormulas && $hasEncoding) {
                            if ($escapeFn !== null) {
                                $colIndex = 0;
                                foreach ($row as &$cell) {
                                    if (is_string($cell)) {
                                        /** @var string $outputEncoding */
                                        $cell = mb_convert_encoding((string) $escapeFn($cell, $colIndex), $outputEncoding);
                                    }
                                    $colIndex++;
                                }
                                unset($cell);
                            } else {
                                $chars = "=+-@\t\r";
                                foreach ($row as &$cell) {
                                    if (is_string($cell)) {
                                        if ($cell !== '' && str_contains($chars, $cell[0])) {
                                            $cell = "'" . $cell;
                                        }
                                        /** @var string $outputEncoding */
                                        $cell = mb_convert_encoding($cell, $outputEncoding);
                                    }
                                }
                                unset($cell);
                            }
                        } elseif ($escapeFormulas) {
                            if ($escapeFn !== null) {
                                $colIndex = 0;
                                foreach ($row as &$cell) {
                                    if (is_string($cell)) {
                                        $cell = $escapeFn($cell, $colIndex);
                                    }
                                    $colIndex++;
                                }
                                unset($cell);
                            } else {
                                $row = self::escapeRow($row);
                            }
                        } elseif ($hasEncoding) {
                            foreach ($row as &$v) {
                                if (is_string($v)) {
                                    /** @var string $outputEncoding */
                                    $v = mb_convert_encoding($v, $outputEncoding);
                                }
                            }
                            unset($v);
                        }
                    }
                    self::serializeTypes($row, $transcodeEncoding);
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

                if ($escapeFormulas || $hasEncoding) {
                    $row = self::stringifyStringables($row);
                    if ($escapeFormulas && $hasEncoding) {
                        if ($escapeFn !== null) {
                            $colIndex = 0;
                            foreach ($row as &$cell) {
                                if (is_string($cell)) {
                                    /** @var string $outputEncoding */
                                    $cell = mb_convert_encoding((string) $escapeFn($cell, $colIndex), $outputEncoding);
                                }
                                $colIndex++;
                            }
                            unset($cell);
                        } else {
                            $chars = "=+-@\t\r";
                            foreach ($row as &$cell) {
                                if (is_string($cell)) {
                                    if ($cell !== '' && str_contains($chars, $cell[0])) {
                                        $cell = "'" . $cell;
                                    }
                                    /** @var string $outputEncoding */
                                    $cell = mb_convert_encoding($cell, $outputEncoding);
                                }
                            }
                            unset($cell);
                        }
                    } elseif ($escapeFormulas) {
                        if ($escapeFn !== null) {
                            $colIndex = 0;
                            foreach ($row as &$cell) {
                                if (is_string($cell)) {
                                    $cell = $escapeFn($cell, $colIndex);
                                }
                                $colIndex++;
                            }
                            unset($cell);
                        } else {
                            $row = self::escapeRow($row);
                        }
                    } elseif ($hasEncoding) {
                        foreach ($row as &$v) {
                            if (is_string($v)) {
                                /** @var string $outputEncoding */
                                $v = mb_convert_encoding($v, $outputEncoding);
                            }
                        }
                        unset($v);
                    }
                }
                self::serializeTypes($row, $transcodeEncoding);
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
     * Prefix dangerous formula characters with a single-quote to prevent injection.
     *
     * @param array<mixed> $row
     * @return array<mixed>
     */
    private static function escapeRow(array $row): array
    {
        $chars = "=+-@\t\r";
        foreach ($row as &$cell) {
            if (is_string($cell) && $cell !== '' && str_contains($chars, $cell[0])) {
                $cell = "'" . $cell;
            }
        }
        return $row;
    }

    /**
     * Normalize Stringable cells to strings before the escaping/encoding steps so
     * they are treated as the text they produce. Numbers are deliberately left
     * untouched here: a numeric cell must never be mistaken for a formula (a
     * negative float must not gain a "'" prefix), and the scalar serialization
     * happens only in {@see serializeTypes()}, after escaping.
     *
     * @param array<mixed> $row
     * @return array<mixed>
     */
    private static function stringifyStringables(array $row): array
    {
        foreach ($row as &$cell) {
            if ($cell instanceof \Stringable) {
                $cell = (string) $cell;
            }
        }
        unset($cell);
        return $row;
    }

    /**
     * Serialize the cell values per the explicit CSV contract just before fputcsv:
     * Stringable -> its string form, bool -> "1"/"0", float via
     * {@see Spread::serializeFloat()} (non-finite floats are rejected like the
     * XLSX/ODS writers), null -> empty.
     *
     * In the common case where no escaping/encoding ran, this is the single pass
     * over the row. When the whole stream is transcoded ($transcodeEncoding is
     * set), every text cell is additionally validated so invalid UTF-8 or an
     * unrepresentable character raises an explicit error instead of producing a
     * silently truncated/cleaned file.
     *
     * @param array<mixed>  $row Modified in place to avoid a copy per row.
     * @param string|null   $transcodeEncoding Active full-stream target encoding, if any.
     * @throws WriteException For non-finite floats, invalid UTF-8 or unrepresentable text.
     */
    private static function serializeTypes(array &$row, ?string $transcodeEncoding = null): void
    {
        if ($transcodeEncoding === null) {
            // Fast path (no full-stream transcoding): one cheap guard per cell.
            foreach ($row as &$cell) {
                if (is_string($cell) || $cell === null || is_int($cell)) {
                    continue;
                }
                if ($cell instanceof \Stringable) {
                    $cell = (string) $cell;
                } elseif (is_bool($cell)) {
                    $cell = $cell ? '1' : '0';
                } elseif (is_float($cell)) {
                    if (!is_finite($cell)) {
                        throw new WriteException('Cannot write a non-finite numeric value');
                    }
                    $cell = Spread::serializeFloat($cell);
                }
            }
            unset($cell);
            return;
        }

        // Transcoding mode: every text cell must be valid UTF-8 and representable.
        foreach ($row as &$cell) {
            if (is_string($cell) || $cell === null || is_int($cell)) {
                if (is_string($cell)) {
                    if (preg_match('//u', $cell) !== 1) {
                        throw new WriteException('Invalid UTF-8 in CSV cell');
                    }
                    if (@iconv('UTF-8', $transcodeEncoding, $cell) === false) {
                        throw new WriteException('CSV cell cannot be represented in ' . $transcodeEncoding);
                    }
                }
                continue;
            }
            if ($cell instanceof \Stringable) {
                $cell = (string) $cell;
            } elseif (is_bool($cell)) {
                $cell = $cell ? '1' : '0';
            } elseif (is_float($cell)) {
                if (!is_finite($cell)) {
                    throw new WriteException('Cannot write a non-finite numeric value');
                }
                $cell = Spread::serializeFloat($cell);
            }
        }
        unset($cell);
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
