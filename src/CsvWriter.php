<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use RuntimeException;
use LeKoala\Baresheet\Bom;

/**
 * Zero-dependency CSV writer using native PHP fputcsv.
 */
class CsvWriter implements WriterInterface
{
    public string $separator = ",";
    public string $enclosure = "\"";
    public string $escape = "";
    public string $eol = "\n";
    public bool|Bom|string $bom = true;
    public bool $stream = true;
    public bool $escapeFormulas = false;
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
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     * @return resource The opened stream containing the data. It is the caller's responsibility to close it.
     */
    public function writeStream(iterable $data, ?Options $options = null)
    {
        $options?->applyTo($this);

        $stream = Spread::getMaxMemTempStream();
        $this->writeInternal($stream, $data);
        rewind($stream);
        return $stream;
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeString(iterable $data, ?Options $options = null): string
    {
        $stream = $this->writeStream($data, $options);
        $contents = stream_get_contents($stream);
        fclose($stream);
        return $contents !== false ? $contents : '';
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeFile(iterable $data, string $filename, ?Options $options = null): bool
    {
        $options?->applyTo($this);
        $filename = Spread::ensureExtension($filename, 'csv');

        $stream = Spread::getOutputStream($filename);
        $this->writeInternal($stream, $data);
        fclose($stream);
        return true;
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function output(iterable $data, string $filename, ?Options $options = null): void
    {
        $options?->applyTo($this);
        $filename = Spread::ensureExtension($filename, 'csv');

        if ($this->stream) {
            $this->outputStream($data, $filename);
            return;
        }

        $content = $this->writeString($data);
        Spread::outputHeaders('text/csv', $filename, strlen($content));
        echo $content;
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function outputStream(iterable $data, string $filename, ?Options $options = null): void
    {
        $options?->applyTo($this);

        Spread::outputHeaders('text/csv', $filename);
        $stream = Spread::getOutputStream();
        $this->writeInternal($stream, $data);
        fclose($stream);
    }

    // -- Internal --

    /**
     * @param resource $stream
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    private function writeInternal($stream, iterable $data): void
    {
        $bomToWrite = null;
        if ($this->bom === true) {
            $bomToWrite = Bom::Utf8;
        } elseif ($this->bom instanceof Bom) {
            $bomToWrite = $this->bom;
        } elseif (is_string($this->bom) && $this->bom !== '') {
            $result = fputs($stream, $this->bom);
            if ($result === false) {
                throw new RuntimeException("Failed to write BOM to stream");
            }
        }

        if ($bomToWrite !== null) {
            $result = fputs($stream, $bomToWrite->value);
            if ($result === false) {
                throw new RuntimeException("Failed to write BOM to stream");
            }

            // If we are writing a non-UTF-8 BOM, we assume the user intends
            // the entire file to be encoded as such. We apply a stream filter
            // so fputcsv (which expects single-byte ASCII compatible sequences)
            // writes UTF-8 internally, but the filter transcodes it before it hits the stream.
            if (!$bomToWrite->isUtf8()) {
                stream_filter_append($stream, 'convert.iconv.UTF-8/' . $bomToWrite->encoding(), STREAM_FILTER_WRITE);
            }
        }

        $separator = $this->separator;
        // For writer, "auto" means php default separator
        if ($separator === "auto") {
            $separator = ",";
        }
        $escapeFormulas = $this->escapeFormulas;
        $outputEncoding = $this->outputEncoding;

        // Determine processing closure to avoid repetitive checks in the loop
        $hasEncoding = ($outputEncoding !== null && $outputEncoding !== '');
        /** @var \Closure(array<mixed>): array<int|string, bool|float|int|string|null> $processRow */
        $processRow = static function (array $row) use ($escapeFormulas, $hasEncoding, $outputEncoding): array {
            if ($escapeFormulas) {
                $row = self::escapeRow($row);
            }
            if ($hasEncoding) {
                /** @var string $outputEncoding */
                $row = array_map(static fn($v) => is_string($v) ? mb_convert_encoding($v, $outputEncoding) : $v, $row);
            }
            /** @var array<int|string, bool|float|int|string|null> $row */
            return $row;
        };

        if (!empty($this->headers)) {
            $row = $processRow($this->headers);
            $result = fputcsv($stream, $row, $separator, $this->enclosure, $this->escape, $this->eol);
            if ($result === false) {
                throw new RuntimeException("Failed to write headers to stream");
            }
        }

        foreach ($data as $row) {
            $row = $processRow($row);
            $result = fputcsv($stream, $row, $separator, $this->enclosure, $this->escape, $this->eol);
            if ($result === false) {
                throw new RuntimeException("Failed to write line");
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
}
