<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Exception;
use Generator;
use ZipArchive;

/**
 * Zero-dependency XLSX reader using ZipArchive + SimpleXML.
 */
class XlsxReader implements ReaderInterface
{
    public bool $assoc = false;
    public bool $strict = false;
    public ?int $limit = null;
    public int $offset = 0;
    public bool $skipEmptyLines = true;
    public string|int|null $sheet = null;
    /** @var string[] */
    public array $requiredColumns = [];

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

        // Flatten shared strings into a plain array for O(1) index lookup during row parsing.
        // Traversing a SimpleXMLElement on every cell would be O(n) per row.
        $sharedStrings = [];
        $ssData = Spread::zipGetData($zip, 'xl/sharedStrings.xml');
        if ($ssData) {
            $ssXml = Spread::safeXml($ssData);
            if (isset($ssXml->si)) {
                foreach ($ssXml->si as $si) {
                    if (isset($si->t)) {
                        $sharedStrings[] = (string)$si->t;
                    } elseif (isset($si->r)) {
                        // Rich text: concatenate all <r><t> runs into a single string.
                        $t = '';
                        foreach ($si->r as $r) {
                            $t .= (string)$r->t;
                        }
                        $sharedStrings[] = $t;
                    } else {
                        $sharedStrings[] = '';
                    }
                }
            }
        }

        // Styles
        $numericalFormats = [];
        $cellFormats = [];
        $stylesData = Spread::zipGetData($zip, 'xl/styles.xml');
        if ($stylesData) {
            $stylesXml = Spread::safeXml($stylesData);

            if (isset($stylesXml->numFmts)) {
                foreach ($stylesXml->numFmts->children() as $fmt) {
                    $attrs = $fmt->attributes();
                    $numericalFormats[(string)$attrs->numFmtId] = (string)$attrs->formatCode;
                }
            }

            if (isset($stylesXml->cellXfs->xf)) {
                foreach ($stylesXml->cellXfs->xf as $v) {
                    /** @var ?\SimpleXMLElement $numFmtId */
                    $numFmtId = $v->attributes()['numFmtId'];
                    $fmtId = (string)$numFmtId;

                    $cellFormat = $numericalFormats[$fmtId] ?? null;

                    if ($cellFormat === null) {
                        $cellFormat = self::getBuiltInFormatCode(intval($fmtId));
                    }

                    $cellFormats[] = $cellFormat;
                }
            }
        }

        // Check 1904 date system
        $is1904 = false;
        $wbData = Spread::zipGetData($zip, 'xl/workbook.xml');
        if ($wbData) {
            $wbXml = Spread::safeXml($wbData);
            if (isset($wbXml->workbookPr)) {
                $date1904 = (string)$wbXml->workbookPr['date1904'];
                $is1904 = ($date1904 === '1' || strtolower($date1904) === 'true');
            }
        }

        // Resolve worksheet path from sheet name/index
        $wsPath = $this->resolveSheetPath($zip);
        $wsIdx = $zip->locateName($wsPath);
        if ($wsIdx === false) {
            $zip->close();
            throw new Exception("No data");
        }
        $zip->close();

        $totalColumns = null;
        $colFormats = [];
        $isDateCache = [];

        // Open the worksheet XML as a zip:// stream directly — avoids writing a temp file first,
        // saving a full disk write+read cycle (~40ms on typical hardware).
        $reader = new \XMLReader();
        $reader->open('zip://' . $filename . '#' . $wsPath, null, LIBXML_NONET);

