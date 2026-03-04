<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use RuntimeException;

/**
 * Zero-dependency CSV writer using native PHP fputcsv.
 */
class CsvWriter implements WriterInterface
{
    public const BOM = "\xef\xbb\xbf";

    public string $separator = ",";
    public string $enclosure = "\"";
    public string $escape = "";
    public string $eol = "\n";
    public bool $bom = true;
    public bool $stream = false;
    public bool $escapeFormulas = false;
    public ?string $outputEncoding = null;
    /**
     * @var string[]
     */
    public array $headers = [];

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeString(iterable $data, ?Options $options = null): string
    {
        $options?->applyTo($this);

        $stream = Spread::getMaxMemTempStream();
        $this->writeInternal($stream, $data);
        $contents = Spread::getStreamContents($stream);
        fclose($stream);
        return $contents;
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeFile(iterable $data, string $filename, ?Options $options = null): bool
    {
        $options?->applyTo($this);

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

        Spread::outputHeaders('text/csv', $filename);
        $stream = Spread::getOutputStream();
        $this->writeInternal($stream, $data);
        fclose($stream);
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function outputStream(iterable $data, string $filename, ?Options $options = null): void
    {
        $options?->applyTo($this);

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
        if ($this->bom) {
            fputs($stream, self::BOM);
        }

        $separator = $this->separator === 'auto' ? ',' : $this->separator;
        if (!empty($this->headers)) {
            $row = $this->escapeRow($this->headers);
            if ($this->outputEncoding) {
                $row = array_map(fn($v) => is_string($v) ? mb_convert_encoding($v, $this->outputEncoding) : $v, $row);
            }
            /** @var array<int|string, bool|float|int|string|null> $row */
            fputcsv($stream, $row, $separator, $this->enclosure, $this->escape, $this->eol);
        }
        foreach ($data as $row) {
            $row = $this->escapeRow($row);
            if ($this->outputEncoding) {
                $row = array_map(fn($v) => is_string($v) ? mb_convert_encoding($v, $this->outputEncoding) : $v, $row);
            }
            /** @var array<int|string, bool|float|int|string|null> $row */
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
    private function escapeRow(array $row): array
    {
        if (!$this->escapeFormulas) {
            return $row;
        }
        foreach ($row as &$cell) {
            if (is_string($cell) && $cell !== '') {
                $firstChar = $cell[0];
                if (
                    $firstChar === '=' ||
                    $firstChar === '+' ||
                    $firstChar === '-' ||
                    $firstChar === '@' ||
                    $firstChar === "\t" ||
                    $firstChar === "\r"
                ) {
                    $cell = "'" . $cell;
                }
            }
        }
        return $row;
    }
}
