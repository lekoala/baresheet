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
    public string $eol = "\r\n";
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
        // Read a sample for detection
        $sample = fread($stream, 4096) ?: '';
        rewind($stream);

        // Auto-detect separator
        if ($this->separator === 'auto') {
            $this->separator = self::detectSeparator($sample);
        }

        // Check for a BOM in the sample
        $this->inputBOM = Bom::tryFromSequence($sample);

        if ($this->inputBOM !== null) {
            // Seek past the BOM
            fseek($stream, $this->inputBOM->length());

            // If it's not UTF-8, transcode the stream
            if (!$this->inputBOM->isUtf8()) {
                $encoding = $this->inputBOM->encoding();
                $filter = @stream_filter_append($stream, 'convert.iconv.' . $encoding . '/UTF-8', STREAM_FILTER_READ);
                if (!$filter) {
                    throw new \RuntimeException("Failed to append iconv filter for encoding $encoding. Ensure iconv extension is enabled.");
                }
                // BOM takes precedence over manual encoding
                $this->inputEncoding = null;
            }
        } elseif (($this->inputEncoding === null || $this->inputEncoding === 'auto') && $this->outputEncoding !== null) {
            // Fallback detection if we need to convert but have no BOM
            $detected = mb_detect_encoding($sample, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
            if ($detected && $detected !== 'UTF-8') {
                $this->inputEncoding = $detected;
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
            // fgetcsv returns [null] for blank lines.
            if ($this->skipEmptyLines && $line === [null]) {
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
                    $rowIdx = $count + 1;
                    throw new \RuntimeException("Row $rowIdx has $colCount columns, expected $expectedCols. Potential malformed data or unclosed quote.");
                }
            }

            if ($this->assoc) {
                // No headers yet, use first line as headers
                if ($headers === null) {
                    $headers = array_map('strval', $line);
                    continue;
                }
                $colCount = count($line);
                $expected = count($headers);
                if ($colCount !== $expected) {
                    $rowIdx = $count + 1;
                    throw new \RuntimeException("Row $rowIdx has $colCount columns, expected $expected");
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

        if (!feof($stream)) {
            $rowIdx = $count + 1;
            throw new \RuntimeException("Failed to parse CSV row $rowIdx. Potential malformed data or unclosed quote.");
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
