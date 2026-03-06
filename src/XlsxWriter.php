<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTimeInterface;
use Exception;
use ZipArchive;

/**
 * Zero-dependency XLSX writer using ZipArchive + raw XML.
 */
class XlsxWriter implements WriterInterface
{
    public const MIMETYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @var Meta|array<string, mixed>|null Optional metadata for the generated document.
     */
    public Meta|array|null $meta = null;
    public ?string $autofilter = null;
    public ?string $freezePane = null;
    public string|int|null $sheet = null;
    public bool $boldHeaders = false;
    public bool $stream = true;
    public bool $sharedStrings = false;
    public bool $autoWidth = false;
    public ?string $tempPath = null;
    /**
     * @var string[]
     */
    public array $headers = [];

    public function __construct(?Options $options = null)
    {
        $options?->applyTo($this);
    }

    private const BUFFER_SIZE = 1000;

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
            $zip = new \ZipStream\ZipStream(
                outputStream: $stream,
                sendHttpHeaders: false,
            );

            $this->addStaticFilesToZip($zip);
            $this->streamWorksheetToZip($zip, $data);
            $zip->finish();
        } else {
            // Buffer to temp file, then copy to stream
            $tempFilename = Spread::getTempFilename();
            $this->buildFile($data, $tempFilename);
            $tmpStream = fopen($tempFilename, 'r');
            if ($tmpStream) {
                stream_copy_to_stream($tmpStream, $stream);
                fclose($tmpStream);
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
        return $this->buildFile($data, $filename);
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    public function output(iterable $data, string $filename, ?Options $options = null): void
    {
        $options?->applyTo($this);

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
     * Stream XLSX directly to php://output via ZipStream (no temp ZIP file).
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
                'Streaming XLSX requires maennchen/zipstream-php. '
                    . 'Install it with: composer require maennchen/zipstream-php'
            );
        }

        Spread::outputHeaders(self::MIMETYPE, $filename);

        // Headers already sent explicitly, disable ZipStream's own header logic
        $zip = new \ZipStream\ZipStream(
            sendHttpHeaders: false,
        );

        $files = [
            '_rels/.rels' => $this->genRels(),
            'docProps/app.xml' => $this->genAppXml(),
            'docProps/core.xml' => $this->genCoreXml(),
            'xl/styles.xml' => $this->genStyles(),
            'xl/workbook.xml' => $this->genWorkbook(),
            'xl/_rels/workbook.xml.rels' => $this->genWorkbookRels(),
            '[Content_Types].xml' => $this->genContentTypes(),
        ];

        $zip = new \ZipStream\ZipStream(
            sendHttpHeaders: false,
        );

        $this->addStaticFilesToZip($zip);
        $this->streamWorksheetToZip($zip, $data);

        $zip->finish();
    }

    private function addStaticFilesToZip(\ZipStream\ZipStream $zip): void
    {
        $files = [
            '_rels/.rels' => $this->genRels(),
            'docProps/app.xml' => $this->genAppXml(),
            'docProps/core.xml' => $this->genCoreXml(),
            'xl/styles.xml' => $this->genStyles(),
            'xl/workbook.xml' => $this->genWorkbook(),
            'xl/_rels/workbook.xml.rels' => $this->genWorkbookRels(),
            '[Content_Types].xml' => $this->genContentTypes(),
        ];

        foreach ($files as $path => $xml) {
            $zip->addFile(fileName: $path, data: $xml);
        }
    }

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     */
    private function streamWorksheetToZip(\ZipStream\ZipStream $zip, iterable $data): void
    {
        $sharedStrings = [];
        $sharedStringKeys = [];
        $worksheetStream = $this->genWorksheet($data, $sharedStrings, $sharedStringKeys);
        rewind($worksheetStream);

        if ($this->sharedStrings) {
            $zip->addFile(fileName: 'xl/sharedStrings.xml', data: $this->genSharedStrings($sharedStrings));
        }
        $zip->addFileFromStream(fileName: 'xl/worksheets/sheet1.xml', stream: $worksheetStream);
    }

    private function canStream(): bool
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

        $mode = ZipArchive::CREATE | ZipArchive::OVERWRITE;
        // Use tempPath when the destination filesystem doesn't support ZipArchive well
        if ($this->tempPath) {
            $baseName = tempnam($this->tempPath, 'xlsx_native');
            if (!$baseName) {
                throw new Exception("Failed to create temp file in " . $this->tempPath);
            }
        } else {
            $baseName = $filename;
        }

        $zip = new ZipArchive();
        $result = $zip->open($baseName, $mode);
        if ($result !== true) {
            throw new Exception("Failed to open zip archive, code: " . Spread::zipError((int)$result));
        }
        $stream = $this->writeToZip($zip, $data);
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

    /**
     * @param ZipArchive $zip
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     * @return resource
     */
    private function writeToZip(ZipArchive $zip, iterable $data)
    {
        $allFiles = [
            '_rels/.rels' => $this->genRels(),
            'docProps/app.xml' => $this->genAppXml(),
            'docProps/core.xml' => $this->genCoreXml(),
            'xl/styles.xml' => $this->genStyles(),
            'xl/workbook.xml' => $this->genWorkbook(),
            'xl/_rels/workbook.xml.rels' => $this->genWorkbookRels(),
            '[Content_Types].xml' => $this->genContentTypes(),
        ];

        foreach ($allFiles as $path => $xml) {
            $zip->addFromString($path, $xml);
        }

        $sharedStrings = [];
        $sharedStringKeys = [];
        $worksheetStream = $this->genWorksheet($data, $sharedStrings, $sharedStringKeys);
        rewind($worksheetStream);

        if ($this->sharedStrings) {
            $ssXml = $this->genSharedStrings($sharedStrings);
            $zip->addFromString('xl/sharedStrings.xml', $ssXml);
        }

        $meta = stream_get_meta_data($worksheetStream);
        $uri = (string)($meta['uri'] ?? '');
        $zip->addFile($uri, 'xl/worksheets/sheet1.xml');
        // Do not fclose() here otherwise the temp file is deleted before the zip is closed/built!
        return $worksheetStream;
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

    /**
     * @param iterable<array<float|int|string|\Stringable|DateTimeInterface|null>> $data
     * @param array<string> $sharedStrings
     * @param array<string,int> $sharedStringKeys
     * @return resource
     */
    private function genWorksheet(iterable $data, array &$sharedStrings, array &$sharedStringKeys)
    {
        $tempStream = tmpfile();
        if (!$tempStream) {
            throw new Exception("Failed to get temp file");
        }
        $r = 0;
        $colWidths = [];

        // Build sheetViews for freeze pane
        $sheetViews = '';
        if ($this->freezePane) {
            $sheetViews = '<sheetViews><sheetView tabSelected="1" workbookViewId="0">';
            $sheetViews .= '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>';
            $sheetViews .= '</sheetView></sheetViews>';
        }

        $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $header .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $header .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $header .= $sheetViews;
        if ($this->autoWidth) {
            $header .= '__COLS_PLACEHOLDER__';
        }
        $header .= '<sheetData>';
        fwrite($tempStream, $header);

        // Bold headers use style index 2 (see genStyles)
        $boldStyle = $this->boldHeaders ? ' s="2"' : '';

        $colCache = [];
        $wrappedData = $this->prependHeaders($data);
        $isFirstRow = true;

        $autoWidth = $this->autoWidth;
        $sharedStringsOpt = $this->sharedStrings;
        $bufferSizeOpt = self::BUFFER_SIZE;
        $buffer = '';

        foreach ($wrappedData as $dataRow) {
            $c = "";
            $i = 0;
            $rowNum = $r + 1;
            foreach ($dataRow as $value) {
                if (!isset($colCache[$i])) {
                    $colCache[$i] = Spread::columnLetter($i + 1);
                }
                $cn = $colCache[$i] . $rowNum;

                // Apply bold style to first row when boldHeaders is enabled
                $cellStyle = ($isFirstRow && $boldStyle) ? $boldStyle : '';

                // Cell generation logic
                $vl = 0;
                if ($value instanceof DateTimeInterface) {
                    $value = Spread::dateToExcel($value);
                    $c .= '<c r="' . $cn . '" t="n" s="1"><v>' . $value . '</v></c>';
                    $vl = 16;
                } elseif (!is_scalar($value) || $value === '') {
                    $c .= '<c r="' . $cn . '"' . $cellStyle . '/>';
                    $vl = 0;
                } elseif (
                    !is_string($value)
                    || $value === '0'
                    || ($value[0] !== '0' && ctype_digit($value))
                    || preg_match("/^\-?(0|[1-9][0-9]*)(\.[0-9]+)?$/", (string)$value)
                ) {
                    $c .= '<c r="' . $cn . '" t="n"' . $cellStyle . '><v>' . $value . '</v></c>';
                    $vl = mb_strlen((string)$value);
                } else {
                    $escaped = self::escapeXml((string)$value);
                    if ($sharedStringsOpt && mb_strlen($escaped) <= 160) {
                        $skey = '~' . $escaped;
                        if (isset($sharedStringKeys[$skey])) {
                            $ssIdx = $sharedStringKeys[$skey];
                        } else {
                            $sharedStrings[] = $escaped;
                            $ssIdx = count($sharedStrings) - 1;
                            $sharedStringKeys[$skey] = $ssIdx;
                        }
                        $c .= '<c r="' . $cn . '" t="s"' . $cellStyle . '><v>' . $ssIdx . '</v></c>';
                    } else {
                        $c .= '<c r="' . $cn . '" t="inlineStr"' . $cellStyle . '><is><t>' . $escaped . '</t></is></c>';
                    }
                    $vl = mb_strlen((string)$value);
                }
                $c .= "\r\n";

                if ($autoWidth) {
                    if (!isset($colWidths[$i]) || $vl > $colWidths[$i]) {
                        $colWidths[$i] = $vl;
                    }
                }

                $i++;
            }
            $r++;
            $buffer .= "<row r=\"$r\">$c</row>\r\n";
            if ($r % $bufferSizeOpt === 0) {
                fwrite($tempStream, $buffer);
                $buffer = '';
            }
            $isFirstRow = false;
        }

        if ($buffer !== '') {
            fwrite($tempStream, $buffer);
            $buffer = '';
        }

        $footer = '</sheetData>';

        // Autofilter
        if ($this->autofilter) {
            $escapedFilter = self::escapeXmlAttr($this->autofilter);
            $footer .= '<autoFilter ref="' . $escapedFilter . '"/>';
        }

        $footer .= '</worksheet>';
        fwrite($tempStream, $footer);

        if ($this->autoWidth) {
            // Replace cols placeholder with actual auto-sized columns
            rewind($tempStream);
            $content = stream_get_contents($tempStream);
            $colsXml = $this->genColsXml($colWidths);
            $content = str_replace('__COLS_PLACEHOLDER__', $colsXml, $content);

            ftruncate($tempStream, 0);
            rewind($tempStream);
            fwrite($tempStream, (string)$content);
        }

        return $tempStream;
    }

    /**
     * @param array<int,int> $colWidths
     */
    private function genColsXml(array $colWidths): string
    {
        if (empty($colWidths)) {
            return '<cols><col collapsed="false" hidden="false" max="1024" min="1" style="0" customWidth="false" width="11.5"/></cols>';
        }

        $xml = '<cols>';
        foreach ($colWidths as $i => $len) {
            $colNum = $i + 1;
            $width = max(8, round($len * 1.2 + 2, 2));
            $xml .= '<col min="' . $colNum . '" max="' . $colNum . '" width="' . $width . '" customWidth="true"/>';
        }
        $xml .= '</cols>';
        return $xml;
    }

    /**
     * @param array<string> $sharedStrings
     */
    private function genSharedStrings(array $sharedStrings): string
    {
        $count = count($sharedStrings);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach ($sharedStrings as $str) {
            $xml .= '<si><t>' . $str . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    // -- XML generation helpers --

    private function genRels(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>
</Relationships>
XML;
    }

    private function genAppXml(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"
    xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">
    <TotalTime>0</TotalTime>
    <Company></Company>
</Properties>
XML;
    }

    private function genCoreXml(): string
    {
        $metaObj = is_array($this->meta) ? Meta::fromArray($this->meta) : $this->meta;
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $title = self::escapeXml($metaObj->title ?? "");
        $subject = self::escapeXml($metaObj->subject ?? "");
        $creator = self::escapeXml($metaObj->creator ?? "");
        $keywords = self::escapeXml($metaObj->keywords ?? "");
        $description = self::escapeXml($metaObj->description ?? "");
        $category = self::escapeXml($metaObj->category ?? "");
        $language = self::escapeXml($metaObj->language ?? "en-US");

        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:dcmitype="http://purl.org/dc/dcmitype/"
    xmlns:dcterms="http://purl.org/dc/terms/"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
    <dcterms:created xsi:type="dcterms:W3CDTF">$created</dcterms:created>
    <dc:title>$title</dc:title>
    <dc:subject>$subject</dc:subject>
    <dc:creator>$creator</dc:creator>
    <cp:keywords>$keywords</cp:keywords>
    <dc:description>$description</dc:description>
    <cp:category>$category</cp:category>
    <dc:language>$language</dc:language>
    <cp:revision>0</cp:revision>
</cp:coreProperties>
XML;
    }

    private function genStyles(): string
    {
        // fontId 0 = normal, fontId 1 = bold (for boldHeaders)
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
<numFmts count="2">
    <numFmt numFmtId="164" formatCode="GENERAL" />
    <numFmt numFmtId="165" formatCode="yyyy\-mm\-dd\ hh:mm:ss" />
</numFmts>
<fonts count="2">
    <font><name val="Arial"/><family val="2"/><sz val="10"/></font>
    <font><b/><name val="Arial"/><family val="2"/><sz val="10"/></font>
</fonts>
<fills count="2">
    <fill><patternFill patternType="none" /></fill>
    <fill><patternFill patternType="gray125" /></fill>
</fills>
<borders count="1">
<border><left/><right/><top/><bottom/><diagonal/></border>
</borders>
<cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0" />
</cellStyleXfs>
<cellXfs count="3">
    <xf applyAlignment="false" applyBorder="false" applyFont="true" applyProtection="false" borderId="0" fillId="0" fontId="0" numFmtId="164" xfId="0">
        <alignment horizontal="general" vertical="bottom" textRotation="0" wrapText="false" indent="0" shrinkToFit="false"/>
        <protection locked="true" hidden="false"/>
    </xf>
    <xf applyNumberFormat="true" borderId="0" fillId="0" fontId="0" numFmtId="165" xfId="0">
        <alignment horizontal="general" vertical="bottom"/>
    </xf>
    <xf applyFont="true" borderId="0" fillId="0" fontId="1" numFmtId="164" xfId="0">
        <alignment horizontal="general" vertical="bottom"/>
    </xf>
</cellXfs>
<cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
</cellStyles>
</styleSheet>
XML;
    }

    private function genWorkbook(): string
    {
        $sheetVal = is_string($this->sheet) ? $this->sheet : 'Sheet1';
        $name = self::escapeXmlAttr($sheetVal);
        return <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
    xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <fileVersion appName="LeKoala\Baresheet"/>
    <sheets>
        <sheet name="$name" sheetId="1" state="visible" r:id="rId2"/>
    </sheets>
</workbook>
XML;
    }

    private function genWorkbookRels(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
    <Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>
</Relationships>
XML;
    }

    private function genContentTypes(): string
    {
        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Override PartName="/_rels/.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Override PartName="/xl/_rels/workbook.xml.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>
    <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
    <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
</Types>
XML;
    }

    /**
     * Escape string for XML, stripping control chars (\x00-\x1F) except tab, LF, CR.
     */
    private static function escapeXml(string $str): string
    {
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str) ?? $str;
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $str);
    }

    /**
     * Escape string for XML attributes (includes quotes)
     */
    private static function escapeXmlAttr(string $str): string
    {
        $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str) ?? $str;
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $str);
    }
}
