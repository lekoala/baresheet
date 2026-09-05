<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTimeInterface;
use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Internal\DirectZipWriter;
use LeKoala\Baresheet\Value\DurationValue;
use LeKoala\Baresheet\Value\TimeValue;

/**
 * Zero-dependency XLSX writer producing raw XML packaged by DirectZipWriter.
 *
 * @phpstan-type WritableRow array<int|string, bool|float|int|string|\Stringable|DateTimeInterface|\Time\Duration|TimeValue|DurationValue|null>
 */
class XlsxWriter implements WriterInterface
{
    public const MIMETYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const BUFFER_SIZE = 1000;
    // Excel grid and cell limits: 1,048,576 rows, 16,384 columns (XFD), 32,767
    // characters per cell. The reader rejects cell references beyond XFD, so the
    // writer must refuse dimensions its own reader cannot round-trip.
    private const MAX_ROWS = 1_048_576;
    private const MAX_COLUMNS = 16_384;
    private const MAX_CELL_LENGTH = 32_767;

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
        $filename = Spread::ensureExtension($filename, 'xlsx');
        return $this->buildFile($data, $filename);
    }

    /**
     * @param iterable<WritableRow> $data
     */
    public function output(iterable $data, string $filename): void
    {
        $filename = Spread::ensureExtension($filename, 'xlsx');

        if ($this->stream) {
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
     * Build XLSX on a seekable temporary stream before copying it to php://output.
     *
     * A seekable stream lets DirectZipWriter enable ZIP64 only when the final
     * archive metadata actually requires it. Proactive ZIP64 headers on a
     * non-seekable HTTP stream are rejected by some spreadsheet clients.
     *
     * @param iterable<WritableRow> $data
     */
    public function outputStream(iterable $data, string $filename): void
    {
        $filename = Spread::ensureExtension($filename, 'xlsx');
        $archive = $this->writeStream($data);

        try {
            $stats = fstat($archive);
            $size = $stats !== false ? $stats['size'] : null;

            Spread::outputHeaders(self::MIMETYPE, $filename, $size);

            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new WriteException('Failed to open php://output');
            }

            try {
                if (stream_copy_to_stream($archive, $output) === false) {
                    throw new WriteException('Failed to stream XLSX output');
                }
            } finally {
                fclose($output);
            }
        } finally {
            fclose($archive);
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

        // Use tempPath when the destination filesystem is not suitable for
        // direct writes; the archive is built there then copied over.
        if ($this->tempPath) {
            $baseName = tempnam($this->tempPath, 'xlsx_native');
            if (!$baseName) {
                throw new WriteException('Failed to create temp file in ' . $this->tempPath);
            }
        } else {
            $baseName = $filename;
        }

        $stream = false;
        try {
            $stream = @fopen($baseName, 'w+b');
            if (!$stream) {
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
     * Package the full XLSX into the target stream via DirectZipWriter.
     *
     * DirectZipWriter selects its seek/patch or data-descriptor strategy from
     * the target stream's seekability.
     *
     * @param iterable<WritableRow> $data
     * @param resource $stream
     */
    private function buildDirectZip(iterable $data, $stream): void
    {
        $zip = new DirectZipWriter($stream, compressionLevel: 6);

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
            $zip->addString($path, $xml);
        }

        $sharedStrings = [];
        $sharedStringKeys = [];

        $zip->addCallback(
            'xl/worksheets/sheet1.xml',
            function (callable $write) use ($data, &$sharedStrings, &$sharedStringKeys): void {
                $this->streamWorksheetToWrite($data, $write, $sharedStrings, $sharedStringKeys);
            },
        );

        if ($this->sharedStrings) {
            $zip->addString('xl/sharedStrings.xml', $this->genSharedStrings($sharedStrings));
        }

        $zip->finish();
    }

    /**
     * Stream the worksheet (header + rows + footer) through a $write callback.
     *
     * When autoWidth is disabled (the default) this is a single pass with no
     * intermediate temp file. With autoWidth the rows are first written to a
     * temp stream so column widths are known before the <cols> section, then
     * replayed through the callback with the header prefixed.
     *
     * @param iterable<WritableRow> $data
     * @param callable(string):void $write
     * @param array<string> $sharedStrings
     * @param array<string,int> $sharedStringKeys
     */
    private function streamWorksheetToWrite(
        iterable $data,
        callable $write,
        array &$sharedStrings,
        array &$sharedStringKeys,
    ): void {
        if (!$this->autoWidth) {
            $write($this->buildWorksheetPrefix(false, []) . '<sheetData>');
            $colWidths = [];
            $this->streamRows($data, $write, $sharedStrings, $sharedStringKeys, false, $colWidths);
            $write('</sheetData>' . $this->buildWorksheetSuffix());
            return;
        }

        $tmp = tmpfile();
        if (!$tmp) {
            throw new WriteException('Failed to get temp file for sheet data');
        }

        try {
            $colWidths = [];
            $this->streamRows(
                $data,
                static function (string $chunk) use ($tmp): void {
                    fwrite($tmp, $chunk);
                },
                $sharedStrings,
                $sharedStringKeys,
                true,
                $colWidths,
            );

            rewind($tmp);
            $write($this->buildWorksheetPrefix(true, $colWidths) . '<sheetData>');
            while (!feof($tmp)) {
                $chunk = fread($tmp, 65_536);
                if ($chunk === false) {
                    throw new WriteException('Failed to read sheet data temp file');
                }
                if ($chunk !== '') {
                    $write($chunk);
                }
            }
            $write('</sheetData>' . $this->buildWorksheetSuffix());
        } finally {
            fclose($tmp);
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

    /**
     * @param iterable<WritableRow> $data
     * @param array<string> $sharedStrings
     * @param array<string,int> $sharedStringKeys
     * @return resource
     */
    /**
     * Encode the wrapped rows through a $write callback, tracking column
     * widths when requested.
     *
     * @param iterable<WritableRow> $data
     * @param callable(string):void $write
     * @param array<string> $sharedStrings
     * @param array<string,int> $sharedStringKeys
     * @param array<int,int> $colWidths
     */
    private function streamRows(
        iterable $data,
        callable $write,
        array &$sharedStrings,
        array &$sharedStringKeys,
        bool $trackWidths,
        array &$colWidths,
    ): void {
        $r = 0;
        $colCache = [];
        $sheetName = is_string($this->sheet) ? $this->sheet : 'Sheet1';
        $boldStyle = $this->boldHeaders ? ' s="2"' : '';
        $autoWidth = $trackWidths;
        $sharedStringsOpt = $this->sharedStrings;
        $bufferSizeOpt = self::BUFFER_SIZE;
        $buffer = '';

        $headerSchema = !empty($this->headers) ? HeaderSchema::fromDefinition($this->headers) : null;
        if ($headerSchema !== null) {
            $headerRowsRemaining = count($headerSchema->headerRows());
        } else {
            $headerRowsRemaining = 0;
            if ($this->boldHeaders) {
                $headerRowsRemaining = 1;
            } elseif (is_array($data)) {
                // Peek at the first row without moving the array pointer: reset()
                // would copy the whole array when it is shared with a callback.
                $firstKey = array_key_first($data);
                $firstRow = $firstKey !== null ? $data[$firstKey] : null;
                if (is_array($firstRow) && !array_is_list($firstRow)) {
                    $headerRowsRemaining = 1;
                }
            }
        }
        $wrappedData = $this->wrapRows($data, $headerSchema);

        foreach ($wrappedData as $dataRow) {
            $r++;
            if ($r > self::MAX_ROWS) {
                throw new WriteException("Row {$r} exceeds the maximum of " . self::MAX_ROWS . ' rows');
            }
            $i = 0;
            $cellStyle = $headerRowsRemaining > 0 ? $boldStyle : '';
            $buffer .= "<row r=\"{$r}\">";
            foreach ($dataRow as $value) {
                if ($i >= self::MAX_COLUMNS) {
                    throw new WriteException(
                        "Row {$r} exceeds the maximum of " . self::MAX_COLUMNS . ' columns',
                    );
                }
                if (!isset($colCache[$i])) {
                    $colCache[$i] = Spread::columnLetter($i + 1);
                }
                $cn = $colCache[$i] . $r;

                if ($value instanceof \Time\Duration) {
                    $excelSerial = Spread::durationToSerial($value);
                    $buffer .= sprintf(
                        '<c r="%s" t="n" s="4"><v>%s</v></c>',
                        $cn,
                        Spread::serializeFloat($excelSerial),
                    );
                    $vl = 16;
                } elseif ($value instanceof DurationValue) {
                    $excelSerial = Spread::durationComponentsToSerial(
                        $value->negative,
                        $value->hours,
                        $value->minutes,
                        $value->seconds,
                        $value->microsecond,
                    );
                    $buffer .= sprintf(
                        '<c r="%s" t="n" s="4"><v>%s</v></c>',
                        $cn,
                        Spread::serializeFloat($excelSerial),
                    );
                    $vl = 16;
                } elseif ($value instanceof TimeValue) {
                    $excelSerial = Spread::timeToExcel($value);
                    $buffer .= sprintf(
                        '<c r="%s" t="n" s="3"><v>%s</v></c>',
                        $cn,
                        Spread::serializeFloat($excelSerial),
                    );
                    $vl = 8;
                } elseif ($value instanceof DateTimeInterface) {
                    $excelDate = Spread::dateToExcel($value);
                    $buffer .= sprintf('<c r="%s" t="n" s="1"><v>%s</v></c>', $cn, Spread::serializeFloat($excelDate));
                    $vl = 16;
                } elseif (is_bool($value)) {
                    $buffer .= '<c r="' . $cn . '" t="b"' . $cellStyle . '><v>' . (int) $value . '</v></c>';
                    $vl = 1;
                } elseif (
                    $value === null
                    || $value === ''
                    || (!is_scalar($value)
                    && !$value instanceof \Stringable)
                ) {
                    $buffer .= '<c r="' . $cn . '"' . $cellStyle . '/>';
                    $vl = 0;
                } elseif (is_int($value) || is_float($value)) {
                    if (is_float($value) && !is_finite($value)) {
                        throw new WriteException('Cannot write a non-finite numeric value');
                    }
                    $strValue = is_float($value) ? Spread::serializeFloat($value) : (string) $value;
                    $vl = strlen($strValue);
                    $buffer .= '<c r="' . $cn . '" t="n"' . $cellStyle . '><v>' . $strValue . '</v></c>';
                } elseif ($this->inferNumericStrings && Spread::isNumericCellValue($value)) {
                    $strValue = (string) $value;
                    $vl = strlen($strValue);
                    $buffer .= '<c r="' . $cn . '" t="n"' . $cellStyle . '><v>' . $strValue . '</v></c>';
                } else {
                    $strValue = (string) $value;

                    // Excel caps a cell at 32,767 characters; refuse longer text
                    // before escaping (byte length is a safe fast-path filter since
                    // a string's byte count is always >= its character count).
                    if (strlen($strValue) > self::MAX_CELL_LENGTH) {
                        if (mb_strlen($strValue) > self::MAX_CELL_LENGTH) {
                            throw new WriteException(
                                "Cell {$cn} exceeds the maximum of " . self::MAX_CELL_LENGTH . ' characters',
                            );
                        }
                    }

                    // ⚡ Bolt: Fast-path optimization
                    // mb_strlen is significantly slower than strlen in tight loops.
                    // Use strlen (byte-length) as a fast threshold check for shared strings.
                    // Only invoke mb_strlen if autoWidth is enabled, as it requires accurate multi-byte character counts.
                    $vl = $autoWidth ? mb_strlen($strValue) : strlen($strValue);

                    $escaped = Spread::escapeXml($strValue, "sheet '{$sheetName}', cell {$cn}");

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
                            . '><is><t xml:space="preserve">'
                            . $escaped
                            . '</t></is></c>';
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
                $write($buffer);
                $buffer = '';
            }
            if ($headerRowsRemaining > 0) {
                $headerRowsRemaining--;
            }
        }

        if ($buffer !== '') {
            $write($buffer);
        }
    }

    /**
     * Worksheet opening markup (declaration, <worksheet>, freeze pane, optional
     * <cols>). Does not include <sheetData>.
     *
     * @param array<int,int> $colWidths
     */
    private function buildWorksheetPrefix(bool $includeCols, array $colWidths): string
    {
        $header = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $header .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $header .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        $freezePaneXml = $this->genFreezePaneXml();
        if ($freezePaneXml !== '') {
            $header .= '<sheetViews><sheetView tabSelected="1" workbookViewId="0">';
            $header .= $freezePaneXml;
            $header .= '</sheetView></sheetViews>';
        }

        if ($includeCols) {
            $header .= $this->genColsXml($colWidths);
        }

        return $header;
    }

    /**
     * Worksheet closing markup (sheet protection, optional auto filter,
     * </worksheet>). Does not include </sheetData>.
     */
    private function buildWorksheetSuffix(): string
    {
        $footer = $this->genSheetProtectionXml();
        if ($this->autofilter) {
            $autofilter = $this->autofilter;
            if (preg_match('/^[A-Z]+\d+:[A-Z]+\d+$/i', $autofilter)) {
                $footer .= '<autoFilter ref="' . Spread::escapeXmlAttr($autofilter) . '"/>';
            }
        }
        return $footer . '</worksheet>';
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
            $xml .= '<si><t xml:space="preserve">' . $str . '</t></si>';
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
        $title = Spread::escapeXml($metaObj->title ?? '', 'metadata title');
        $subject = Spread::escapeXml($metaObj->subject ?? '', 'metadata subject');
        $creator = Spread::escapeXml($metaObj->creator ?? '', 'metadata creator');
        $keywords = Spread::escapeXml($metaObj->keywords ?? '', 'metadata keywords');
        $description = Spread::escapeXml($metaObj->description ?? '', 'metadata description');
        $category = Spread::escapeXml($metaObj->category ?? '', 'metadata category');
        $language = Spread::escapeXml($metaObj->language ?? 'en-US', 'metadata language');

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
            <numFmts count="3">
                <numFmt numFmtId="164" formatCode="yyyy\-mm\-dd\ hh:mm:ss" />
                <numFmt numFmtId="165" formatCode="hh:mm:ss" />
                <numFmt numFmtId="166" formatCode="[h]:mm:ss" />
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
            <cellXfs count="5">
                <xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" />
                <xf applyNumberFormat="true" borderId="0" fillId="0" fontId="0" numFmtId="164" xfId="0" />
                <xf applyFont="true" borderId="0" fillId="0" fontId="1" numFmtId="0" xfId="0" />
                <xf applyNumberFormat="true" borderId="0" fillId="0" fontId="0" numFmtId="165" xfId="0" />
                <xf applyNumberFormat="true" borderId="0" fillId="0" fontId="0" numFmtId="166" xfId="0" />
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
