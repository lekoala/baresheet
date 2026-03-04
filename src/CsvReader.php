<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Generator;

/**
 * Zero-dependency CSV reader using native PHP fgetcsv.
 */
class CsvReader implements ReaderInterface
{
    public const BOM = "\xef\xbb\xbf";

    public string $separator = "auto";
    public string $enclosure = "\"";
    public string $escape = "";
    public bool $assoc = false;
    public bool $strict = false;
    public ?string $inputEncoding = null;
    public ?string $outputEncoding = null;
    public ?int $limit = null;

    /**
     * @return Generator<mixed>
     */
    public function readString(string $contents, ?Options $options = null): Generator
    {
        $options?->applyTo($this);
        $temp = Spread::getMaxMemTempStream();
        fwrite($temp, $contents);
        rewind($temp);
        return $this->parseStream($temp);
    }

    /**
     * @param resource $stream
     * @return Generator<mixed>
     */
    public function readStream($stream, ?Options $options = null): Generator
    {
        $options?->applyTo($this);
        return $this->parseStream($stream);
    }

    /**
     * @return Generator<mixed>
     */
    public function readFile(string $filename, ?Options $options = null): Generator
    {
        $options?->applyTo($this);
        $stream = Spread::getInputStream($filename);
        yield from $this->parseStream($stream);
    }

    // -- Internal --

    /**
     * @param resource $stream
     * @return Generator<mixed>
     */
    private function parseStream($stream): Generator
    {
        // Auto-detect separator from first ~4KB before consuming the stream
        if ($this->separator === 'auto') {
            $sample = fread($stream, 4096) ?: '';
            rewind($stream);
            $this->separator = self::detectSeparator($sample);
        }

        if (fgets($stream, 4) !== self::BOM) {
            rewind($stream);
        }
        $headers = null;
        $separator = $this->separator;
        $count = 0;
        $expectedCols = null;
        $doEncode = $this->inputEncoding && $this->outputEncoding;

        while (
            !feof($stream)
            &&
            ($line = fgetcsv($stream, null, $separator, $this->enclosure, $this->escape)) !== false
        ) {
            if ($doEncode) {
                $line = array_map(fn($v) => is_string($v)
                    ? mb_convert_encoding($v, (string)$this->outputEncoding, (string)$this->inputEncoding)
                    : $v, $line);
            }

            if ($this->strict) {
                $colCount = count($line);
                if ($expectedCols === null) {
                    $expectedCols = $colCount;
                } elseif ($colCount !== $expectedCols) {
                    throw new \RuntimeException("Row has $colCount columns, expected $expectedCols");
                }
            }

            if ($this->assoc) {
                // No headers yet, use first line as headers
                if ($headers === null) {
                    $headers = array_map('strval', $line);
                    continue;
                }
                $line = array_combine($headers, $line);
            }
            yield $line;
            $count++;
            if ($this->limit !== null && $count >= $this->limit) {
                return;
            }
        }
    }

    /**
     * Detect the most likely delimiter from a text sample.
     */
    public static function detectSeparator(string $sample): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $sample, 10) ?: [];
        if (empty($lines)) {
            return ',';
        }

        $candidates = [',', ';', '|', "\t"];
        $scores = array_fill_keys($candidates, 0);

        foreach ($lines as $line) {
            $clean = preg_replace('/"[^"]*"/', '', $line) ?? '';
            foreach ($candidates as $sep) {
                $scores[$sep] += substr_count($clean, $sep);
            }
        }

        arsort($scores);
        $best = array_key_first($scores);

        return $scores[$best] > 0 ? $best : ',';
    }
}
