<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

/**
 * Bag of all configurable options for readers/writers.
 */
class Options
{
    public function __construct(
        // ─── General ──────────────────────────────
        /**
         * @var bool If true, readers return associative arrays using the first row as keys.
         */
        public bool $assoc = false,
        /**
         * @var bool Reader option: enforce strict row width matching the header count.
         *           CsvWriter additionally honours it to reject ragged list rows;
         *           XLSX/ODS writers ignore it.
         */
        public bool $strict = false,
        /**
         * @var bool If true, writers stream the output directly to stdout instead of buffering.
         *           Buffering costs temporary disk for XLSX/ODS but PHP memory for CSV;
         *           see docs/streaming.md before setting it for several formats at once.
         */
        public bool $stream = true,
        /** @var bool If true, empty lines are skipped during reading. */
        public bool $skipEmptyLines = true,
        /** @var int Number of rows to skip at the beginning. */
        public int $offset = 0,
        /** @var ?int Maximum number of rows to read. */
        public ?int $limit = null,
        /** @var ?string Directory path for temporary files during extraction/creation. */
        public ?string $tempPath = null,
        // ─── Headers / schema ─────────────────────
        /**
         * @var string[] Predefined headers to use for reading or writing.
         */
        public array $headers = [],
        /** @var int Number of consecutive rows that define the header (1 = flat, >1 = hierarchical). */
        public int $headerRows = 1,
        /**
         * @var int|string|null Number of logical records to skip before the header block starts.
         *                    A logical record is a row the reader would otherwise emit:
         *                    rows dropped by skipEmptyLines never count, and an ODS
         *                    row repeated N times counts N — identically across
         *                    CSV, XLSX and ODS readers.
         *                    null  = BC behaviour (no header offset).
         *                    int   = records to skip before header (e.g. 2 = skip 2 rows, header starts on 3rd).
         *                    'auto' = automatically detect header position (requires requiredColumns).
         */
        public int|string|null $headerOffset = null,
        /**
         * @var null|callable(string): string Callback applied to source headers before schema validation,
         *                                      requiredColumns, columns and aliases. Receives a single header
         *                                      cell string and must return the normalized string. Applied to
         *                                      non-empty header cells only.
         */
        public $headerNormalizer = null,
        /** @var string[] Required column names that must exist in the header row. */
        public array $requiredColumns = [],
        /** @var string[] Columns to extract (selects and reorders). Empty = all columns. */
        public array $columns = [],
        /**
         * @var array<string|int, string|array<array-key, mixed>> Column aliases for renaming.
         *                              Flat:    ['E-mail' => 'email', 'First Name' => 'first_name']
         *                              Nested:  ['Contact' => ['E-mail' => 'email']]
         */
        public array $aliases = [],
        // ─── Value semantics ──────────────────────
        /**
         * @var bool If true, XLSX/ODS readers return legacy CSV-like strings.
         *           If false, readers expose natural PHP value kinds: numbers and
         *           booleans are typed, dates become DateTimeImmutable, while time
         *           and duration remain canonical strings.
         *
         *           INTERIM DEFAULT: currently true to preserve BC behavior. Flip to false
         *           for the 1.0 release, together with Options::$inferNumericStrings.
         */
        public bool $stringifyValues = true,
        /**
         * @var bool If true, writers infer numeric cells from canonically numeric strings
         *           (legacy behavior). If false, a PHP string always means spreadsheet text.
         *
         *           INTERIM DEFAULT: currently true to preserve BC behavior. Flip to false
         *           for the 1.0 release, together with Options::$stringifyValues.
         */
        public bool $inferNumericStrings = true,
        /**
         * @var bool If true, XLSX/ODS writers store every non-empty value as
         *           spreadsheet text. Null and empty strings remain empty cells.
         */
        public bool $forceText = false,
        // ─── CSV ──────────────────────────────────
        /** @var string The delimiter used for CSV fields ("auto" attempts to guess). */
        public string $separator = 'auto',
        /** @var string The enclosure character for CSV fields. */
        public string $enclosure = '"',
        /** @var string The escape character for CSV fields. */
        public string $escape = '',
        /** @var string The end-of-line character sequence for CSV files. */
        public string $eol = "\r\n",
        /** @var ?string Source encoding for reading CSV files. */
        public ?string $inputEncoding = null,
        /** @var ?string Target encoding for writing CSV files. */
        public ?string $outputEncoding = null,
        /** @var bool If true, skip the BOM sequence at the beginning of the CSV input. */
        public bool $skipInputBOM = true,
        /** @var bool If true, transcode the CSV input according to the detected BOM. */
        public bool $transcodeBomInput = true,
        /** @var bool|\LeKoala\Baresheet\Bom|string If true, writes a UTF-8 BOM, otherwise accepts a specific Bom enum or sequence string. */
        public bool|\LeKoala\Baresheet\Bom|string $bom = true,
        /**
         * @var bool|callable If true, escapes formulas starting with `=`, `+`, `-`, or `@` to prevent injection.
         *                    If a callable, it receives (string $cell, int $colIndex) and should return the processed cell.
         */
        public $escapeFormulas = false,
        // ─── XLSX & ODS ───────────────────────────
        /**
         * @var Meta|array<string, mixed>|null Optional metadata for the generated document.
         */
        public Meta|array|null $meta = null,
        /** @var ?string Apply autofilter to range (e.g. A1:B1) (XLSX only) */
        public ?string $autofilter = null,
        /** @var ?string Freeze panes starting from cell (e.g. A2) (XLSX only) */
        public ?string $freezePane = null,
        /**
         * @var bool|string Protect the XLSX sheet from editing. True locks without a password;
         *                  a string locks with an Excel sheet-protection password.
         */
        public bool|string $sheetProtection = false,
        /**
         * @var string|int|null
         * For readers: The sheet name or index to read. If null, the first sheet is read.
         * For writers: The name of the sheet to create. If null, it defaults to 'Sheet1' internally.
         */
        public string|int|null $sheet = null,
        /** @var bool If true, formats the first row's cells as bold text. */
        public bool $boldHeaders = false,
        /** @var bool If true, enables shared strings for XLSX files (faster writing when false). */
        public bool $sharedStrings = false,
        /** @var bool If true, enables auto column width for XLSX files (faster writing when false). */
        public bool $autoWidth = false,
        /**
         * @var array<int|string, int|float> Explicit XLSX column widths, keyed by 0-based
         *      column index or Excel letter ('A', 'C'…). They override autoWidth for
         *      those columns and are emitted even when autoWidth is off. XLSX only.
         */
        public array $columnWidths = [],
        /**
         * @var array<int|string, string> Explicit XLSX number formats, keyed by 0-based
         *      column index or Excel letter ('A', 'C'…). The format is applied to every
         *      cell of the column regardless of the PHP value type. '@' forces text
         *      cells (preserves leading '+'/'0'); other codes (e.g. '0.00') style
         *      numeric cells. XLSX only.
         */
        public array $columnFormats = [],
        /**
         * @var ?float Lower bound for autoWidth-measured XLSX column widths.
         *             Replaces the built-in floor of 8 when set. XLSX only.
         */
        public ?float $minColumnWidth = null,
        /** @var ?float Upper bound for autoWidth-measured XLSX column widths. XLSX only. */
        public ?float $maxColumnWidth = null,
        /**
         * @var ?string Rows repeated at the top of each printed page, given as a row
         *              number or range like '1' or '1:2'. XLSX writer only.
         */
        public ?string $printTitleRows = null,
        /** @var ?int Maximum allowed size for the streamed worksheet or content XML file in bytes. */
        public ?int $maxWorksheetSize = 500_000_000,
    ) {
        if ($this->offset < 0) {
            throw new \InvalidArgumentException('Offset must be >= 0, got ' . $this->offset);
        }
        if ($this->maxWorksheetSize !== null && $this->maxWorksheetSize <= 0) {
            throw new \InvalidArgumentException(
                'maxWorksheetSize must be greater than 0 or null, got ' . $this->maxWorksheetSize,
            );
        }
        if ($this->limit !== null && $this->limit < 0) {
            throw new \InvalidArgumentException('Limit must be >= 0 or null, got ' . $this->limit);
        }
        if ($this->headerRows < 1) {
            throw new \InvalidArgumentException('headerRows must be >= 1, got ' . $this->headerRows);
        }
        if (
            $this->headerOffset !== null
            && $this->headerOffset !== 'auto'
            && (!is_int($this->headerOffset)
            || $this->headerOffset < 0)
        ) {
            throw new \InvalidArgumentException(
                'headerOffset must be null, a non-negative integer, or "auto", got '
                    . var_export($this->headerOffset, true),
            );
        }
        if ($this->separator !== 'auto' && strlen($this->separator) !== 1) {
            throw new \InvalidArgumentException(
                'Separator must be a single character or "auto", got "' . $this->separator . '"',
            );
        }
        if (strlen($this->enclosure) !== 1) {
            throw new \InvalidArgumentException('Enclosure must be a single character, got "' . $this->enclosure . '"');
        }
        if ($this->escape !== '' && strlen($this->escape) !== 1) {
            throw new \InvalidArgumentException(
                'Escape must be a single character or empty, got "' . $this->escape . '"',
            );
        }
        if ($this->minColumnWidth !== null && $this->minColumnWidth <= 0) {
            throw new \InvalidArgumentException(
                'minColumnWidth must be > 0, got ' . $this->minColumnWidth,
            );
        }
        if ($this->maxColumnWidth !== null && $this->maxColumnWidth <= 0) {
            throw new \InvalidArgumentException(
                'maxColumnWidth must be > 0, got ' . $this->maxColumnWidth,
            );
        }
        if (
            $this->minColumnWidth !== null
            && $this->maxColumnWidth !== null
            && $this->minColumnWidth > $this->maxColumnWidth
        ) {
            throw new \InvalidArgumentException('minColumnWidth must be <= maxColumnWidth');
        }
        if (
            $this->printTitleRows !== null
            && (preg_match('/^(\d+)(?::(\d+))?$/', $this->printTitleRows, $m) !== 1
            || (isset($m[2])
            && (int) $m[1] > (int) $m[2]))
        ) {
            throw new \InvalidArgumentException(
                'printTitleRows must be a row number or ascending range like "1" or "1:2", got '
                    . var_export($this->printTitleRows, true),
            );
        }
        foreach ($this->columnWidths as $key => $width) {
            Spread::columnIndex0($key, 'columnWidths');
            if ($width <= 0) {
                throw new \InvalidArgumentException(
                    'columnWidths values must be positive numbers, got ' . var_export($width, true),
                );
            }
        }
        foreach ($this->columnFormats as $key => $format) {
            Spread::columnIndex0($key, 'columnFormats');
            Spread::validateNumberFormat($format, 'columnFormats');
        }
    }

    /**
     * Copy matching properties to the target object.
     */
    public function applyTo(object $target): void
    {
        foreach (get_object_vars($this) as $k => $v) {
            if (property_exists($target, $k)) {
                $target->$k = $v;
            }
        }
    }
}
