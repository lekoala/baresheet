<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Exception;
use Generator;
use ZipArchive;

/**
 * Zero-dependency ODS reader using ZipArchive + SimpleXML.
 */
class OdsReader implements ReaderInterface
{
    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    public bool $assoc = false;
    public bool $strict = false;
    public ?int $limit = null;
    public int $offset = 0;
    public bool $skipEmptyLines = true;
    public string|int|null $sheet = null;

    public function __construct(?Options $options = null)
    {
        $options?->applyTo($this);
    }

    /**
     * @return Generator<mixed>
     */
    public function readFile(string $filename, ?Options $options = null): Generator
    {
        $options?->applyTo($this);

        Spread::isSafePath($filename);
        if (!is_file($filename)) {
            throw new Exception("Invalid file $filename");
        }
        if (!is_readable($filename)) {
            throw new Exception("File $filename is not readable");
        }

        $zip = new ZipArchive();
        $result = $zip->open($filename);
        if ($result !== true) {
            throw new Exception("Failed to open zip archive, code: " . Spread::zipError($result));
        }
        if ($zip->locateName('content.xml') === false) {
            $zip->close();
            throw new Exception("No content.xml found in ODS file");
        }
        $zip->close();

        // Open content.xml as a zip:// stream directly — avoids writing a temp file first,
        // saving a full disk write+read cycle (~40ms on typical hardware).
        yield from $this->parseContent('zip://' . $filename . '#content.xml');
    }

    /**
     * @return Generator<mixed>
     */
    public function readString(string $contents, ?Options $options = null): Generator
    {
        $options?->applyTo($this);
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
        $reader->open($xmlFile, null, LIBXML_NONET);

        $tableIndex = 0;
        $targetTableFound = false;

        $headers = null;
        $totalColumns = null;
        $yieldCount = 0;

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'table' && $reader->namespaceURI === self::NS_TABLE) {
                $name = $reader->getAttributeNs('name', self::NS_TABLE);
                $isTarget = false;

                if ($this->sheet === null && $tableIndex === 0) {
                    $isTarget = true;
                } elseif (is_int($this->sheet) && $tableIndex === $this->sheet) {
                    $isTarget = true;
                } elseif (is_string($this->sheet) && $name === $this->sheet) {
                    $isTarget = true;
                }

                if ($isTarget) {
                    $targetTableFound = true;
                    if (!$reader->isEmptyElement) {
                        $tableDepth = $reader->depth;
                        $moved = $reader->read();
                        while ($moved && $reader->depth > $tableDepth) {
                            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'table-row' && $reader->namespaceURI === self::NS_TABLE) {
                                $rowRepeat = (int)($reader->getAttributeNs('number-rows-repeated', self::NS_TABLE) ?: 1);
                                if ($rowRepeat > 100) {
                                    $moved = $reader->next();
                                    continue;
                                }

                                for ($ri = 0; $ri < $rowRepeat; $ri++) {
                                    $rowData = [];
                                    $isEmpty = true;

                                    if (!$reader->isEmptyElement) {
                                        $rowDepth = $reader->depth;
                                        while ($reader->read() && $reader->depth > $rowDepth) {
                                            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'table-cell' && $reader->namespaceURI === self::NS_TABLE) {
                                                $colRepeat = (int)($reader->getAttributeNs('number-columns-repeated', self::NS_TABLE) ?: 1);

                                                $valueType = $reader->getAttributeNs('value-type', self::NS_OFFICE) ?? '';
                                                $value = null;

                                                if ($valueType === 'float' || $valueType === 'currency' || $valueType === 'percentage') {
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
                                                        if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'p' && $reader->namespaceURI === self::NS_TEXT) {
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
                                        }
                                    }

                                    if ($isEmpty && $this->skipEmptyLines) {
                                        break;
                                    }

                                    if ($this->strict && $totalColumns !== null && count($rowData) !== $totalColumns) {
                                        $colCount = count($rowData);
                                        throw new \RuntimeException("Row has $colCount columns, expected $totalColumns");
                                    }

                                    if ($this->assoc) {
                                        if ($headers === null) {
                                            $headers = array_map('strval', $rowData);
                                            $totalColumns = count($headers);
                                            continue;
                                        }
                                        $rowData = array_slice(array_pad($rowData, $totalColumns ?? 0, null), 0, $totalColumns ?? 0);
                                        $rowData = array_combine($headers, $rowData);
                                    } else {
                                        if ($totalColumns === null) {
                                            $totalColumns = count($rowData);
                                        }
                                    }

                                    if ($yieldCount < $this->offset) {
                                        $yieldCount++;
                                        continue;
                                    }

                                    yield $rowData;
                                    $yieldCount++;
                                    if ($this->limit !== null && ($yieldCount - $this->offset) >= $this->limit) {
                                        $reader->close();
                                        return;
                                    }
                                }
                                $moved = $reader->next();
                                continue;
                            }
                            $moved = $reader->read();
                        }
                    }
                    break;
                }
                $tableIndex++;
            }
        }

        $reader->close();

        if (!$targetTableFound && $this->sheet !== null) {
            throw new Exception("Sheet '{$this->sheet}' not found");
        }
    }
}
