<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTimeInterface;
use LeKoala\Baresheet\Exception\WriteException;
use ZipArchive;

/**
 * Zero-dependency ODS writer using ZipArchive + raw XML.
 *
 * @phpstan-type WritableRow array<int|string, bool|float|int|string|\Stringable|DateTimeInterface|null>
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
        $tempFilename = Spread::getTempFilename();
        try {
            $this->buildFile($data, $tempFilename);
            $tmpStream = fopen($tempFilename, 'r');
            if ($tmpStream) {
                $result = stream_copy_to_stream($tmpStream, $stream);
                fclose($tmpStream);
                if ($result === false) {
                    throw new WriteException('Failed to copy temp file to stream');
                }
            }
        } finally {
            if (is_file($tempFilename)) {
                unlink($tempFilename);
            }
        }

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
        $this->outputBuffered($data, $filename, includeLength: !$this->stream);
    }

    /**
     * ODS output is buffered to preserve its special first mimetype entry.
     *
     * @param iterable<WritableRow> $data
     */
    public function outputStream(iterable $data, string $filename): void
    {
        $this->outputBuffered($data, $filename, includeLength: false);
    }

    /**
     * @param iterable<WritableRow> $data
     */
    private function outputBuffered(iterable $data, string $filename, bool $includeLength): void
    {
        $filename = Spread::ensureExtension($filename, 'ods');
        $tempFilename = Spread::getTempFilename();
        try {
            $this->buildFile($data, $tempFilename);

            $size = filesize($tempFilename);
            Spread::outputHeaders(
                self::MIMETYPE,
                $filename,
                $includeLength && $size !== false ? $size : null,
            );
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

        // Use tempPath when the destination filesystem doesn't support ZipArchive well
        if ($this->tempPath) {
            $baseName = tempnam($this->tempPath, 'ods_native');
            if (!$baseName) {
                throw new WriteException('Failed to create temp file in ' . $this->tempPath);
            }
        } else {
            $baseName = $filename;
        }

        $zip = new ZipArchive();
        $result = $zip->open($baseName, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new WriteException('Failed to open zip archive, code: ' . Spread::zipError((int) $result));
        }

        $contentStream = null;
        try {
            // mimetype must be first entry and stored uncompressed
            $zip->addFromString('mimetype', self::MIMETYPE);
            $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

            $zip->addFromString('META-INF/manifest.xml', $this->genManifest());
            $zip->addFromString('meta.xml', $this->genMeta());
            $zip->addFromString('styles.xml', $this->genStyles());

            $contentStream = $this->genContent($data);
            rewind($contentStream);
            $meta = stream_get_meta_data($contentStream);
            $uri = (string) ($meta['uri'] ?? '');
            $zip->addFile($uri, 'content.xml');

            $destinationFile = $zip->filename;
            $closeResult = $zip->close();
            if ($closeResult === false) {
                throw new WriteException("Failed to close file '{$destinationFile}'");
            }
        } finally {
            if (is_resource($contentStream)) {
                fclose($contentStream);
            }
        }

        // Copy from temp location to final destination when using tempPath
        if ($this->tempPath) {
            try {
                copy($destinationFile, $filename);
            } finally {
                if (is_file($destinationFile)) {
                    unlink($destinationFile);
                }
            }
        }

        return true;
    }

    // -- XML generators --

    /**
     * @param iterable<WritableRow> $data
     * @return resource
     */
    private function genContent(iterable $data)
    {
        $fd = tmpfile();
        if ($fd === false) {
            throw new WriteException('Failed to open temp stream');
        }

        try {
            $sheetVal = is_string($this->sheet) ? $this->sheet : 'Sheet1';
            $sheetName = Spread::escapeXmlAttr(Spread::validateSheetName($sheetVal));

            fwrite($fd, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
            fwrite(
                $fd,
                '<office:document-content'
                . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
                . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
                . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
                . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
                . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
                . ' office:version="1.3">',
            );

            // Styles block (mandatory for some readers like OpenSpout)
            fwrite($fd, '<office:automatic-styles>');
            fwrite($fd, '<style:style style:name="ta1" style:family="table"/>');
            fwrite($fd, '<style:style style:name="bold" style:family="table-cell">');
            fwrite($fd, '<style:text-properties fo:font-weight="bold"/>');
            fwrite($fd, '</style:style>');
            fwrite($fd, '</office:automatic-styles>');

            fwrite($fd, '<office:body><office:spreadsheet>');
            fwrite($fd, '<table:table table:name="' . $sheetName . '" table:style-name="ta1">');

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

                foreach ($row as $value) {
                    if ($value instanceof DateTimeInterface) {
                        $isoDate = $value->format('Y-m-d\TH:i:s');
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
                    } elseif (
                        Spread::isNumericCellValue($value)
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
                        $escaped = Spread::escapeXml($strValue);
                        $buffer .=
                            '<table:table-cell'
                            . $rowCellStyle
                            . ' office:value-type="string">'
                            . '<text:p>'
                            . $escaped
                            . '</text:p>'
                            . '</table:table-cell>';
                    }
                }
                if ($headerRowsRemaining > 0) {
                    $headerRowsRemaining--;
                }
                $buffer .= '</table:table-row>';
                if (($r % $bufferSizeOpt) === 0) {
                    $res = fwrite($fd, $buffer);
                    if ($res === false) {
                        throw new WriteException('Failed to write to buffer');
                    }
                    $buffer = '';
                }
            }

            if ($buffer !== '') {
                $res = fwrite($fd, $buffer);
                if ($res === false) {
                    throw new WriteException('Failed to write to buffer');
                }
            }

            fwrite($fd, '</table:table>');
            fwrite($fd, '</office:spreadsheet></office:body>');
            fwrite($fd, '</office:document-content>');

            return $fd;
        } catch (\Throwable $e) {
            fclose($fd);
            throw $e;
        }
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

        $first = true;
        foreach ($data as $row) {
            if ($first && array_is_list($row) === false) {
                yield array_keys($row);
            }
            $first = false;
            yield array_values($row);
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
        $creator = Spread::escapeXml($metaObj->creator ?? '');
        $titleVal = $metaObj?->title;
        $title = $titleVal ? '<dc:title>' . Spread::escapeXml($titleVal) . '</dc:title>' : '';
        $subjectVal = $metaObj?->subject;
        $subject = $subjectVal ? '<dc:subject>' . Spread::escapeXml($subjectVal) . '</dc:subject>' : '';
        $keywordsVal = $metaObj?->keywords;
        $keywords = '';
        if ($keywordsVal !== null && $keywordsVal !== '') {
            $parts = array_filter(array_map('trim', explode(',', $keywordsVal)));
            foreach ($parts as $part) {
                $keywords .= '<meta:keyword>' . Spread::escapeXml($part) . '</meta:keyword>';
            }
        }
        $descriptionVal = $metaObj?->description;
        $description = $descriptionVal
            ? '<dc:description>' . Spread::escapeXml($descriptionVal) . '</dc:description>'
            : '';
        $languageVal = $metaObj?->language;
        $language = $languageVal ? '<dc:language>' . Spread::escapeXml($languageVal) . '</dc:language>' : '';
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
