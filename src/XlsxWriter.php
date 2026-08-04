<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTimeInterface;
use LeKoala\Baresheet\Exception\WriteException;
use ZipArchive;

/**
 * Zero-dependency XLSX writer using ZipArchive + raw XML.
 *
 * @phpstan-type WritableRow array<int|string, float|int|string|\Stringable|DateTimeInterface|null>
 */
class XlsxWriter implements WriterInterface
{
    public const MIMETYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const BUFFER_SIZE = 1000;

    /**
     * @var Meta|array<string, mixed>|null Optional metadata for the generated document.
     */
    public Meta|array|null $meta = null;
    public ?string $autofilter = null;
    public ?string $freezePane = null;
    /** @var bool|string True locks without a password; a string locks with an Excel sheet-protection password. */
    public bool|string $sheetProtection = false;
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

    // -- Write API --

    /**
     * @param iterable<WritableRow> $data
     * @return resource The opened stream containing the data. It is the caller's responsibility to close it.
     */
    public function writeStream(iterable $data)
    {
        $stream = Spread::getMaxMemTempStream();

        if ($this->canStream()) {
            $this->streamIterative($data, $stream);
        } else {
            // Buffer to temp file, then copy to stream
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
        $filename = Spread::ensureExtension($filename, 'xlsx');
        return $this->buildFile($data, $filename);
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function output(iterable $data, string $filename): void
    {
        $filename = Spread::ensureExtension($filename, 'xlsx');

        if ($this->stream && $this->canStream()) {
            $this->outputStream($data, $filename);
            return;
        }

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

    /**
     * Stream XLSX directly to php://output via ZipStream (no temp ZIP file).
     *
     * Requires maennchen/zipstream-php ^3.1.
     *
     * @param iterable<WritableRow> $data
     */
    public function outputStream(iterable $data, string $filename): void
    {
        if (!class_exists(\ZipStream\ZipStream::class)) {
            throw new WriteException(
                'Streaming XLSX requires maennchen/zipstream-php. '
                . 'Install it with: composer require maennchen/zipstream-php',
            );
        }

        Spread::outputHeaders(self::MIMETYPE, $filename);

        $this->streamIterative($data);
    }

    /**
     * @param iterable<WritableRow> $data
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
     * @param iterable<WritableRow> $data
     */
    private function streamWorksheetToZip(\ZipStream\ZipStream $zip, iterable $data): void
    {
        $sharedStrings = [];
        $sharedStringKeys = [];
        $worksheetStream = $this->genWorksheet($data, $sharedStrings, $sharedStringKeys);
        try {
            rewind($worksheetStream);

            if ($this->sharedStrings) {
                $zip->addFile(fileName: 'xl/sharedStrings.xml', data: $this->genSharedStrings($sharedStrings));
            }
            $zip->addFileFromStream(fileName: 'xl/worksheets/sheet1.xml', stream: $worksheetStream);
        } finally {
            if (is_resource($worksheetStream)) {
                fclose($worksheetStream);
            }
        }
    }

    protected function canStream(): bool
    {
        return class_exists(\ZipStream\ZipStream::class);
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

        $mode = ZipArchive::CREATE | ZipArchive::OVERWRITE;
        // Use tempPath when the destination filesystem doesn't support ZipArchive well
        if ($this->tempPath) {
            $baseName = tempnam($this->tempPath, 'xlsx_native');
            if (!$baseName) {
                throw new WriteException('Failed to create temp file in ' . $this->tempPath);
            }
        } else {
            $baseName = $filename;
        }

        $zip = new ZipArchive();
        $result = $zip->open($baseName, $mode);
        if ($result !== true) {
            throw new WriteException('Failed to open zip archive, code: ' . Spread::zipError((int) $result));
        }

        $stream = null;
        try {
            $stream = $this->writeToZip($zip, $data);
            $destinationFile = $zip->filename;
            $closeResult = $zip->close();
            if ($closeResult === false) {
                throw new WriteException("Failed to close file '{$destinationFile}'");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
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

    /**
     * @param ZipArchive $zip
     * @param iterable<WritableRow> $data
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
        $uri = (string) ($meta['uri'] ?? '');
        $zip->addFile($uri, 'xl/worksheets/sheet1.xml');
        // Do not fclose() here otherwise the temp file is deleted before the zip is closed/built!
        return $worksheetStream;
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

    /**
     * @param iterable<WritableRow> $data
     * @param array<string> $sharedStrings
     * @param array<string,int> $sharedStringKeys
     * @return resource
     */
    private function genWorksheet(iterable $data, array &$sharedStrings, array &$sharedStringKeys)
    {
        // We write the sheet data to a separate temp stream first so we can
        // calculate column widths and write the <cols> section before <sheetData>.
        $dataStream = tmpfile();
        if (!$dataStream) {
            throw new WriteException('Failed to get temp file for sheet data');
        }

        $worksheetStream = null;
        try {
            $r = 0;
            $colWidths = [];
            $boldStyle = $this->boldHeaders ? ' s="2"' : '';
            $colCache = [];

            $headerSchema = !empty($this->headers) ? HeaderSchema::fromDefinition($this->headers) : null;
            if ($headerSchema !== null) {
                $headerRowsRemaining = count($headerSchema->headerRows());
            } else {
                $headerRowsRemaining = 0;
                if ($this->boldHeaders) {
                    $headerRowsRemaining = 1;
                } elseif (is_array($data)) {
                    $firstRow = reset($data);
                    if (is_array($firstRow) && !array_is_list($firstRow)) {
                        $headerRowsRemaining = 1;
                    }
                }
            }
            $wrappedData = $this->wrapRows($data, $headerSchema);

            $autoWidth = $this->autoWidth;
            $sharedStringsOpt = $this->sharedStrings;
            $bufferSizeOpt = self::BUFFER_SIZE;
            $buffer = '';

            foreach ($wrappedData as $dataRow) {
                $r++;
                $i = 0;
                $cellStyle = $headerRowsRemaining > 0 ? $boldStyle : '';
                $buffer .= "<row r=\"{$r}\">";
                foreach ($dataRow as $value) {
                    if (!isset($colCache[$i])) {
                        $colCache[$i] = Spread::columnLetter($i + 1);
                    }
                    $cn = $colCache[$i] . $r;

                    if ($value instanceof DateTimeInterface) {
                        $excelDate = Spread::dateToExcel($value);
                        $buffer .= '<c r="' . $cn . '" t="n" s="1"><v>' . $excelDate . '</v></c>';
                        $vl = 16;
                    } elseif (
                        $value === null
                        || $value === ''
                        || (!is_scalar($value)
                        && !$value instanceof \Stringable)
                    ) {
                        $buffer .= '<c r="' . $cn . '"' . $cellStyle . '/>';
                        $vl = 0;
                    } else {
                        $isNumeric = false;
                        if (is_int($value) || is_float($value)) {
                            $isNumeric = true;
                            $strValue = (string) $value;
                        } else {
                            $strValue = (string) $value;
                            if ($strValue === '0') {
                                $isNumeric = true;
                            } elseif (isset($strValue[0]) && $strValue[0] !== '0' && ctype_digit($strValue)) {
                                $isNumeric = true;
                            } elseif (is_numeric($strValue)) {
                                $isNumeric = (bool) preg_match("/^\-?(0|[1-9][0-9]*)(\.[0-9]+)?$/", $strValue);
                            }
                        }

                        if ($isNumeric) {
                            $vl = strlen($strValue);
                            $buffer .= '<c r="' . $cn . '" t="n"' . $cellStyle . '><v>' . $strValue . '</v></c>';
                        } else {
                            // ⚡ Bolt: Fast-path optimization
                            // mb_strlen is significantly slower than strlen in tight loops.
                            // Use strlen (byte-length) as a fast threshold check for shared strings.
                            // Only invoke mb_strlen if autoWidth is enabled, as it requires accurate multi-byte character counts.
                            $vl = $autoWidth ? mb_strlen($strValue) : strlen($strValue);

                            $escaped = Spread::escapeXml($strValue);

                            // For shared strings logic, use strlen for byte-length threshold checking
                            $strByteLen = $autoWidth ? strlen($strValue) : $vl;

                            if ($sharedStringsOpt && $strByteLen <= 160) {
                                $skey = '~' . $escaped;
                                if (isset($sharedStringKeys[$skey])) {
                                    $ssIdx = $sharedStringKeys[$skey];
                                } else {
                                    $sharedStrings[] = $escaped;
                                    $ssIdx = count($sharedStrings) - 1;
                                    $sharedStringKeys[$skey] = $ssIdx;
                                }
                                $buffer .= '<c r="' . $cn . '" t="s"' . $cellStyle . '><v>' . $ssIdx . '</v></c>';
                            } else {
                                $buffer .=
                                    '<c r="'
                                    . $cn
                                    . '" t="inlineStr"'
                                    . $cellStyle
                                    . '><is><t>'
                                    . $escaped
                                    . '</t></is></c>';
                            }
                        }
                    }
                    $buffer .= "\r\n";
                    if ($autoWidth) {
                        if (!isset($colWidths[$i]) || $vl > $colWidths[$i]) {
                            $colWidths[$i] = $vl;
                        }
                    }
                    $i++;
                }
                $buffer .= "</row>\r\n";
                if (($r % $bufferSizeOpt) === 0) {
                    fwrite($dataStream, $buffer);
                    $buffer = '';
                }
                if ($headerRowsRemaining > 0) {
                    $headerRowsRemaining--;
                }
            }

            if ($buffer !== '') {
                fwrite($dataStream, $buffer);
            }

            // Now assemble the final worksheet stream
            $worksheetStream = tmpfile();
            if (!$worksheetStream) {
                throw new WriteException('Failed to get temp file for worksheet');
            }

            $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
            $header .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
            $header .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

            $freezePaneXml = $this->genFreezePaneXml();
            if ($freezePaneXml !== '') {
                $header .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">';
                $header .= $freezePaneXml;
                $header .= '</sheetView></sheetViews>';
            }

            if ($autoWidth) {
                $header .= $this->genColsXml($colWidths);
            }

            $header .= '<sheetData>';
            fwrite($worksheetStream, $header);

            rewind($dataStream);
            stream_copy_to_stream($dataStream, $worksheetStream);
            fclose($dataStream);
            $dataStream = null;

            $footer = '</sheetData>';
            $footer .= $this->genSheetProtectionXml();
            if ($this->autofilter) {
                $autofilter = $this->autofilter;
                if (preg_match('/^[A-Z]+\d+:[A-Z]+\d+$/i', $autofilter)) {
                    $escapedFilter = Spread::escapeXmlAttr($autofilter);
                    $footer .= '<autoFilter ref="' . $escapedFilter . '"/>';
                }
            }
            $footer .= '</worksheet>';
            fwrite($worksheetStream, $footer);

            return $worksheetStream;
        } catch (\Throwable $e) {
            if (is_resource($worksheetStream)) {
                fclose($worksheetStream);
            }
            if (is_resource($dataStream)) {
                fclose($dataStream);
            }
            throw $e;
        }
    }

    private function genSheetProtectionXml(): string
    {
        if ($this->sheetProtection === false) {
            return '';
        }

        $password = '';
        if (is_string($this->sheetProtection)) {
            $password = ' password="' . $this->hashSheetProtectionPassword($this->sheetProtection) . '"';
        }

        return '<sheetProtection' . $password . ' sheet="1"/>';
    }

    /**
     * Generate Excel's legacy sheet-protection password verifier.
     *
     * This is intentionally not cryptographic: sheet protection prevents accidental edits,
     * but does not encrypt the workbook or secure its contents.
     */
    private function hashSheetProtectionPassword(string $password): string
    {
        $verifier = 0;
        $length = strlen($password);
        $passwordWithLength = pack('c', $length) . $password;

        for ($i = $length; $i >= 0; $i--) {
            $highBit = ($verifier & 0x4000) === 0 ? 0 : 1;
            $verifier = (($verifier << 1) & 0x7fff) | $highBit;
            $verifier ^= ord($passwordWithLength[$i]);
        }

        return strtoupper(dechex($verifier ^ 0xCE4B));
    }

    private function genFreezePaneXml(): string
    {
        if ($this->freezePane === null || $this->freezePane === '') {
            return '';
        }

        $reference = strtoupper($this->freezePane);
        if (!preg_match('/^([A-Z]{1,3})([1-9]\d*)$/', $reference, $matches)) {
            throw new \InvalidArgumentException("Invalid freeze pane cell reference: {$this->freezePane}");
        }

        $columnIndex = Spread::columnIndex($matches[1]);
        $rowIndex = (int) $matches[2];
        if ($columnIndex > 16_384 || $rowIndex > 1_048_576) {
            throw new \InvalidArgumentException("Invalid freeze pane cell reference: {$this->freezePane}");
        }

        $xSplit = $columnIndex - 1;
        $ySplit = $rowIndex - 1;
        if ($xSplit === 0 && $ySplit === 0) {
            return '';
        }

        $splitAttributes = '';
        if ($xSplit > 0) {
            $splitAttributes .= ' xSplit="' . $xSplit . '"';
        }
        if ($ySplit > 0) {
            $splitAttributes .= ' ySplit="' . $ySplit . '"';
        }

        $activePane = match (true) {
            $xSplit > 0 && $ySplit > 0 => 'bottomRight',
            $xSplit > 0 => 'topRight',
            default => 'bottomLeft',
        };

        return (
            '<pane'
            . $splitAttributes
            . ' topLeftCell="'
            . $reference
            . '" activePane="'
            . $activePane
            . '" state="frozen"/>'
        );
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
            $width = max(8, round(($len * 1.2) + 2, 2));
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
        $xml .=
            '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            . $count
            . '" uniqueCount="'
            . $count
            . '">';
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
        $title = Spread::escapeXml($metaObj->title ?? '');
        $subject = Spread::escapeXml($metaObj->subject ?? '');
        $creator = Spread::escapeXml($metaObj->creator ?? '');
        $keywords = Spread::escapeXml($metaObj->keywords ?? '');
        $description = Spread::escapeXml($metaObj->description ?? '');
        $category = Spread::escapeXml($metaObj->category ?? '');
        $language = Spread::escapeXml($metaObj->language ?? 'en-US');

        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"
                xmlns:dc="http://purl.org/dc/elements/1.1/"
                xmlns:dcmitype="http://purl.org/dc/dcmitype/"
                xmlns:dcterms="http://purl.org/dc/terms/"
                xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
                <dcterms:created xsi:type="dcterms:W3CDTF">{$created}</dcterms:created>
                <dc:title>{$title}</dc:title>
                <dc:subject>{$subject}</dc:subject>
                <dc:creator>{$creator}</dc:creator>
                <cp:keywords>{$keywords}</cp:keywords>
                <dc:description>{$description}</dc:description>
                <cp:category>{$category}</cp:category>
                <dc:language>{$language}</dc:language>
                <cp:revision>0</cp:revision>
            </cp:coreProperties>
            XML;
    }

    private function genStyles(): string
    {
        // fontId 0 = normal, fontId 1 = bold (for boldHeaders)
        // cellXfs: 0 = default, 1 = date (s="1"), 2 = bold (s="2")
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
            <numFmts count="1">
                <numFmt numFmtId="164" formatCode="yyyy\-mm\-dd\ hh:mm:ss" />
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
                <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" />
                <xf applyNumberFormat="true" borderId="0" fillId="0" fontId="0" numFmtId="164" xfId="0" />
                <xf applyFont="true" borderId="0" fillId="0" fontId="1" numFmtId="0" xfId="0" />
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
        $name = Spread::escapeXmlAttr(Spread::validateSheetName($sheetVal));
        return <<<XML
            <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
                xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
                <fileVersion appName="LeKoala\Baresheet"/>
                <sheets>
                    <sheet name="{$name}" sheetId="1" state="visible" r:id="rId1"/>
                </sheets>
            </workbook>
            XML;
    }

    private function genWorkbookRels(): string
    {
        $sharedStrings = $this->sharedStrings
            ? '    <Relationship Id="rId3" '
            . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" '
            . 'Target="sharedStrings.xml"/>'
            : '';
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
                <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
                <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>
            {$sharedStrings}
            </Relationships>
            XML;
    }

    private function genContentTypes(): string
    {
        $sharedStrings = $this->sharedStrings
            ? '    <Override PartName="/xl/sharedStrings.xml" '
            . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            : '';
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
                <Override PartName="/_rels/.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                <Override PartName="/xl/_rels/workbook.xml.rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
                <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
                <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
                <Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>
            {$sharedStrings}
                <Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>
                <Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>
            </Types>
            XML;
    }
}
