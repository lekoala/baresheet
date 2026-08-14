<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Generator;
use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\InvalidRowException;
use LeKoala\Baresheet\Exception\MissingColumnException;
use LeKoala\Baresheet\Exception\SheetNotFoundException;
use ZipArchive;

/**
 * Zero-dependency XLSX reader using ZipArchive + SimpleXML.
 *
 * @phpstan-import-type Row from ReaderInterface
 */
class XlsxReader implements ReaderInterface
{
    // The shared strings table is streamed via XMLReader below (not loaded in full),
    // so it gets a permissive sanity guard instead of a tight in-memory cap. This only
    // protects against absurd/malformed declarations, not against legitimate large files.
    private const MAX_STREAMED_ENTRY_SIZE = 1_000_000_000;
    // Excel's real column limit (XFD), so a bogus cell reference can't force
    // allocation of an absurd number of null placeholder columns.
    private const MAX_COLUMNS = 16_384;

    public bool $assoc = false;
    public bool $strict = false;
    public ?int $limit = null;
    public int $offset = 0;
    public bool $skipEmptyLines = true;
    public string|int|null $sheet = null;
    /** @var string[] */
    public array $headers = [];
    /** @var string[] */
    public array $requiredColumns = [];
    /** @var string[] */
    public array $columns = [];
    /** @var array<string|int, string|array<array-key, mixed>> */
    public array $aliases = [];
    public int $headerRows = 1;
    public int|string|null $headerOffset = null;
    /** @var null|callable(string): string */
    public $headerNormalizer = null;
    /** @var ?int Maximum allowed size for the streamed worksheet, in bytes (null = unlimited). */
    public ?int $maxWorksheetSize = 500_000_000;
    /**
     * @var bool If true, values are stringified (CSV-like, lossy). If false,
     *           readers expose natural PHP value kinds: numbers and booleans are
     *           typed, dates become DateTimeImmutable, while time and duration
     *           remain canonical strings.
     *
     *           INTERIM DEFAULT: true to preserve BC behavior. Flip to false
     *           for the 1.0 release together with Options::$stringifyValues.
     */
    public bool $stringifyValues = true;

    public function __construct(?Options $options = null)
    {
        $options?->applyTo($this);
    }

