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
    /**
     * @var Meta|array<string, mixed>|null Optional metadata for the generated document.
     */
    public Meta|array|null $meta = null;
    public string|int|null $sheet = null;
    public bool $boldHeaders = false;
    public bool $stream = false;
    public ?string $tempPath = null;
    /**
     * @var string[]
     */
    public array $headers = [];

    private const MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet';

    // -- Write API --

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function writeString(iterable $data, ?Options $options = null): string
    {
        $options?->applyTo($this);

        $filename = Spread::getTempFilename();
        $this->buildFile($data, $filename);
        $contents = file_get_contents($filename);
        if (!$contents) {
            $contents = "";
        }
        unlink($filename);
        return $contents;
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function writeFile(iterable $data, string $filename, ?Options $options = null): bool
    {
        $options?->applyTo($this);
        return $this->buildFile($data, $filename);
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function output(iterable $data, string $filename, ?Options $options = null): void
    {
        $options?->applyTo($this);

        Spread::outputHeaders(self::MIMETYPE, $filename);

        if ($this->stream) {
            $this->outputStream($data, $filename);
            return;
        }

        $tempFilename = Spread::getTempFilename();
        $this->buildFile($data, $tempFilename);
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

        $zip = new \ZipStream\ZipStream(
            sendHttpHeaders: false,
        );

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

        if ($this->tempPath) {
            $baseName = tempnam($this->tempPath, 'ods_native');
            if (!$baseName) {
                throw new Exception("Failed to create temp file in " . $this->tempPath);
            }
        } else {
            $baseName = Spread::getTempFilename();
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

        $contents = file_get_contents($destinationFile);
        if ($contents !== false) {
            file_put_contents($filename, $contents);
        }
        unlink($destinationFile);

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
        $sheetName = htmlspecialchars($sheetVal, ENT_XML1, 'UTF-8');

        fwrite($fd, '<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        fwrite($fd, '<office:document-content'
            . ' xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"'
            . ' xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0"'
            . ' office:version="1.3">');

        // Bold header style if needed
        if ($this->boldHeaders) {
            fwrite($fd, '<office:automatic-styles>'
                . '<style:style style:name="bold" style:family="table-cell">'
                . '<style:text-properties fo:font-weight="bold"/>'
                . '</style:style>'
                . '</office:automatic-styles>');
        }

        fwrite($fd, '<office:body><office:spreadsheet>');
        fwrite($fd, '<table:table table:name="' . $sheetName . '">');

        $wrappedData = $this->prependHeaders($data);
        $isFirstRow = true;

        foreach ($wrappedData as $row) {
            fwrite($fd, '<table:table-row>');
            foreach ($row as $value) {
                $cellStyle = ($isFirstRow && $this->boldHeaders)
                    ? ' table:style-name="bold"'
                    : '';

                if ($value instanceof DateTimeInterface) {
                    $isoDate = $value->format('Y-m-d\TH:i:s');
                    $display = $value->format('Y-m-d H:i:s');
                    fwrite($fd, '<table:table-cell' . $cellStyle
                        . ' office:value-type="date"'
                        . ' office:date-value="' . $isoDate . '">'
                        . '<text:p>' . $display . '</text:p>'
                        . '</table:table-cell>');
                } elseif ($value === null || $value === '') {
                    fwrite($fd, '<table:table-cell' . $cellStyle . '/>');
                } elseif (is_numeric($value) && !is_string($value)) {
                    fwrite($fd, '<table:table-cell' . $cellStyle
                        . ' office:value-type="float"'
                        . ' office:value="' . $value . '">'
                        . '<text:p>' . $value . '</text:p>'
                        . '</table:table-cell>');
                } elseif (
                    is_string($value)
                    && is_numeric($value)
                    && ($value === '0' || ($value[0] !== '0' || str_contains($value, '.')))
                ) {
                    fwrite($fd, '<table:table-cell' . $cellStyle
                        . ' office:value-type="float"'
                        . ' office:value="' . $value . '">'
                        . '<text:p>' . $value . '</text:p>'
                        . '</table:table-cell>');
                } else {
                    $escaped = htmlspecialchars((string)$value, ENT_XML1, 'UTF-8');
                    fwrite($fd, '<table:table-cell' . $cellStyle
                        . ' office:value-type="string">'
                        . '<text:p>' . $escaped . '</text:p>'
                        . '</table:table-cell>');
                }
            }
            fwrite($fd, '</table:table-row>');
            $isFirstRow = false;
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
        $creator = htmlspecialchars($metaObj->creator ?? 'Baresheet', ENT_XML1, 'UTF-8');
        $titleVal = $metaObj?->title;
        $title = $titleVal ? '<dc:title>' . htmlspecialchars($titleVal, ENT_XML1, 'UTF-8') . '</dc:title>' : '';
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
