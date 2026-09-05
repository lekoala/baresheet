<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTimeInterface;
use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Internal\DirectZipWriter;
use LeKoala\Baresheet\Value\DurationValue;
use LeKoala\Baresheet\Value\TimeValue;

/**
 * Zero-dependency ODS writer producing raw XML packaged by DirectZipWriter.
 *
 * @phpstan-type WritableRow array<int|string, bool|float|int|string|\Stringable|DateTimeInterface|\Time\Duration|TimeValue|DurationValue|null>
 */
class OdsWriter implements WriterInterface
{
    public const MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet';
    private const BUFFER_SIZE = 1000;

    /**
     * @var Meta|array<string, mixed>|null Optional metadata for the generated document.
     */
    public Meta|array|null $meta = null;
    public string|int|null $sheet = null;
    public bool $boldHeaders = false;
    public bool $stream = true;
    public ?string $tempPath = null;
    /**
     * @var string[]
     */
    public array $headers = [];
    /**
     * @var bool If true, canonically numeric strings are written as numeric cells
     *           (legacy behavior). If false, a PHP string always means spreadsheet text.
     *
     *           INTERIM DEFAULT: true to preserve BC behavior. Flip to false
     *           for the 1.0 release together with Options::$inferNumericStrings.
     */
    public bool $inferNumericStrings = true;

    public function __construct(?Options $options = null)
    {
        $options?->applyTo($this);
    }

    // -- Write API --

    /**
     * @param iterable<WritableRow> $data
     * @return resource The opened stream containing the data. It is the caller's responsibility to close it.
     */
    public function writeStream(iterable $data)
    {
        $stream = Spread::getMaxMemTempStream();
        $this->buildDirectZip($data, $stream);
        rewind($stream);
        return $stream;
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
        $filename = Spread::ensureExtension($filename, 'ods');
        return $this->buildFile($data, $filename);
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function output(iterable $data, string $filename): void
    {
        if ($this->stream) {
            $this->outputStream($data, $filename);
            return;
        }

        $this->outputBuffered($data, $filename);
    }

    /**
     * Stream ODS straight to php://output, without building it first.
     *
     * Bytes reach the client as they are produced, so the download starts at
     * once instead of after the whole archive is built, and no temporary file
     * is written. Peak memory is the same either way — the buffered path spills
     * to disk rather than holding the archive. What streaming costs is the
     * Content-Length, unknown until the archive is finished; set $stream to
     * false to buffer and send it.
     *
     * DirectZipWriter keeps a non-seekable target on classic ZIP, announcing
     * each entry's sizes in a trailing data descriptor. Excel accepts that; it
     * is ZIP64 it refuses, and a streamed archive now fails rather than
     * promoting to it past 4 GiB.
     *
     * @param iterable<WritableRow> $data
     */
    public function outputStream(iterable $data, string $filename): void
    {
        $filename = Spread::ensureExtension($filename, 'ods');
        Spread::outputHeaders(self::MIMETYPE, $filename);

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new WriteException('Failed to open php://output');
        }

        try {
            $this->buildDirectZip($data, $output);
        } finally {
            fclose($output);
        }
    }

    /**
     * @param iterable<WritableRow> $data
     */
    private function outputBuffered(iterable $data, string $filename): void
    {
        $filename = Spread::ensureExtension($filename, 'ods');
        $tempFilename = Spread::getTempFilename();
        try {
            $this->buildFile($data, $tempFilename);

            $size = filesize($tempFilename);
            Spread::outputHeaders(self::MIMETYPE, $filename, $size !== false ? $size : null);
            readfile($tempFilename);
        } finally {
            if (is_file($tempFilename)) {
                unlink($tempFilename);
            }
        }
    }

    // -- Internal --

