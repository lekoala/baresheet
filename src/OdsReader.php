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
 * Zero-dependency ODS reader using ZipArchive + SimpleXML.
 *
 * @phpstan-import-type Row from ReaderInterface
 */
class OdsReader implements ReaderInterface
{
    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

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
        } finally {
            $zip->close();
        }

        // Open content.xml as a zip:// stream directly — avoids writing a temp file first,
        // saving a full disk write+read cycle (~40ms on typical hardware).
        yield from $this->parseContent('zip://' . $filename . '#content.xml');
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
    private function parseContent(string $xmlFile): Generator
    {
        $reader = new \XMLReader();
        if (!$reader->open($xmlFile, null, LIBXML_NONET)) {
            throw new InvalidDocumentException("Failed to open {$xmlFile}");
        }

        try {
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
     * @return Generator<int, Row>
     */
    private function parseTable(
        \XMLReader $reader,
        ?HeaderSchema &$schema,
        ?int &$totalColumns,
        int &$yieldCount,
        ?HeaderSchema &$selectionSchema,
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

                        if ($value === null) {
                            if ($valueType === 'string' || $valueType === '') {
                                $value = $textP !== '' ? $textP : null;
                            }
                        }

                        if ($value === null && $colRepeat > 100) {
                            break;
                        }

                        for ($ci = 0; $ci < $colRepeat; $ci++) {
                            $rowTemplate[] = $value;
                            if ($value !== null && $value !== '') {
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
                    $autoWindow[] = $rowData;
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
                            $headerNames[] = $v !== null ? (string) $v : '';
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
}