    /**
     * @return Generator<int, Row>
     * @throws InvalidDocumentException
     * @throws SheetNotFoundException
     */
    public function readFile(string $filename): Generator
    {
        Spread::isSafePath($filename);
        if (!is_file($filename)) {
            throw new InvalidDocumentException("Invalid file {$filename}");
        }
        if (!is_readable($filename)) {
            throw new InvalidDocumentException("File {$filename} is not readable");
        }

        $zip = new ZipArchive();
        $result = $zip->open($filename);
        if ($result !== true) {
            throw new InvalidDocumentException('Failed to open zip archive, code: ' . Spread::zipError($result));
        }

        try {
            // Locate shared strings without loading them: they are streamed below via zip://
            // after the archive is closed, exactly like the worksheet.
            $sharedStringsUri = null;
            $ssIdx = $zip->locateName('xl/sharedStrings.xml');
            if ($ssIdx !== false) {
                $ssStat = $zip->statIndex($ssIdx);
                if ($ssStat !== false && $ssStat['size'] > self::MAX_STREAMED_ENTRY_SIZE) {
                    throw new InvalidDocumentException(
                        'ZIP entry \'xl/sharedStrings.xml\' exceeds maximum allowed size ('
                        . self::MAX_STREAMED_ENTRY_SIZE
                        . ' bytes).',
                    );
                }
                $sharedStringsUri = 'zip://' . $filename . '#xl/sharedStrings.xml';
            }

            // Styles
            $cellFormats = [];
            $stylesData = Spread::zipGetData($zip, 'xl/styles.xml');
            if ($stylesData) {
                $cellFormats = self::parseCellFormats($stylesData, $cellFormats);
            }

            // Check 1904 date system
            $is1904 = false;
            $wbData = Spread::zipGetData($zip, 'xl/workbook.xml');
            if ($wbData) {
                $wbXml = Spread::safeXml($wbData);
                if (isset($wbXml->workbookPr)) {
                    $date1904 = (string) $wbXml->workbookPr['date1904'];
                    $is1904 = $date1904 === '1' || strtolower($date1904) === 'true';
                }
            }

            // Resolve worksheet path from sheet name/index
            $wsPath = $this->resolveSheetPath($zip);
            $wsIdx = $zip->locateName($wsPath);
            if ($wsIdx === false) {
                throw new InvalidDocumentException('No data');
            }

            // The worksheet is streamed directly via zip:// below (not loaded into PHP
            // memory); the maximum size is configurable via maxWorksheetSize.
            $wsStat = $zip->statIndex($wsIdx);
            if ($this->maxWorksheetSize !== null && $wsStat !== false && $wsStat['size'] > $this->maxWorksheetSize) {
                throw new InvalidDocumentException(
                    "ZIP entry '{$wsPath}' exceeds maximum allowed size (" . $this->maxWorksheetSize . ' bytes).',
                );
            }
        } finally {
            $zip->close();
        }

        $colFormats = [];
        $isDateCache = [];

        // Flatten shared strings into a plain array for O(1) index lookup during row parsing.
        // Streamed via XMLReader to avoid holding the full XML string and SimpleXML DOM
        // in memory simultaneously.
        $sharedStrings = $sharedStringsUri !== null ? self::readSharedStrings($sharedStringsUri) : [];

        // Open the worksheet XML as a zip:// stream directly — avoids writing a temp file first,
        // saving a full disk write+read cycle (~40ms on typical hardware).
        $reader = new \XMLReader();
        if (!$reader->open('zip://' . $filename . '#' . $wsPath, null, LIBXML_NONET)) {
            throw new InvalidDocumentException("Failed to open worksheet '{$wsPath}'");
        }

        try {
            yield from $this->parseWorksheet($reader, $sharedStrings, $cellFormats, $colFormats, $isDateCache, $is1904);
        } finally {
            $reader->close();
        }
    }

