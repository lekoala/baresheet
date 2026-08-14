<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Generator;
use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\InvalidRowException;
use LeKoala\Baresheet\Exception\MissingColumnException;
use LeKoala\Baresheet\Exception\SheetNotFoundException;
use LeKoala\Baresheet\Value\TimeValue;
use ZipArchive;

/**
 * Zero-dependency ODS reader using ZipArchive + SimpleXML.
 *
 * @phpstan-import-type Row from ReaderInterface
 */
class OdsReader implements ReaderInterface
{
    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    private const NS_STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    private const NS_NUMBER = 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0';

    // Caps the total column count a row can reach, however many repeated
    // cells it takes to get there.
    private const MAX_COLUMNS = 16_384;
    // Caps number-rows-repeated so a single declared row can't force an
    // absurd number of logical row emissions.
    private const MAX_ROW_REPEAT = 16_384;

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
    /** @var ?int Maximum allowed size for the streamed content.xml, in bytes (null = unlimited). */
    public ?int $maxWorksheetSize = 500_000_000;
    /**
     * @var bool If true, values are stringified (CSV-like, lossy). If false,
     *           the semantic source type is preserved (int|float|bool|DateTimeImmutable|...).
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
     * @throws InvalidRowException
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
            $idx = $zip->locateName('content.xml');
            if ($idx === false) {
                throw new InvalidDocumentException('No content.xml found in ODS file');
            }

            // content.xml is streamed directly via zip:// below (not loaded into PHP
            // memory); the maximum size is configurable via maxWorksheetSize.
            $stat = $zip->statIndex($idx);
            if ($this->maxWorksheetSize !== null && $stat !== false && $stat['size'] > $this->maxWorksheetSize) {
                throw new InvalidDocumentException(
                    'ZIP entry \'content.xml\' exceeds maximum allowed size (' . $this->maxWorksheetSize . ' bytes).',
                );
            }

            // Data (number) styles live in styles.xml for many external writers.
            $stylesXml = Spread::zipGetData($zip, 'styles.xml');
        } finally {
            $zip->close();
        }

        // Open content.xml as a zip:// stream directly — avoids writing a temp file first,
        // saving a full disk write+read cycle (~40ms on typical hardware).
        yield from $this->parseContent('zip://' . $filename . '#content.xml', $stylesXml);
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
     * @return Generator<int, Row>
     */
    private function parseContent(string $xmlFile, ?string $stylesXml): Generator
    {
        $reader = new \XMLReader();
        if (!$reader->open($xmlFile, null, LIBXML_NONET)) {
            throw new InvalidDocumentException("Failed to open {$xmlFile}");
        }

        try {
            // Map of table-cell style name => whether it renders an elapsed
            // duration (number:truncate-on-overflow="false"), distinguishing a
            // time of day from a duration shorter than 24 hours in ODS.
            $cellToDataStyles = [];
            $dataStyleDurations = [];
            if ($stylesXml !== null && $stylesXml !== '') {
                self::scanTimeStyles($stylesXml, $cellToDataStyles, $dataStyleDurations);
            }
            $timeStyles = self::scanContentAutoStyles($reader, $cellToDataStyles, $dataStyleDurations);

            $tableIndex = 0;
            $schema = !empty($this->headers)
                ? HeaderSchema::fromHeaders($this->headers, $this->headerRows, $this->headerNormalizer)
                : null;
            $totalColumns = $schema !== null ? $schema->columnCount() : null;
            $yieldCount = 0;
            $selectionSchema = null;

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

            if ($this->limit === 0) {
                return;
            }

            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName !== 'table') {
                    continue;
                }
                if ($reader->namespaceURI !== self::NS_TABLE) {
                    continue;
                }

                $name = $reader->getAttributeNs('name', self::NS_TABLE);
                if (!$this->isTargetSheet($tableIndex, $name)) {
                    $tableIndex++;
                    continue;
                }

                if ($reader->isEmptyElement) {
                    continue;
                }

                yield from $this->parseTable(
                    $reader,
                    $schema,
                    $totalColumns,
                    $yieldCount,
                    $selectionSchema,
                    $timeStyles,
                );

                return;
            }

