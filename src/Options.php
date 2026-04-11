<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

/**
 * Bag of all configurable options for readers/writers.
 */
class Options
{
    public function __construct(
        // ─── Common ──────────────────────────────
        /**
         * @var bool If true, readers return associative arrays using the first row as keys.
         */
        public bool $assoc = false,
        /**
         * @var bool If true, readers enforce strict row width matching the header count.
         */
        public bool $strict = false,
        /**
         * @var bool If true, writers stream the output directly to stdout instead of buffering.
         */
        public bool $stream = true,
        /**
         * @var string[] Predefined headers to use for reading or writing.
         */
        public array $headers = [],
        /** @var bool If true, empty lines are skipped during reading. */
        public bool $skipEmptyLines = true,
        /** @var int Number of rows to skip at the beginning. */
        public int $offset = 0,
        /** @var ?int Maximum number of rows to read. */
        public ?int $limit = null,
        /** @var string[] Required column names that must exist in the header row. */
        public array $requiredColumns = [],
        /** @var string[] Columns to extract (selects and reorders). Empty = all columns. */
        public array $columns = [],
        // ─── CSV ─────────────────────────────────
        /** @var string The delimiter used for CSV fields ("auto" attempts to guess). */
        public string $separator = "auto",
        /** @var string The enclosure character for CSV fields. */
        public string $enclosure = "\"",
        /** @var string The escape character for CSV fields. */
        public string $escape = "",
        /** @var string The end-of-line character sequence for CSV files. */
        public string $eol = "\r\n",
        /** @var ?string Source encoding for reading CSV files. */
        public ?string $inputEncoding = null,
        /** @var ?string Target encoding for writing CSV files. */
        public ?string $outputEncoding = null,
        /** @var bool|\LeKoala\Baresheet\Bom|string If true, writes a UTF-8 BOM, otherwise accepts a specific Bom enum or sequence string. */
        public bool|\LeKoala\Baresheet\Bom|string $bom = true,
        /**
         * @var bool|callable If true, escapes formulas starting with `=`, `+`, `-`, or `@` to prevent injection.
         *                    If a callable, it receives (string $cell, int $colIndex) and should return the processed cell.
         */
        public $escapeFormulas = false,
        // ─── XLSX & ODS ──────────────────────────
        /**
         * @var Meta|array<string, mixed>|null Optional metadata for the generated document.
         */
        public Meta|array|null $meta = null,
        /** @var ?string Apply autofilter to range (e.g. A1:B1) (XLSX only) */
        public ?string $autofilter = null,
        /** @var ?string Freeze panes starting from cell (e.g. A2) (XLSX only) */
        public ?string $freezePane = null,
        /**
         * @var string|int|null
         * For readers: The sheet name or index to read. If null, the first sheet is read.
         * For writers: The name of the sheet to create. If null, it defaults to 'Sheet1' internally.
         */
        public string|int|null $sheet = null,
        /** @var bool If true, formats the first row's cells as bold text. */
        public bool $boldHeaders = false,
        /** @var ?string Directory path for temporary files during extraction/creation. */
        public ?string $tempPath = null,
        /** @var bool If true, enables shared strings for XLSX files (faster writing when false). */
        public bool $sharedStrings = false,
        /** @var bool If true, enables auto column width for XLSX files (faster writing when false). */
        public bool $autoWidth = false,
    ) {
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