        $headers = null;
        $rowCount = 0;
        $yieldCount = 0;
        $startRow = $this->assoc ? 1 : 0;
        $totalColumns = null;
        $colRefCache = [];

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'row') {
                $rowCount++;
                $rowData = [];
                $col = 0;
                $isEmpty = true;

                if (!$reader->isEmptyElement) {
                    $rowDepth = $reader->depth;
                    while ($reader->read() && $reader->depth > $rowDepth) {
                        if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'c') {
                            $t = $reader->getAttribute('t') ?? '';
                            $r = $reader->getAttribute('r') ?? '';
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
                                                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 't') {
                                                    $v .= $reader->readString();
                                                }
                                            }
                                        }
                                    }
                                }
                            }

                            $format = null;
                            $cellIndex = $col;
                            if ($r !== '') {
                                $colLetter = rtrim($r, '0123456789');
                                // Cache column letter → index: columnIndex() only runs once per column.
                                $cellIndex = $colRefCache[$colLetter] ?? null;
                                if ($cellIndex === null) {
                                    $cellIndex = Spread::columnIndex($colLetter) - 1;
                                    $colRefCache[$colLetter] = $cellIndex;
                                }
                            }

                            while ($cellIndex > $col) {
                                $rowData[] = null;
                                $col++;
                            }

                            if ($t === 's') {
                                $idx = (int)$v;
                                $v = $sharedStrings[$idx] ?? '';
                            }

                            $excelFormat = null;
                            $isDateFormat = false;
                            if ($s !== '') {
                                $excelFormat = $cellFormats[(int)$s] ?? null;
                                if ($excelFormat) {
                                    if (!isset($isDateCache[$excelFormat])) {
                                        $isDateCache[$excelFormat] = self::isDateTimeFormatCode($excelFormat);
                                    }
                                    $isDateFormat = $isDateCache[$excelFormat];
                                }
                                $format = $isDateFormat ? 'date' : null;
                            }

                            if ($t === 'n' && is_numeric($v)) {
                                if ($excelFormat === null) {
                                    $format = $colFormats[$col] ?? null;
                                } else {
                                    $format = $isDateFormat ? 'date' : 'number';
                                }
                            }

                            if ($format !== null && $rowCount > $startRow && !isset($colFormats[$col])) {
                                $colFormats[$col] = $format;
                            }

                            if ($format === 'date') {
                                $v = Spread::excelDateToString($v, null, $is1904);
                            }

                            if ($v !== '') {
                                $isEmpty = false;
                            }

                            $rowData[] = $v;
                            $col++;
                        }
                    }
                }

                while ($totalColumns && $col < $totalColumns) {
                    $rowData[] = null;
                    $col++;
                }

                if ($isEmpty && $this->skipEmptyLines) {
                    continue;
                }
                if ($this->assoc) {
                    if ($headers === null) {
                        $headers = array_map('strval', $rowData);
                        $totalColumns = count($headers);
                        // Validate required columns
                        if (!empty($this->requiredColumns)) {
                            $missing = array_diff($this->requiredColumns, $headers);
                            if (!empty($missing)) {
                                $reader->close();
                                throw new \RuntimeException(
                                    'Missing required columns: ' . implode(', ', $missing)
                                );
                            }
                        }
                        continue;
                    }
                    $rowData = array_combine($headers, array_slice($rowData, 0, $totalColumns));
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
        }
        $reader->close();
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
            $name = (string)$attrs->name;
            $rId = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
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
            throw new Exception("Sheet '{$this->sheet}' not found");
        }

        // Resolve rId to target path from workbook relationships
        $relsData = Spread::zipGetData($zip, 'xl/_rels/workbook.xml.rels');
        if ($relsData) {
            $relsXml = Spread::safeXml($relsData);
            foreach ($relsXml->Relationship as $rel) {
                if ((string)$rel['Id'] === $target) {
                    return 'xl/' . (string)$rel['Target'];
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
            27 => '[$-404]e/m/d',
            30 => 'm/d/yy',
            36 => '[$-404]e/m/d',
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
            50 => '[$-404]e/m/d',
            57 => '[$-404]e/m/d',
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
        $lowerCode = strtolower($excelFormatCode);
        if ($lowerCode === 'general') {
            return false;
        }

        // Remove locale/bucket identifiers [$-409] or duration brackets [h]
        $cleanCode = str_replace(['[', ']', '.000', '\\'], '', $lowerCode);

        // Standard markers
        if (
            str_contains($cleanCode, 'yy') ||
            str_contains($cleanCode, 'dd') ||
            str_contains($cleanCode, 'mm') ||
            str_contains($cleanCode, 'hh') ||
            str_contains($cleanCode, 'ss')
        ) {
            return true;
        }

        // Single letter markers with separators
        if (
            str_contains($cleanCode, 'd/m') ||
            str_contains($cleanCode, 'm/d') ||
            str_contains($cleanCode, 'h:m') ||
            str_contains($cleanCode, 'm:s') ||
            str_contains($cleanCode, 'am/pm') ||
            str_contains($cleanCode, 'a/p')
        ) {
            return true;
        }

        // Special codes
        if (str_contains($cleanCode, 'e/m/d') || $cleanCode === 'ww') {
            return true;
        }

        return false;
    }
}
