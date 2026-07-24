<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Generator;
use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\InvalidRowException;
use LeKoala\Baresheet\Exception\SheetNotFoundException;
use ZipArchive;

/**
 * Zero-dependency ODS reader using ZipArchive + SimpleXML.
 */
class OdsReader implements ReaderInterface
{
    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    private const MAX_ZIP_ENTRY_SIZE = 50_000_000;
    // Caps number-columns-repeated so a single declared cell can't force an
    // absurd number of repetitions of its value into the row.
    private const MAX_COLUMN_REPEAT = 16_384;

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

    public function __construct(?Options $options = null)
    {
        $options?->applyTo($this);
    }

    /**
     * @return Generator<mixed>
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

            // zipGetData() only guards entries it loads into memory itself; content.xml
            // is instead streamed directly via zip:// below, so it needs the same size cap.
            $stat = $zip->statIndex($idx);
            if ($stat !== false && $stat['size'] > self::MAX_ZIP_ENTRY_SIZE) {
                throw new InvalidDocumentException(
                    'ZIP entry \'content.xml\' exceeds maximum allowed size (' . self::MAX_ZIP_ENTRY_SIZE . ' bytes).',
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
     * @return Generator<mixed>
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
     * @return Generator<mixed>
     */
    private function parseContent(string $xmlFile): Generator
    {
        $reader = new \XMLReader();
        if (!$reader->open($xmlFile, null, LIBXML_NONET)) {
            throw new InvalidDocumentException("Failed to open {$xmlFile}");
        }

        try {
            $tableIndex = 0;
            $headers = !empty($this->headers) ? $this->headers : null;
            $totalColumns = $headers !== null ? count($headers) : null;
            $yieldCount = 0;
            $columnMap = [];
            $selectedIndices = []; // Set of column indices to parse (empty = all)

            // Pre-build column map and validate required columns from injected headers
            if (!empty($this->headers)) {
                if ($this->assoc) {
                    Spread::checkNoDuplicateHeaders($this->headers);
                }
                if (!empty($this->requiredColumns)) {
                    Spread::checkRequiredColumns($this->requiredColumns, $this->headers);
                }
                if (!empty($this->columns)) {
                    [$columnMap, $selectedIndices] = Spread::buildColumnSelection($this->columns, $this->headers);
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
                    $headers,
                    $totalColumns,
                    $yieldCount,
                    $columnMap,
                    $selectedIndices,
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
     * @param ?array<string> $headers
     * @param ?int $totalColumns
     * @param int $yieldCount
     * @param array<string, int> $columnMap
     * @param array<int, true> $selectedIndices
     * @return Generator<mixed>
     */
    private function parseTable(
        \XMLReader $reader,
        ?array &$headers,
        ?int &$totalColumns,
        int &$yieldCount,
        array &$columnMap,
        array &$selectedIndices,
    ): Generator {
        $tableDepth = $reader->depth;
        $moved = $reader->read();

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
            if ($rowRepeat > 100) {
                $moved = $reader->next();
                continue;
            }

            for ($ri = 0; $ri < $rowRepeat; $ri++) {
                $rowData = [];
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
                            $colRepeat = (int) (
                                $reader->getAttributeNs('number-columns-repeated', self::NS_TABLE) ?? '1'
                            );
                            if ($colRepeat > self::MAX_COLUMN_REPEAT) {
                                throw new InvalidDocumentException(
                                    "number-columns-repeated ({$colRepeat}) exceeds the maximum allowed ("
                                    . self::MAX_COLUMN_REPEAT
                                    . ').',
                                );
                            }
                            $colIndex = count($rowData);

                            // Optimization: Skip parsing unselected cells
                            $selectedInRange = false;
                            if (!empty($selectedIndices)) {
                                for ($i = 0; $i < $colRepeat; $i++) {
                                    if (isset($selectedIndices[$colIndex + $i])) {
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
                                    $rowData[] = null;
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
                                $rowData[] = $value;
                                if ($value !== null && $value !== '') {
                                    $isEmpty = false;
                                }
                            }
                        }
                        $moved = $reader->read();
                    }
                }

                if ($isEmpty && $this->skipEmptyLines) {
                    break;
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
                    if ($headers === null) {
                        $headers = [];
                        foreach ($rowData as $v) {
                            $headers[] = $v !== null ? (string) $v : '';
                        }
                        $totalColumns = count($headers);
                        Spread::checkNoDuplicateHeaders($headers);
                        // Validate required columns
                        Spread::checkRequiredColumns($this->requiredColumns, $headers, $reader);
                        // Build column selection map
                        [$columnMap, $selectedIndices] = Spread::buildColumnSelection(
                            $this->columns,
                            $headers,
                        );
                        continue;
                    }
                    $rowData = array_slice(
                        array_pad($rowData, $totalColumns ?? 0, null),
                        0,
                        $totalColumns ?? 0,
                    );
                    $rowData = array_combine($headers, $rowData);
                } else {
                    if ($totalColumns === null) {
                        $totalColumns = count($rowData);
                    }
                }

                // Apply column selection
                if (!empty($columnMap)) {
                    $rowData = Spread::applyColumnSelection(
                        $rowData,
                        $columnMap,
                        $this->columns,
                        $this->assoc,
                    );
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
    }
}