    /**
     * Stream shared strings from the zip:// entry, building a plain array for O(1)
     * index lookup during row parsing. Avoids holding the full XML string and the
     * SimpleXML DOM in memory at the same time.
     *
     * @return string[]
     */
    private static function readSharedStrings(string $uri): array
    {
        $sharedStrings = [];
        $reader = new \XMLReader();
        if (!$reader->open($uri, null, LIBXML_NONET)) {
            return $sharedStrings;
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'si') {
                    continue;
                }
                $sharedStrings[] = self::readSharedStringItem($reader);
            }
        } finally {
            $reader->close();
        }

        return $sharedStrings;
    }

    /**
     * Read a single <si> item, concatenating its visible text. Direct <t> children
     * (simple string) and direct <r><t> runs (rich text) are included; <rPh> phonetic
     * runs and <phoneticPr> are ignored.
     */
    private static function readSharedStringItem(\XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $siDepth = $reader->depth;
        $text = '';

        while ($reader->read() && $reader->depth > $siDepth) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            // Only direct children of <si> are meaningful here; this naturally skips
            // the <t> inside <rPh> (which is nested one level deeper).
            if ($reader->depth !== ($siDepth + 1)) {
                continue;
            }
            if ($reader->name === 't') {
                $text .= $reader->readString();
            } elseif ($reader->name === 'r') {
                if (!$reader->isEmptyElement) {
                    $rDepth = $reader->depth;
                    while ($reader->read() && $reader->depth > $rDepth) {
                        if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 't') {
                            $text .= $reader->readString();
                        }
                    }
                }
            }
        }

        return $text;
    }

    /**
     * @param string[] $sharedStrings
     * @param array<int, ?string> $cellFormats
     * @param array<int, ?string> $colFormats
     * @param array<string, string> $isDateCache
     * @return Generator<int, Row>
     */
    private function parseWorksheet(
        \XMLReader $reader,
        array $sharedStrings,
        array $cellFormats,
        array $colFormats,
        array $isDateCache,
        bool $is1904,
    ): Generator {
        $schema = !empty($this->headers)
            ? HeaderSchema::fromHeaders($this->headers, $this->headerRows, $this->headerNormalizer)
            : null;
        $rowCount = 0;
        $yieldCount = 0;
        $startRow = $this->assoc ? 1 : 0;
        $totalColumns = $schema !== null ? $schema->columnCount() : null;
        // Seeded from injected headers so a too-short/too-long first data row is
        // caught immediately instead of silently becoming the new expected width.
        $expectedCols = $totalColumns;
        $colRefCache = [];
        $selectionSchema = null;
        $headerRowsBuffer = [];
        $autoScanning = $this->headerOffset === 'auto';
        $autoWindow = [];

        // Pre-build column map and validate required columns from injected headers
        if ($schema !== null) {
            if (!empty($this->requiredColumns)) {
                $schema->checkRequiredColumns($this->requiredColumns);
            }
            if (!empty($this->columns)) {
                $selectionSchema = $schema->select($this->columns);
            }
            if (!empty($this->aliases)) {
                if ($selectionSchema !== null) {
                    $selectionSchema = $selectionSchema->rename($this->aliases);
                }
                $schema = $schema->rename($this->aliases);
            }
        }
        $selectedIndices = $selectionSchema !== null ? array_fill_keys($selectionSchema->indices(), true) : [];

        if ($this->limit === 0) {
            return;
        }

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->name !== 'row') {
                continue;
            }

            $rowCount++;
            $rowData = [];
            $col = 0;
            $isEmpty = true;

            if (!$reader->isEmptyElement) {
                $rowDepth = $reader->depth;
                $moved = $reader->read();

                while ($moved && $reader->depth > $rowDepth) {
                    if (
                        $reader->nodeType === \XMLReader::ELEMENT
                        && $reader->name === 'c'
                    ) {
                        $r = $reader->getAttribute('r') ?? '';

                        $cellIndex = $col;
                        if ($r !== '') {
                            $colLetter = rtrim($r, '0123456789');
                            // Cache column letter → index: columnIndex() only runs once per column.
                            $cellIndex = $colRefCache[$colLetter] ?? null;
                            if ($cellIndex === null) {
                                $cellIndex = Spread::columnIndex($colLetter) - 1;
                                if ($cellIndex >= self::MAX_COLUMNS) {
                                    throw new InvalidDocumentException(
                                        "Cell reference '{$colLetter}' exceeds the maximum of "
                                        . self::MAX_COLUMNS
                                        . ' columns.',
                                    );
                                }
                                $colRefCache[$colLetter] = $cellIndex;
                            }
                        }

                        // Optimization: Skip parsing unselected cells entirely
                        if ($selectionSchema !== null && !isset($selectedIndices[$cellIndex])) {
                            // Skip the entire <c> subtree without reading value
                            if (!$reader->isEmptyElement) {
                                $moved = $reader->next();
                            } else {
                                $moved = $reader->read();
                            }
                            // Still need to track position for sparse row handling
                            while ($cellIndex > $col) {
                                $rowData[] = null;
                                $col++;
                            }
                            // Add null placeholder for skipped column
                            $rowData[] = null;
                            $col++;
                            continue;
                        }

                        $t = $reader->getAttribute('t') ?? '';
                        $s = $reader->getAttribute('s') ?? '';

                        $v = '';
                        $cDepth = $reader->depth;
                        if (!$reader->isEmptyElement) {
                            while ($reader->read() && $reader->depth > $cDepth) {
                                if ($reader->nodeType === \XMLReader::ELEMENT) {
                                    if ($reader->name === 'v') {
                                        $v = $reader->readString();
                                    } elseif ($reader->name === 'is') {
                                        $isDepth = $reader->depth;
                                        $v = '';
                                        while ($reader->read() && $reader->depth > $isDepth) {
                                            if (
                                                $reader->nodeType === \XMLReader::ELEMENT
                                                && $reader->name === 't'
                                            ) {
                                                $v .= $reader->readString();
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        $format = null;

                        while ($cellIndex > $col) {
                            $rowData[] = null;
                            $col++;
                        }

                        if ($t === 's') {
                            $idx = (int) $v;
                            $v = $sharedStrings[$idx] ?? '';
                        }

                        $excelFormat = null;
                        $classification = null;
                        if ($s !== '') {
                            $excelFormat = $cellFormats[(int) $s] ?? null;
                            if ($excelFormat) {
                                if (!isset($isDateCache[$excelFormat])) {
                                    $isDateCache[$excelFormat] = Spread::classifyNumberFormat($excelFormat);
                                }
                                $classification = $isDateCache[$excelFormat];
                            }
                        }

                        if ($t === 'n' && is_numeric($v)) {
                            if ($excelFormat === null) {
                                $classification = $colFormats[$col] ?? null;
                            }
                        }

                        if ($classification !== null && !isset($colFormats[$col])) {
                            $colFormats[$col] = $classification;
                        }

                        if ($this->stringifyValues) {
                            if ($classification !== null && $classification !== 'number') {
                                $v = Spread::excelDateToString($v, null, $is1904);
                            }
                            $value = $v;
                        } else {
                            $value = $this->decodeTypedCell($t, $v, $classification, $is1904);
                        }

                        if ($value !== '' && $value !== null) {
                            $isEmpty = false;
                        }

                        $rowData[] = $value;
                        $col++;
                    }
                    $moved = $reader->read();
                }
            }

            $actualCols = $col;

            while ($totalColumns && $col < $totalColumns) {
                $rowData[] = null;
                $col++;
            }

            if ($isEmpty && $this->skipEmptyLines) {
                continue;
            }

            // Skip rows before the header block (explicit int offset)
            if (
                !$autoScanning
                && $this->headerOffset !== null
                && $this->headerOffset !== 'auto'
                && $rowCount <= $this->headerOffset
            ) {
                continue;
            }

            // Auto-detection: slide window until requiredColumns match
            if ($autoScanning) {
                $autoWindow[] = array_map(
                    static fn(mixed $cell): string => $cell === null ? '' : Spread::stringifyValue($cell),
                    $rowData,
                );
                if (count($autoWindow) > $this->headerRows) {
                    array_shift($autoWindow);
                }
                if (count($autoWindow) >= $this->headerRows) {
                    try {
                        $candidate = HeaderSchema::fromRows($autoWindow, $this->headerNormalizer);
                        $candidate->checkRequiredColumns($this->requiredColumns);
                        $schema = $candidate;
                        $autoScanning = false;
                        $totalColumns = $schema->columnCount();
                        if (!empty($this->columns)) {
                            $selectionSchema = $schema->select($this->columns);
                        }
                        if (!empty($this->aliases)) {
                            $schema = $schema->rename($this->aliases);
                            if ($selectionSchema !== null) {
                                $selectionSchema = $selectionSchema->rename($this->aliases);
                            }
                        }
                        $selectedIndices = $selectionSchema !== null
                            ? array_fill_keys($selectionSchema->indices(), true)
                            : [];
                    } catch (InvalidDocumentException|MissingColumnException) {
                        // Not matched — keep scanning
                    }
                }
                continue;
            }

            if ($this->strict && !($this->assoc && $schema === null)) {
                if ($expectedCols === null) {
                    $expectedCols = $actualCols;
                } elseif ($actualCols !== $expectedCols) {
                    throw new InvalidRowException(
                        "Row {$rowCount} has {$actualCols} columns, expected {$expectedCols}",
                        row: $rowCount,
                    );
                }
            }

            if ($this->assoc) {
                if ($schema === null) {
                    $headerNames = [];
                    foreach ($rowData as $v) {
                        $headerNames[] = $v === null ? '' : Spread::stringifyValue($v);
                    }
                    $headerRowsBuffer[] = $headerNames;

                    if (count($headerRowsBuffer) < $this->headerRows) {
                        continue;
                    }

                    $schema = HeaderSchema::fromRows($headerRowsBuffer, $this->headerNormalizer);
                    $totalColumns = $schema->columnCount();
                    // Validate required columns
                    if (!empty($this->requiredColumns)) {
                        $schema->checkRequiredColumns($this->requiredColumns);
                    }
                    // Build column selection
                    if (!empty($this->columns)) {
                        $selectionSchema = $schema->select($this->columns);
                    }
                    // Apply column aliases
                    if (!empty($this->aliases)) {
                        if ($selectionSchema !== null) {
                            $selectionSchema = $selectionSchema->rename($this->aliases);
                        }
                        $schema = $schema->rename($this->aliases);
                    }
                    $selectedIndices = $selectionSchema !== null
                        ? array_fill_keys($selectionSchema->indices(), true)
                        : [];
                    continue;
                }
                if ($selectionSchema !== null) {
                    $rowData = $selectionSchema->mapRow(array_slice($rowData, 0, $totalColumns));
                } else {
                    $rowData = $schema->mapRow(array_slice($rowData, 0, $totalColumns));
                }
            } else {
                if ($totalColumns === null) {
                    $totalColumns = count($rowData);
                }
                if ($selectionSchema !== null) {
                    $indices = $selectionSchema->indices();
                    $selected = [];
                    foreach ($indices as $i) {
                        $selected[] = $rowData[$i] ?? null;
                    }
                    $rowData = $selected;
                }
            }

            if ($yieldCount < $this->offset) {
                $yieldCount++;
                continue;
            }

            yield $rowData;
            $yieldCount++;
            if ($this->limit !== null && ($yieldCount - $this->offset) >= $this->limit) {
                return;
            }
        }

        if ($autoScanning) {
            throw new InvalidDocumentException(
                'Could not auto-detect header position. Ensure required columns exist.',
            );
        }
    }

    /**
     * Decode a raw cell into its semantic PHP value (native, non-stringified mode).
     *
     * @param ?string $classification 'number'|'date'|'datetime'|'time'|'duration'|null
     */
    private function decodeTypedCell(string $t, string $v, ?string $classification, bool $is1904): mixed
    {
        if ($t === 'b') {
            return $v === '1' || strtolower($v) === 'true';
        }
        if ($t === 's' || $t === 'str' || $t === 'inlineStr' || $t === 'e') {
            return $v;
        }

        // OOXML t="d" cells carry an explicit ISO-8601 date/datetime. The
        // value is validated strictly and neutralized to UTC civil components:
        // an embedded offset is not part of the round-trip contract.
        if ($t === 'd') {
            return Spread::parseIsoDate($v) ?? $v;
        }

        // Cells without an explicit type attribute default to numeric in XLSX.
        if (!is_numeric($v)) {
            return $v;
        }

        $floatValue = (float) $v;
        return match ($classification) {
            'date', 'datetime' => Spread::excelDateToImmutable($v, $is1904),
            'time' => Spread::excelTimeToString($floatValue),
            'duration' => Spread::durationSerialToString($floatValue),
            default => Spread::parseNumericValue($v),
        };
    }

    /**
     * Parse styles.xml and return cell format lookup array.
     *
     * @param array<string, ?string> $numericalFormats
     * @return array<int, ?string>
     */
    private static function parseCellFormats(string $stylesData, array $numericalFormats = []): array
    {
        $cellFormats = [];
        $stylesXml = Spread::safeXml($stylesData);

        if (isset($stylesXml->numFmts)) {
            foreach ($stylesXml->numFmts->children() as $fmt) {
                $attrs = $fmt->attributes();
                $numericalFormats[(string) $attrs->numFmtId] = (string) $attrs->formatCode;
            }
        }

        if (isset($stylesXml->cellXfs->xf)) {
            foreach ($stylesXml->cellXfs->xf as $v) {
                $fmtId = (string) ($v['numFmtId'] ?? '0');

                $cellFormat = $numericalFormats[$fmtId] ?? null;

                if ($cellFormat === null) {
                    $cellFormat = self::getBuiltInFormatCode((int) $fmtId);
                    // Cache built-in formats too to avoid redundant match/lookup
                    $numericalFormats[$fmtId] = $cellFormat;
                }

                $cellFormats[] = $cellFormat;
            }
        }

        return $cellFormats;
    }

    /**
     * @return Generator<int, Row>
     */
    public function readString(string $contents): Generator
    {
        $filename = Spread::getTempFilename();
        try {
            file_put_contents($filename, $contents);
            yield from $this->readFile($filename);
        } finally {
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    /**
     * Resolve which worksheet file to read from the workbook.
     */
    private function resolveSheetPath(ZipArchive $zip): string
    {
        if ($this->sheet === null) {
            return 'xl/worksheets/sheet1.xml';
        }

        $wbData = Spread::zipGetData($zip, 'xl/workbook.xml');
        if (!$wbData) {
            return 'xl/worksheets/sheet1.xml';
        }

        $wbXml = Spread::safeXml($wbData);
        $sheets = [];
        $idx = 0;
        foreach ($wbXml->sheets->sheet as $s) {
            $attrs = $s->attributes();
            $name = (string) $attrs->name;
            $rId = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            $sheets[] = ['name' => $name, 'rId' => $rId, 'index' => $idx];
            $idx++;
        }

        $target = null;
        foreach ($sheets as $s) {
            if (is_int($this->sheet) && $s['index'] === $this->sheet) {
                $target = $s['rId'];
                break;
            }
            if (is_string($this->sheet) && $s['name'] === $this->sheet) {
                $target = $s['rId'];
                break;
            }
        }

        if (!$target) {
            throw new SheetNotFoundException("Sheet '{$this->sheet}' not found");
        }

        // Resolve rId to target path from workbook relationships
        $relsData = Spread::zipGetData($zip, 'xl/_rels/workbook.xml.rels');
        if ($relsData) {
            $relsXml = Spread::safeXml($relsData);
            foreach ($relsXml->Relationship as $rel) {
                if ((string) $rel['Id'] === $target) {
                    return 'xl/' . (string) $rel['Target'];
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    // -- Format helpers --

    /**
     * Built-in Open XML number format codes (0–70), including CJK locale formats.
     */
    public static function getBuiltInFormatCode(int $numFmtId): ?string
    {
        return match ($numFmtId) {
            0 => 'General',
            1 => '0',
            2 => '0.00',
            3 => '#,##0',
            4 => '#,##0.00',
            5 => '$#,##0_);($#,##0)',
            6 => '$#,##0_);[Red]($#,##0)',
            7 => '$#,##0.00_);($#,##0.00)',
            8 => '$#,##0.00_);[Red]($#,##0.00)',
            9 => '0%',
            10 => '0.00%',
            11 => '0.00E+00',
            12 => '# ?/?',
            13 => '# ??/??',
            14 => 'm/d/yyyy',
            15 => 'd-mmm-yy',
            16 => 'd-mmm',
            17 => 'mmm-yy',
            18 => 'h:mm AM/PM',
            19 => 'h:mm:ss AM/PM',
            20 => 'h:mm',
            21 => 'h:mm:ss',
            22 => 'm/d/yyyy h:mm',
            27, 36, 50, 57 => '[$-404]e/m/d',
            30 => 'm/d/yy',
            37 => '#,##0 ;(#,##0)',
            38 => '#,##0 ;[Red](#,##0)',
            39 => '#,##0.00;(#,##0.00)',
            40 => '#,##0.00;[Red](#,##0.00)',
            41 => '_(* #,##0_);_(* (#,##0);_(* "-"_);_(@_)',
            42 => '_($* #,##0_);_($* (#,##0);_($* "-"_);_(@_)',
            43 => '_(* #,##0.00_);_(* (#,##0.00);_(* "-"??_);_(@_)',
            44 => '_($* #,##0.00_);_($* (#,##0.00);_($* "-"??_);_(@_)',
            45 => 'mm:ss',
            46 => '[h]:mm:ss',
            47 => 'mm:ss.0',
            48 => '##0.0E+0',
            49 => '@',
            59 => 't0',
            60 => 't0.00',
            61 => 't#,##0',
            62 => 't#,##0.00',
            67 => 't0%',
            68 => 't0.00%',
            69 => 't# ?/?',
            70 => 't# ??/??',
            default => null,
        };
    }

    public static function isDateTimeFormatCode(string $excelFormatCode): bool
    {
        return Spread::isDateTimeFormatCode($excelFormatCode);
    }
}