            if ($this->sheet !== null) {
                throw new SheetNotFoundException("Sheet '{$this->sheet}' not found");
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Scan the <office:automatic-styles> section of content.xml, if any,
     * merging into the given maps. The reader is left positioned right after
     * the section (or at <office:body>) so the table loop can continue.
     *
     * @param array<string, string> $cellToDataStyles
     * @param array<string, bool> $dataStyleDurations
     * @return array<string, bool> cell style name => is duration
     */
    private static function scanContentAutoStyles(
        \XMLReader $reader,
        array &$cellToDataStyles,
        array &$dataStyleDurations,
    ): array {
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'automatic-styles' && $reader->namespaceURI === self::NS_OFFICE) {
                if (!$reader->isEmptyElement) {
                    $depth = $reader->depth;
                    while ($reader->read() && $reader->depth > $depth) {
                        if ($reader->nodeType === \XMLReader::ELEMENT) {
                            self::scanTimeStyleNode($reader, $cellToDataStyles, $dataStyleDurations);
                        }
                    }
                }
                break;
            }
            if ($reader->localName === 'body' && $reader->namespaceURI === self::NS_OFFICE) {
                break;
            }
        }

        return self::buildTimeStyleMap($cellToDataStyles, $dataStyleDurations);
    }

    /**
     * Scan an XML document (e.g. styles.xml) for table-cell styles referencing
     * number:time-style data styles.
     *
     * @param array<string, string> $cellToDataStyles
     * @param array<string, bool> $dataStyleDurations
     */
    private static function scanTimeStyles(
        string $xml,
        array &$cellToDataStyles,
        array &$dataStyleDurations,
    ): void {
        $reader = new \XMLReader();
        $uri = 'data://text/plain;base64,' . base64_encode($xml);
        if (!$reader->open($uri, null, LIBXML_NONET)) {
            return;
        }
        try {
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    self::scanTimeStyleNode($reader, $cellToDataStyles, $dataStyleDurations);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param array<string, string> $cellToDataStyles
     * @param array<string, bool> $dataStyleDurations
     */
    private static function scanTimeStyleNode(
        \XMLReader $reader,
        array &$cellToDataStyles,
        array &$dataStyleDurations,
    ): void {
        if ($reader->localName === 'style' && $reader->namespaceURI === self::NS_STYLE) {
            if ($reader->getAttributeNs('family', self::NS_STYLE) === 'table-cell') {
                $name = $reader->getAttributeNs('name', self::NS_STYLE);
                $dataStyle = $reader->getAttributeNs('data-style-name', self::NS_STYLE);
                if ($name !== null && $dataStyle !== null) {
                    $cellToDataStyles[$name] = $dataStyle;
                }
            }
        } elseif ($reader->localName === 'time-style' && $reader->namespaceURI === self::NS_NUMBER) {
            $name = $reader->getAttributeNs('name', self::NS_STYLE);
            if ($name !== null) {
                $dataStyleDurations[$name] =
                    $reader->getAttributeNs(
                        'truncate-on-overflow',
                        self::NS_NUMBER,
                    ) === 'false';
            }
        }
    }

    /**
     * Join the cell-style → data-style and data-style → duration maps.
     *
     * @param array<string, string> $cellToDataStyles
     * @param array<string, bool> $dataStyleDurations
     * @return array<string, bool> cell style name => is duration
     */
    private static function buildTimeStyleMap(array $cellToDataStyles, array $dataStyleDurations): array
    {
        $map = [];
        foreach ($cellToDataStyles as $cellName => $dataStyle) {
            if (array_key_exists($dataStyle, $dataStyleDurations)) {
                $map[$cellName] = $dataStyleDurations[$dataStyle];
            }
        }
        return $map;
    }

    /**
     * Check if the given table index/name matches the requested sheet.
     */
    private function isTargetSheet(int $tableIndex, ?string $name): bool
    {
        if ($this->sheet === null && $tableIndex === 0) {
            return true;
        }
        if (is_int($this->sheet) && $tableIndex === $this->sheet) {
            return true;
        }
        if (is_string($this->sheet) && $name === $this->sheet) {
            return true;
        }

        return false;
    }

    /**
     * @param ?HeaderSchema $schema
     * @param ?int $totalColumns
     * @param int $yieldCount
     * @param ?HeaderSchema $selectionSchema
     * @param array<string, bool> $timeStyles cell style name => is duration
     * @return Generator<int, Row>
     */
    private function parseTable(
        \XMLReader $reader,
        ?HeaderSchema &$schema,
        ?int &$totalColumns,
        int &$yieldCount,
        ?HeaderSchema &$selectionSchema,
        array $timeStyles,
    ): Generator {
        $tableDepth = $reader->depth;
        $moved = $reader->read();
        $headerRowsBuffer = [];
        $headerOffsetCount = 0;
        $autoScanning = $this->headerOffset === 'auto';
        $autoWindow = [];

        while ($moved && $reader->depth > $tableDepth) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                $moved = $reader->read();
                continue;
            }
            if ($reader->localName !== 'table-row') {
                $moved = $reader->read();
                continue;
            }
            if ($reader->namespaceURI !== self::NS_TABLE) {
                $moved = $reader->read();
                continue;
            }

            $rowRepeat = (int) ($reader->getAttributeNs('number-rows-repeated', self::NS_TABLE) ?? '1');

            // Parse the physical <table-row> exactly once; number-rows-repeated is then
            // emitted logically below. Re-entering the reader per repeat doesn't work — by
            // the second pass it's no longer positioned on this row at all.
            $rowTemplate = [];
            $isEmpty = true;

            if (!$reader->isEmptyElement) {
                $rowDepth = $reader->depth;
                $moved = $reader->read();

                while ($moved && $reader->depth > $rowDepth) {
                    if (
                        $reader->nodeType === \XMLReader::ELEMENT
                        && $reader->localName === 'table-cell'
                        && $reader->namespaceURI === self::NS_TABLE
                    ) {
                        $colRepeat = (int) ($reader->getAttributeNs('number-columns-repeated', self::NS_TABLE) ?? '1');
                        $colIndex = count($rowTemplate);
                        if (($colIndex + $colRepeat) > self::MAX_COLUMNS) {
                            throw new InvalidDocumentException(
                                'Row exceeds the maximum number of columns (' . self::MAX_COLUMNS . ').',
                            );
                        }

                        // Optimization: Skip parsing unselected cells
                        $selectedInRange = false;
                        if ($selectionSchema !== null) {
                            $indices = $selectionSchema->indices();
                            for ($i = 0; $i < $colRepeat; $i++) {
                                if (in_array($colIndex + $i, $indices, true)) {
                                    $selectedInRange = true;
                                    break;
                                }
                            }
                        } else {
                            $selectedInRange = true;
                        }

                        if (!$selectedInRange) {
                            if (!$reader->isEmptyElement) {
                                $moved = $reader->next();
                            } else {
                                $moved = $reader->read();
                            }
                            for ($i = 0; $i < $colRepeat; $i++) {
                                $rowTemplate[] = null;
                            }
                            continue;
                        }

                        $valueType = $reader->getAttributeNs('value-type', self::NS_OFFICE) ?? '';
                        $cellStyleName = $reader->getAttributeNs('style-name', self::NS_TABLE);
                        $value = null;

                        if (
                            $valueType === 'float'
                            || $valueType === 'currency'
                            || $valueType === 'percentage'
                        ) {
                            $value = $reader->getAttributeNs('value', self::NS_OFFICE);
                        } elseif ($valueType === 'date') {
                            $value = $reader->getAttributeNs('date-value', self::NS_OFFICE);
                        } elseif ($valueType === 'time') {
                            $value = $reader->getAttributeNs('time-value', self::NS_OFFICE);
                        } elseif ($valueType === 'boolean') {
                            $value = $reader->getAttributeNs('boolean-value', self::NS_OFFICE);
                            $value = $value === 'true' || $value === '1' ? '1' : '0';
                        }

                        $textP = '';
                        if (!$reader->isEmptyElement) {
                            $cellDepth = $reader->depth;
                            while ($reader->read() && $reader->depth > $cellDepth) {
                                if (
                                    $reader->nodeType === \XMLReader::ELEMENT
                                    && $reader->localName === 'p'
                                    && $reader->namespaceURI === self::NS_TEXT
                                ) {
                                    // readString() is much faster and uses less memory than expand()->textContent
                                    $textP = $reader->readString();
                                }
                            }
                        }

                        if ($this->stringifyValues) {
                            // Compatibility mode: preserve the raw ODF lexical value
                            // (e.g. "2026-08-13T14:30:15", "PT14H30M15S"), exactly as
                            // the historical reader returned it.
                            $typed = $this->legacyCellValue($value, $valueType, $textP);
                        } else {
                            $typed = $this->decodeTypedCell($value, $valueType, $textP, $timeStyles, $cellStyleName);
                        }

                        if ($typed === null && $colRepeat > 100) {
                            break;
                        }

                        for ($ci = 0; $ci < $colRepeat; $ci++) {
                            $rowTemplate[] = $typed;
                            if ($typed !== null && $typed !== '') {
                                $isEmpty = false;
                            }
                        }
                    }
                    $moved = $reader->read();
                }
            }

            if ($isEmpty && $this->skipEmptyLines) {
                $moved = $reader->next();
                continue;
            }

            if ($rowRepeat > self::MAX_ROW_REPEAT) {
                throw new InvalidDocumentException(
                    'number-rows-repeated ('
                    . $rowRepeat
                    . ') exceeds the maximum allowed ('
                    . self::MAX_ROW_REPEAT
                    . ').',
                );
            }

            for ($ri = 0; $ri < $rowRepeat; $ri++) {
                $rowData = $rowTemplate;

                // Skip rows before the header block (explicit int offset)
                if (
                    !$autoScanning
                    && $this->headerOffset !== null
                    && $this->headerOffset !== 'auto'
                    && $headerOffsetCount < $this->headerOffset
                ) {
                    $headerOffsetCount++;
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
                        } catch (InvalidDocumentException|MissingColumnException) {
                            // Not matched — keep scanning
                        }
                    }
                    continue;
                }

                if ($this->strict && $totalColumns !== null && count($rowData) !== $totalColumns) {
                    $colCount = count($rowData);
                    $rowIdx = $yieldCount + 1;
                    throw new InvalidRowException(
                        "Row {$rowIdx} has {$colCount} columns, expected {$totalColumns}",
                        row: $rowIdx,
                    );
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
                        continue;
                    }
                    $rowData = array_slice(
                        array_pad($rowData, $totalColumns ?? 0, null),
                        0,
                        $totalColumns ?? 0,
                    );
                    // Map with selection schema or full schema, not both
                    if ($selectionSchema !== null) {
                        $rowData = $selectionSchema->mapRow($rowData);
                    } else {
                        $rowData = $schema->mapRow($rowData);
                    }
                } else {
                    if ($totalColumns === null) {
                        $totalColumns = count($rowData);
                    }
                    if ($selectionSchema !== null) {
                        $selected = [];
                        foreach ($selectionSchema->indices() as $i) {
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

            $moved = $reader->next();
        }

        if ($autoScanning) {
            throw new InvalidDocumentException(
                'Could not auto-detect header position. Ensure required columns exist.',
            );
        }
    }

    /**
     * Historical reader behavior for stringifyValues mode: return the raw ODF
     * lexical value, falling back to the display text for string cells.
     */
    private function legacyCellValue(?string $value, string $valueType, string $textP): ?string
    {
        if ($value !== null) {
            return $value;
        }
        if ($valueType !== 'string' && $valueType !== '') {
            return null;
        }
        return $textP !== '' ? $textP : null;
    }

    /**
     * Decode a raw ODF cell into its semantic PHP value (native mode).
     *
     * @param ?string $value Raw office:* attribute value for typed cells.
     * @param string $valueType office:value-type value.
     * @param string $textP Display text content of the cell.
     * @param array<string, bool> $timeStyles cell style name => is duration
     * @param ?string $cellStyleName table:style-name of the cell.
     */
    private function decodeTypedCell(
        ?string $value,
        string $valueType,
        string $textP,
        array $timeStyles,
        ?string $cellStyleName,
    ): mixed {
        if (
            $valueType === 'float'
            || $valueType === 'currency'
            || $valueType === 'percentage'
        ) {
            return $value !== null ? Spread::parseNumericValue($value) : null;
        }
        if ($valueType === 'date') {
            if ($value === null || $value === '') {
                return null;
            }
            return new \DateTimeImmutable($value, Spread::utc());
        }
        if ($valueType === 'time') {
            if ($value === null || $value === '') {
                return null;
            }
            $microseconds = Spread::parseIsoDurationToMicroseconds($value);
            // A duration style marks an elapsed duration regardless of magnitude;
            // a negative value can never be a time of day. Otherwise a time-style
            // (or an unknown style) only carries a duration past a single day.
            $isDuration = $timeStyles[$cellStyleName ?? ''] ?? false;
            if ($isDuration || $microseconds < 0 || $microseconds >= TimeValue::MICROSECONDS_PER_DAY) {
                return Spread::durationFromMicroseconds($microseconds);
            }
            return TimeValue::fromMicroseconds($microseconds);
        }
        if ($valueType === 'boolean') {
            return $value === 'true' || $value === '1';
        }
        if ($valueType === 'string' || $valueType === '') {
            return $textP !== '' ? $textP : null;
        }
        return null;
    }
}
