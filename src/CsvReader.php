<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Generator;
use LeKoala\Baresheet\Bom;

/**
 * Zero-dependency CSV reader using native PHP fgetcsv.
 */
class CsvReader implements ReaderInterface
{
    private ?Bom $inputBOM = null;

    public bool $assoc = false;
    public bool $strict = false;
    public ?int $limit = null;
    public int $offset = 0;
    public bool $skipEmptyLines = true;
    public string $separator = "auto";
    public string $enclosure = "\"";
    public string $escape = "";
    public ?string $inputEncoding = null;
    public ?string $outputEncoding = null;

    public function __construct(?Options $options = null)
    {
        $options?->applyTo($this);
    }

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
     * Get the detected input BOM sequence, if any.
     */
    public function getInputBOM(): ?Bom
    {
        return $this->inputBOM;
    }

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

        // Read up to 4 bytes to check for a BOM
        $bomSample = fread($stream, 4) ?: '';
        rewind($stream);
        $this->inputBOM = Bom::tryFromSequence($bomSample);

        if ($this->inputBOM !== null) {
            // Seek past the BOM, which varies in length
            fseek($stream, $this->inputBOM->length());

            // If it's a UTF-16 or UTF-32 BOM, we MUST transcode the stream to UTF-8
            // on the fly so fgetcsv can parse single-byte delimiters and newlines correctly.
            if (!$this->inputBOM->isUtf8()) {
                stream_filter_append($stream, 'convert.iconv.' . $this->inputBOM->encoding() . '/UTF-8', STREAM_FILTER_READ);
            }
        }

        $headers = null;
        $separator = $this->separator;
        $count = 0;
        $yieldCount = 0;
        $expectedCols = null;
        $doEncode = $this->inputEncoding && $this->outputEncoding;

        while (
            !feof($stream)
            &&
            ($line = fgetcsv($stream, null, $separator, $this->enclosure, $this->escape)) !== false
        ) {
            if ($this->skipEmptyLines && (count($line) === 1 && $line[0] === null)) {
                continue;
            }

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

            if ($count < $this->offset) {
                $count++;
                continue;
            }

            yield $line;
            $count++;
            $yieldCount++;
            if ($this->limit !== null && $yieldCount >= $this->limit) {
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