    /**
     * @param iterable<WritableRow> $data
     */
    private function buildFile(iterable $data, string $filename): bool
    {
        $destinationDir = dirname($filename);
        if (!is_writable($destinationDir)) {
            throw new WriteException("Directory '{$destinationDir}' is not writable");
        }

        // Build in tempPath first when the destination filesystem is unsuitable
        // for direct writes, then copy the completed archive into place.
        if ($this->tempPath) {
            $baseName = tempnam($this->tempPath, 'ods_direct');
            if (!$baseName) {
                throw new WriteException('Failed to create temp file in ' . $this->tempPath);
            }
        } else {
            $baseName = $filename;
        }

        $stream = false;
        try {
            $stream = @fopen($baseName, 'w+b');
            if ($stream === false) {
                throw new WriteException("Failed to open '{$baseName}' for writing");
            }

            $this->buildDirectZip($data, $stream);

            // Copy from temp location to final destination when using tempPath
            if ($this->tempPath) {
                if (!copy($baseName, $filename)) {
                    throw new WriteException("Failed to copy '{$baseName}' to '{$filename}'");
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // Never touch the caller's destination on failure; only clean up the
            // temporary built by this operation when tempPath is in use.
            if ($this->tempPath && is_file($baseName)) {
                unlink($baseName);
            }
        }

        return true;
    }

    /**
     * Package the full ODS into the target stream via DirectZipWriter.
     *
     * @param iterable<WritableRow> $data
     * @param resource $stream
     */
    private function buildDirectZip(iterable $data, $stream): void
    {
        $zip = new DirectZipWriter($stream, compressionLevel: 6);

        // ODF requires this to be the first entry, stored, with no local extra
        // field. DirectZipWriter's known STORE string path writes final header
        // metadata up front even when the target is non-seekable.
        $zip->addString('mimetype', self::MIMETYPE, store: true);
        $zip->addString('META-INF/manifest.xml', $this->genManifest());
        $zip->addString('meta.xml', $this->genMeta());
        $zip->addString('styles.xml', $this->genStyles());
        $zip->addCallback(
            'content.xml',
            function (callable $write) use ($data): void {
                $this->writeContent($data, $write);
            },
        );
        $zip->finish();
    }

    // -- XML generators --

    /**
     * @param iterable<WritableRow> $data
     * @param callable(string):void $write
     */
    private function writeContent(iterable $data, callable $write): void
    {
        $sheetVal = is_string($this->sheet) ? $this->sheet : 'Sheet1';
        $sheetName = Spread::escapeXmlAttr(Spread::validateSheetName($sheetVal));
        $cellContext = static fn(int $row, int $column): string => (
            "sheet '{$sheetVal}', cell " . Spread::cellAddress($row - 1, $column)
        );

        $write(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . "\n"
            . '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            . ' xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0"'
            . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
            . ' office:version="1.3">'
            // Styles block (mandatory for some readers like OpenSpout)
            . '<office:automatic-styles>'
            . '<style:style style:name="ta1" style:family="table"/>'
            . '<style:style style:name="bold" style:family="table-cell">'
            . '<style:text-properties fo:font-weight="bold"/>'
            . '</style:style>'
            // Time of day vs elapsed duration: distinguished via the standard
            // number:truncate-on-overflow attribute on the data style, not via
            // the magnitude of the value (a duration may be shorter than a day).
            . '<style:style style:name="ce-time" style:family="table-cell" style:data-style-name="timeOfDay"/>'
            . '<style:style style:name="ce-duration" style:family="table-cell" style:data-style-name="durationTime"/>'
            . '<number:time-style style:name="timeOfDay">'
            . '<number:hours number:style="long"/><number:text>:</number:text>'
            . '<number:minutes number:style="long"/><number:text>:</number:text>'
            . '<number:seconds number:style="long"/>'
            . '</number:time-style>'
            . '<number:time-style style:name="durationTime" number:truncate-on-overflow="false">'
            . '<number:hours number:style="long"/><number:text>:</number:text>'
            . '<number:minutes number:style="long"/><number:text>:</number:text>'
            . '<number:seconds number:style="long"/>'
            . '</number:time-style>'
            . '</office:automatic-styles>'
            . '<office:body><office:spreadsheet>'
            . '<table:table table:name="'
            . $sheetName
            . '" table:style-name="ta1">',
        );

        $headerSchema = !empty($this->headers) ? HeaderSchema::fromDefinition($this->headers) : null;
        if ($headerSchema !== null) {
            $headerRowsRemaining = count($headerSchema->headerRows());
        } else {
            $headerRowsRemaining = 0;
            if ($this->boldHeaders) {
                $headerRowsRemaining = 1;
            } elseif (is_array($data)) {
                $firstKey = array_key_first($data);
                $firstRow = $firstKey !== null ? $data[$firstKey] : null;
                if (is_array($firstRow) && !array_is_list($firstRow)) {
                    $headerRowsRemaining = 1;
                }
            }
        }
        $wrappedData = $this->wrapRows($data, $headerSchema);

        $boldHeadersOpt = $this->boldHeaders;
        $bufferSizeOpt = self::BUFFER_SIZE;
        $buffer = '';
        $r = 0;

        foreach ($wrappedData as $row) {
            $r++;
            $buffer .= '<table:table-row>';

            $rowCellStyle = $headerRowsRemaining > 0 && $boldHeadersOpt ? ' table:style-name="bold"' : '';

            $i = 0;

            foreach ($row as $value) {
                if ($value instanceof \Time\Duration) {
                    $iso = Spread::formatIsoDuration($value);
                    $display = Spread::stringifyDuration($value);
                    $buffer .=
                        '<table:table-cell'
                        . ' table:style-name="ce-duration"'
                        . ' office:value-type="time"'
                        . ' office:time-value="'
                        . Spread::escapeXmlAttr($iso)
                        . '">'
                        . '<text:p>'
                        . Spread::escapeXml($display)
                        . '</text:p>'
                        . '</table:table-cell>';
                } elseif ($value instanceof DurationValue) {
                    $iso = Spread::formatIsoDurationComponents(
                        $value->negative,
                        $value->hours,
                        $value->minutes,
                        $value->seconds,
                        $value->microsecond,
                    );
                    $display = (string) $value;
                    $buffer .=
                        '<table:table-cell'
                        . ' table:style-name="ce-duration"'
                        . ' office:value-type="time"'
                        . ' office:time-value="'
                        . Spread::escapeXmlAttr($iso)
                        . '">'
                        . '<text:p>'
                        . Spread::escapeXml($display)
                        . '</text:p>'
                        . '</table:table-cell>';
                } elseif ($value instanceof TimeValue) {
                    $iso = Spread::formatIsoDurationComponents(
                        false,
                        $value->hour,
                        $value->minute,
                        $value->second,
                        $value->microsecond,
                    );
                    $display = (string) $value;
                    $buffer .=
                        '<table:table-cell'
                        . ' table:style-name="ce-time"'
                        . ' office:value-type="time"'
                        . ' office:time-value="'
                        . Spread::escapeXmlAttr($iso)
                        . '">'
                        . '<text:p>'
                        . Spread::escapeXml($display)
                        . '</text:p>'
                        . '</table:table-cell>';
                } elseif ($value instanceof DateTimeInterface) {
                    $isoDate = $value->format('u') === '000000'
                        ? $value->format('Y-m-d\TH:i:s')
                        : $value->format('Y-m-d\TH:i:s.u');
                    $display = $value->format('Y-m-d H:i:s');
                    $buffer .=
                        '<table:table-cell'
                        . $rowCellStyle
                        . ' office:value-type="date"'
                        . ' office:date-value="'
                        . $isoDate
                        . '">'
                        . '<text:p>'
                        . $display
                        . '</text:p>'
                        . '</table:table-cell>';
                } elseif (is_bool($value)) {
                    $bool = $value ? 'true' : 'false';
                    $buffer .=
                        '<table:table-cell'
                        . $rowCellStyle
                        . ' office:value-type="boolean"'
                        . ' office:boolean-value="'
                        . $bool
                        . '">'
                        . '<text:p>'
                        . $bool
                        . '</text:p>'
                        . '</table:table-cell>';
                } elseif ($value === null || $value === '') {
                    $buffer .= '<table:table-cell' . $rowCellStyle . '/>';
                } elseif (is_int($value) || is_float($value)) {
                    if (is_float($value) && !is_finite($value)) {
                        throw new WriteException('Cannot write a non-finite numeric value');
                    }
                    $strValue = is_float($value) ? Spread::serializeFloat($value) : (string) $value;
                    $buffer .=
                        '<table:table-cell'
                        . $rowCellStyle
                        . ' office:value-type="float"'
                        . ' office:value="'
                        . $strValue
                        . '">'
                        . '<text:p>'
                        . $strValue
                        . '</text:p>'
                        . '</table:table-cell>';
                } elseif (
                    $this->inferNumericStrings
                    && Spread::isNumericCellValue($value)
                    && (is_scalar($value)
                    || $value instanceof \Stringable)
                ) {
                    $strValue = (string) $value;
                    $buffer .=
                        '<table:table-cell'
                        . $rowCellStyle
                        . ' office:value-type="float"'
                        . ' office:value="'
                        . $strValue
                        . '">'
                        . '<text:p>'
                        . $strValue
                        . '</text:p>'
                        . '</table:table-cell>';
                } else {
                    if ($value instanceof \Stringable) {
                        $strValue = $value->__toString();
                    } elseif (is_scalar($value)) {
                        $strValue = (string) $value;
                    } else {
                        $strValue = '';
                    }
                    $escaped = Spread::escapeXml($strValue, $cellContext($r, $i));
                    $buffer .=
                        '<table:table-cell'
                        . $rowCellStyle
                        . ' office:value-type="string">'
                        . '<text:p>'
                        . $escaped
                        . '</text:p>'
                        . '</table:table-cell>';
                }
                $i++;
            }
            if ($headerRowsRemaining > 0) {
                $headerRowsRemaining--;
            }
            $buffer .= '</table:table-row>';
            if (($r % $bufferSizeOpt) === 0) {
                $write($buffer);
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $write($buffer);
        }

        $write('</table:table></office:spreadsheet></office:body></office:document-content>');
    }

    /**
     * Wrap data with header rows (flat or hierarchical) according to the schema.
     *
     * @param iterable<WritableRow> $data
     * @return iterable<array<int|string, mixed>>
     */
    private function wrapRows(iterable $data, ?HeaderSchema $schema): iterable
    {
        if ($schema !== null) {
            yield from $schema->headerRows();
            foreach ($data as $row) {
                yield $schema->flattenRow((array) $row);
            }
            return;
        }

        $firstSeen = false;
        $columnKeys = null;
        foreach ($data as $row) {
            $isList = array_is_list($row);
            if (!$firstSeen) {
                $firstSeen = true;
                if (!$isList) {
                    // The first associative row defines the columns: its keys become
                    // the header and every following associative row is aligned on them.
                    $columnKeys = array_keys($row);
                    yield $columnKeys;
                }
            }

            if ($isList || $columnKeys === null) {
                // Positional rows are written as-is. Once the first row is a list no
                // header is invented mid-stream, so later associative rows keep their
                // array order too (matching positional semantics).
                yield array_values($row);
                continue;
            }

            if (array_keys($row) === $columnKeys) {
                yield array_values($row);
                continue;
            }

            $rowByKey = [];
            foreach ($row as $key => $value) {
                $rowByKey[$key] = $value;
            }
            // Unknown keys would be silently dropped by alignment, so they are
            // rejected instead of losing data.
            foreach ($rowByKey as $key => $_rowValue) {
                if (!in_array($key, $columnKeys, true)) {
                    $sheetName = is_string($this->sheet) ? $this->sheet : 'Sheet1';
                    throw new WriteException(
                        "Row contains column key '{$key}' absent from the header (sheet '{$sheetName}')",
                    );
                }
            }
            $aligned = [];
            foreach ($columnKeys as $key) {
                $aligned[] = array_key_exists($key, $rowByKey) ? $rowByKey[$key] : null;
            }
            yield $aligned;
        }
    }

    private function genManifest(): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">
                <manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="application/vnd.oasis.opendocument.spreadsheet"/>
                <manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>
                <manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>
                <manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/>
            </manifest:manifest>
            XML;
    }

    private function genMeta(): string
    {
        $metaObj = is_array($this->meta) ? Meta::fromArray($this->meta) : $this->meta;
        $creator = Spread::escapeXml($metaObj->creator ?? '', 'metadata creator');
        $titleVal = $metaObj?->title;
        $title = $titleVal ? '<dc:title>' . Spread::escapeXml($titleVal, 'metadata title') . '</dc:title>' : '';
        $subjectVal = $metaObj?->subject;
        $subject = $subjectVal
            ? '<dc:subject>' . Spread::escapeXml($subjectVal, 'metadata subject') . '</dc:subject>'
            : '';
        $keywordsVal = $metaObj?->keywords;
        $keywords = '';
        if ($keywordsVal !== null && $keywordsVal !== '') {
            $parts = array_filter(array_map('trim', explode(',', $keywordsVal)));
            foreach ($parts as $part) {
                $keywords .= '<meta:keyword>' . Spread::escapeXml($part, 'metadata keywords') . '</meta:keyword>';
            }
        }
        $descriptionVal = $metaObj?->description;
        $description = $descriptionVal
            ? '<dc:description>' . Spread::escapeXml($descriptionVal, 'metadata description') . '</dc:description>'
            : '';
        $languageVal = $metaObj?->language;
        $language = $languageVal
            ? '<dc:language>' . Spread::escapeXml($languageVal, 'metadata language') . '</dc:language>'
            : '';
        $date = date('Y-m-d\TH:i:s');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <office:document-meta xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
                xmlns:dc="http://purl.org/dc/elements/1.1/"
                xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"
                office:version="1.3">
                <office:meta>
                    <meta:initial-creator>{$creator}</meta:initial-creator>
                    <dc:creator>{$creator}</dc:creator>
                    {$title}
                    {$subject}
                    {$keywords}
                    {$description}
                    {$language}
                    <meta:creation-date>{$date}</meta:creation-date>
                    <dc:date>{$date}</dc:date>
                </office:meta>
            </office:document-meta>
            XML;
    }

    private function genStyles(): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <office:document-styles xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"
                xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"
                office:version="1.3">
            </office:document-styles>
            XML;
    }
}
