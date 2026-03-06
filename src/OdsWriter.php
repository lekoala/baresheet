<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTimeInterface;
use Exception;
use ZipArchive;

/**
 * Zero-dependency ODS writer using ZipArchive + raw XML.
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
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     * @return resource The opened stream containing the data. It is the caller's responsibility to close it.
     */
    public function writeStream(iterable $data, ?Options $options = null)
    {
        $options?->applyTo($this);

        $stream = Spread::getMaxMemTempStream();

        if ($this->canStream()) {
            $this->streamIterative($data, $stream);
        } else {
            // Buffer to temp file, then copy to stream
            $tempFilename = Spread::getTempFilename();
            $this->buildFile($data, $tempFilename);
            $tmpStream = fopen($tempFilename, 'r');
            if ($tmpStream) {
                $result = stream_copy_to_stream($tmpStream, $stream);
                fclose($tmpStream);
                if ($result === false) {
                    throw new Exception("Failed to copy temp file to stream");
                }
            }
            unlink($tempFilename);
        }

        rewind($stream);
        return $stream;
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function writeString(iterable $data, ?Options $options = null): string
    {
        $stream = $this->writeStream($data, $options);
        $contents = stream_get_contents($stream);
        fclose($stream);
        return $contents !== false ? $contents : '';
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function writeFile(iterable $data, string $filename, ?Options $options = null): bool
    {
        $options?->applyTo($this);
        $filename = Spread::ensureExtension($filename, 'ods');
        return $this->buildFile($data, $filename);
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function output(iterable $data, string $filename, ?Options $options = null): void
    {
        $options?->applyTo($this);
        $filename = Spread::ensureExtension($filename, 'ods');

        if ($this->stream && $this->canStream()) {
            $this->outputStream($data, $filename);
            return;
        }

        $tempFilename = Spread::getTempFilename();
        $this->buildFile($data, $tempFilename);

        $size = filesize($tempFilename);
        Spread::outputHeaders(self::MIMETYPE, $filename, $size !== false ? $size : null);

        readfile($tempFilename);
        unlink($tempFilename);
    }

    /**
     * Stream ODS directly to php://output via ZipStream (no temp file).
     *
     * Requires maennchen/zipstream-php ^3.1.
     *
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function outputStream(iterable $data, string $filename, ?Options $options = null): void
    {
        $options?->applyTo($this);
        if (!class_exists(\ZipStream\ZipStream::class)) {
            throw new Exception(
                'Streaming ODS requires maennchen/zipstream-php. '
                    . 'Install it with: composer require maennchen/zipstream-php'
            );
        }

        Spread::outputHeaders(self::MIMETYPE, $filename);

        $this->streamIterative($data);
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     * @param resource|null $outputStream
     */
    private function streamIterative(iterable $data, $outputStream = null): void
    {
        $zipArgs = [
            // We handle headers ourselves via Spread::outputHeaders() to maintain consistency
            // across all writers (CSV/XLSX/ODS) and support PSR-7 StreamedResponses.
            'sendHttpHeaders' => false,
        ];
        if ($outputStream) {
            $zipArgs['outputStream'] = $outputStream;
        }
        $zip = new \ZipStream\ZipStream(...$zipArgs);

        // mimetype must be first and stored uncompressed
        $zip->addFile(
            fileName: 'mimetype',
            data: self::MIMETYPE,
            compressionMethod: \ZipStream\CompressionMethod::STORE,
        );
        $zip->addFile(fileName: 'META-INF/manifest.xml', data: $this->genManifest());
        $zip->addFile(fileName: 'meta.xml', data: $this->genMeta());
        $zip->addFile(fileName: 'styles.xml', data: $this->genStyles());

        $contentStream = $this->genContent($data);
        rewind($contentStream);
        $zip->addFileFromStream(fileName: 'content.xml', stream: $contentStream);

        $zip->finish();
    }

    protected function canStream(): bool
    {
        return class_exists(\ZipStream\ZipStream::class);
    }

    // -- Internal --

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    private function buildFile(iterable $data, string $filename): bool
    {
        $destinationDir = dirname($filename);
        if (!is_writable($destinationDir)) {
            throw new Exception("Directory '$destinationDir' is not writable");
        }

        // Use tempPath when the destination filesystem doesn't support ZipArchive well
        if ($this->tempPath) {
            $baseName = tempnam($this->tempPath, 'ods_native');
            if (!$baseName) {
                throw new Exception("Failed to create temp file in " . $this->tempPath);
            }
        } else {
            $baseName = $filename;
        }

        $zip = new ZipArchive();
        $result = $zip->open($baseName, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new Exception("Failed to open zip archive, code: " . Spread::zipError((int)$result));
        }

        // mimetype must be first entry and stored uncompressed
        $zip->addFromString('mimetype', self::MIMETYPE);
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);

        $zip->addFromString('META-INF/manifest.xml', $this->genManifest());
        $zip->addFromString('meta.xml', $this->genMeta());
        $zip->addFromString('styles.xml', $this->genStyles());

        $contentStream = $this->genContent($data);
        rewind($contentStream);
        $meta = stream_get_meta_data($contentStream);
        $uri = (string)($meta['uri'] ?? '');
        $zip->addFile($uri, 'content.xml');

        $destinationFile = $zip->filename;
        $closeResult = $zip->close();
        if ($closeResult === false) {
            throw new Exception("Failed to close file '$destinationFile'");
        }

        // Copy from temp location to final destination when using tempPath
        if ($this->tempPath) {
            $contents = file_get_contents($destinationFile);
            if ($contents !== false) {
                file_put_contents($filename, $contents);
            }
            unlink($destinationFile);
        }

        return true;
    }

    // -- XML generators --

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     * @return resource
     */
    private function genContent(iterable $data)
    {
        $fd = tmpfile();
        if ($fd === false) {
            throw new Exception("Failed to open temp stream");
        }

        $sheetVal = is_string($this->sheet) ? $this->sheet : 'Sheet1';
        $sheetName = Spread::escapeXmlAttr($sheetVal);

        fwrite($fd, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fd, '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
            . ' office:version="1.3">');

        // Styles block (mandatory for some readers like OpenSpout)
        fwrite($fd, '<office:automatic-styles>');
        fwrite($fd, '<style:style style:name="ta1" style:family="table"/>');
        fwrite($fd, '<style:style style:name="bold" style:family="table-cell">');
        fwrite($fd, '<style:text-properties fo:font-weight="bold"/>');
        fwrite($fd, '</style:style>');
        fwrite($fd, '</office:automatic-styles>');

        fwrite($fd, '<office:body><office:spreadsheet>');
        fwrite($fd, '<table:table table:name="' . $sheetName . '" table:style-name="ta1">');

        $wrappedData = $this->prependHeaders($data);
        $isFirstRow = true;

        $boldHeadersOpt = $this->boldHeaders;
        $bufferSizeOpt = self::BUFFER_SIZE;
        $buffer = '';
        $r = 0;

        foreach ($wrappedData as $row) {
            $r++;
            $buffer .= '<table:table-row>';

            $rowCellStyle = ($isFirstRow && $boldHeadersOpt) ? ' table:style-name="bold"' : '';

            foreach ($row as $value) {
                if ($value instanceof DateTimeInterface) {
                    $isoDate = $value->format('Y-m-d\TH:i:s');
                    $display = $value->format('Y-m-d H:i:s');
                    $buffer .= '<table:table-cell' . $rowCellStyle
                        . ' office:value-type="date"'
                        . ' office:date-value="' . $isoDate . '">'
                        . '<text:p>' . $display . '</text:p>'
                        . '</table:table-cell>';
                } elseif ($value === null || $value === '') {
                    $buffer .= '<table:table-cell' . $rowCellStyle . '/>';
                } elseif (is_numeric($value) && !is_string($value)) {
                    $buffer .= '<table:table-cell' . $rowCellStyle
                        . ' office:value-type="float"'
                        . ' office:value="' . $value . '">'
                        . '<text:p>' . $value . '</text:p>'
                        . '</table:table-cell>';
                } elseif (
                    is_string($value)
                    && is_numeric($value)
                    && ($value === '0' || (isset($value[0]) && $value[0] !== '0' || str_contains($value, '.')))
                ) {
                    $buffer .= '<table:table-cell' . $rowCellStyle
                        . ' office:value-type="float"'
                        . ' office:value="' . $value . '">'
                        . '<text:p>' . $value . '</text:p>'
                        . '</table:table-cell>';
                } else {
                    $escaped = Spread::escapeXml((string)$value);
                    $buffer .= '<table:table-cell' . $rowCellStyle
                        . ' office:value-type="string">'
                        . '<text:p>' . $escaped . '</text:p>'
                        . '</table:table-cell>';
                }
            }
            $isFirstRow = false;
            $buffer .= '</table:table-row>';
            if ($r % $bufferSizeOpt === 0) {
                $res = fwrite($fd, $buffer);
                if ($res === false) {
                    throw new \RuntimeException("Failed to write to buffer");
                }
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $res = fwrite($fd, $buffer);
            if ($res === false) {
                throw new \RuntimeException("Failed to write to buffer");
            }
        }

        fwrite($fd, '</table:table>');
        fwrite($fd, '</office:spreadsheet></office:body>');
        fwrite($fd, '</office:document-content>');

        return $fd;
    }

    /**
     * Prepend a header row from associative keys or explicit headers option.
     *
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     * @return iterable<array<float|int|string|\Stringable|DateTimeInterface|null>>
     */
    private function prependHeaders(iterable $data): iterable
    {
        if (!empty($this->headers)) {
            yield $this->headers;
            foreach ($data as $row) {
                yield array_values($row);
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
        $creator = Spread::escapeXml($metaObj->creator ?? "");
        $titleVal = $metaObj?->title;
        $title = $titleVal ? '<dc:title>' . Spread::escapeXml($titleVal) . '</dc:title>' : '';
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
