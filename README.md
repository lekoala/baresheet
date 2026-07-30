# Baresheet

Fast, zero-dependency CSV, XLSX, and ODS reader/writer for PHP.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Requirements

- PHP 8.1+
- ext-mbstring (Required for all formats)

### Format Specific (Required for XLSX/ODS)

- ext-zip
- ext-xmlreader, ext-simplexml, ext-libxml (standard XML extensions, usually bundled together)

### Optional

- ext-iconv (Required only for CSV BOM transcoding)
- maennchen/zipstream-php (Required only for streaming XLSX/ODS output)

## Installation

```bash
composer require lekoala/baresheet
```

## Quick Start

```php
use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Spread;

// Auto-detect format from extension
$rows = Baresheet::read('data.xlsx', new Options(assoc: true));
foreach ($rows as $row) {
    echo $row['email'];
}

// Inspect a spreadsheet before choosing the table to import
$sheetNames = Spread::getSheetNames('import.xlsx');
// ['Patients', 'Archive', 'Instructions']

$rows = Baresheet::read('import.xlsx'); // Reads the first sheet by default
$archiveRows = Baresheet::read('import.xlsx', new Options(sheet: 'Archive'));
// CSV is a single table, so do not inspect it for sheets.

// Write — format from extension
Baresheet::write($data, 'output.csv', new Options(bom: false));
Baresheet::write($data, 'output.xlsx', new Options(meta: ['creator' => 'My App']));
Baresheet::write($data, 'output.ods');

// Write to string
$csv = Baresheet::writeString($data, 'csv');
$xlsx = Baresheet::writeString($data, 'xlsx');
$ods = Baresheet::writeString($data, 'ods');

// Write to PHP resource (for PSR-7 or Laravel Responses)
$stream = Baresheet::writeStream($data, 'xlsx');

// Stream as download (sends HTTP headers)
Baresheet::output($data, 'report.xlsx');
```

## Direct Reader/Writer Usage

Concrete classes allow setting properties directly or passing an `Options` object to the constructor:

```php
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

// CSV - Manual pattern
$reader = new CsvReader();
$reader->assoc = true;
$rows = $reader->readFile('data.csv');

// CSV - Options pattern
$writer = new CsvWriter(new Options(
    escapeFormulas: true,
));
$writer->writeFile($data, 'safe-export.csv');

// XLSX - Manual pattern
$reader = new XlsxReader();
$reader->sheet = 'Data';
$rows = $reader->readFile('report.xlsx');

// XLSX - Options pattern
$writer = new XlsxWriter(new Options(
    meta: ['creator' => 'My App'],
));
$writer->writeFile($data, 'report.xlsx');
```

## Features

### CSV

- **Auto delimiter detection** — analyzes a sample to pick the best separator (default: `auto`)
- **BOM handling** — detects and natively transcodes UTF-8/16/32 BOM sequences on the fly via stream filters
- **Formula injection protection** — `escapeFormulas: true` (opt-in security flag, see Security section)
- **RFC 4180 compliant** — handles enclosures, double-quote escaping, and **CRLF (`\r\n`)** line endings by default for maximum interoperability.
- **Column Selection & Aliasing** — Select, reorder, and rename columns during read. Supports hierarchical column selection and aliasing.
- **Stream reading** — `readStream()` for reading from any PHP resource

### XLSX

- **Blazing fast reading** — optimized `XMLReader` with direct `zip://` streaming (2x faster than SimpleXLSX)
- **Data offset** & **Empty line skipping** — safely skip arbitrary leading rows or completely empty lines
- **Extreme memory efficiency** — unified 0.63MB footprint regardless of file size
- **Column Selection** — Skip XML parsing for unselected cells for massive performance gains.
- **Shared string table** — opt-in de-duplication for smaller files (default: `false` for speed)
- **Auto column widths** — opt-in automatic column sizing (default: `false` for speed)
- **DateTime support** — pass `DateTimeInterface` objects directly, seamlessly handles 1900/1904 calendar systems
- **Freeze Pane & Autofilter** — simple options for improved sheet usability
- **Document properties** — set creator, title, subject, keywords, etc. via `meta`

### ODS

- **Streaming reader** — handles large files with minimal 0.63MB memory usage
- **Data offset** & **Empty line skipping** — safely skip arbitrary leading rows or completely empty lines
- **Column Selection** — Skip XML parsing for unselected cells for significant performance gains.
- **Zero-dependency** — uses native `ZipArchive` + `XMLReader`
- **DateTime support** — dates stored accurately in ISO 8601
- **Document properties** — set creator and title via `meta`
- **Sheet selection** — read specific sheets by name or index

> [!NOTE]
> ODS is supported for reading and writing tables. Advanced presentation features — such as `freezePane`, `autoWidth`, and `autofilter` — are intentionally XLSX-only. Baresheet does not aim for feature parity between the formats; use XLSX when those features matter.

### XLSX Sheet Protection

Lock an exported sheet to discourage accidental edits:

```php
// The user can remove the protection without a password.
Baresheet::write($data, 'readonly.xlsx', new Options(sheetProtection: true));

// The user must enter the password to remove the protection in Excel.
Baresheet::write($data, 'protected.xlsx', new Options(sheetProtection: 'change-me'));
```

Sheet protection is not encryption and does not secure the workbook's contents. It is intended to prevent ordinary edits, not to protect sensitive data.

## Options

Readers and writers are configured objects: you set their options once, then read/write as many times as you like with that same configuration. There are three ways to configure an instance:

**1. Directly on properties:**

```php
$reader = new CsvReader();
$reader->assoc = true;
$reader->separator = ";";
```

**2. Via the constructor:**

```php
$reader = new CsvReader(new Options(assoc: true, separator: ";"));
```

**3. Via `Options::applyTo()`**, which gives **full IDE autocomplete** and can reconfigure an already-constructed instance:

```php
use LeKoala\Baresheet\Options;

$opts = new Options(
    assoc: true,
    separator: 'auto',
    meta: ['creator' => 'My App']
);
$opts->applyTo($reader);
```

`readFile()`, `readString()`, `writeFile()`, etc. no longer accept an `Options` argument directly — they simply read/write using whatever configuration the reader/writer instance currently holds. This avoids ambiguity about whether a per-call option leaks into subsequent calls: the instance's configuration *is* its state.

The `Baresheet` facade keeps the convenient one-shot form, since it always creates a fresh reader/writer internally, applies the options to it, then reads/writes once:

```php
$rows = Baresheet::read('data.csv', $opts);
```

| Option            | Type                                       | Default  | Applies to                |
|-------------------|--------------------------------------------|----------|---------------------------|
| `assoc`           | bool                                       | `false`  | Read (All)                |
| `strict`          | bool                                       | `false`  | Read (All), Write (CSV)   |
| `stream`          | bool                                       | `true`   | Output (Any)              |
| `limit`           | ?int                                       | `null`   | Read (All)                |
| `offset`          | int                                        | `0`      | Read (All)                |
| `skipEmptyLines`  | bool                                       | `true`   | Read (All)                |
| `headers`         | string[]\|array<int, string[]>             | `[]`     | Read (All), Write (All)   |
| `headerRows`      | int                                        | `1`      | Read (All), Write (All)   |
| `headerOffset`    | int\|string\|null                          | `null`   | Read (All)                |
| `requiredColumns` | string[]\|array<string\|int,string\|array> | `[]`     | Read (All)                |
| `columns`         | string[]\|array<string\|int,string\|array> | `[]`     | Read (All)                |
| `aliases`         | array<string\|int,string\|array>           | `[]`     | Read (All)                |
| `separator`       | string                                     | `"auto"` | Read (CSV)                |
| `enclosure`       | string                                     | `"`      | Read (CSV)                |
| `escape`          | string                                     | `""`     | Read (CSV)                |
| `eol`             | string                                     | `\r\n`   | Write (CSV)               |
| `inputEncoding`   | ?string                                    | `null`   | Read (CSV)                |
| `outputEncoding`  | ?string                                    | `null`   | Read/Write (CSV)          |
| `bom`             | bool\|string\|Bom                          | `true`   | Write (CSV)               |
| `escapeFormulas`  | bool/callable                              | `false`  | Write (CSV)               |
| `meta`            | array/Meta                                 | `null`   | Write (XLSX, ODS)         |
| `autofilter`      | ?string                                    | `null`   | Write (XLSX)              |
| `freezePane`      | ?string                                    | `null`   | Write (XLSX)              |
| `sheetProtection` | bool\|string                               | `false`  | Write (XLSX)              |
| `sheet`           | string/int                                 | `null`   | Read/Write (XLSX, ODS)    |
| `boldHeaders`     | bool                                       | `false`  | Write (XLSX, ODS)         |
| `tempPath`        | ?string                                    | `null`   | Any (Temp files location) |
| `sharedStrings`   | bool                                       | `false`  | Write (XLSX)              |
| `autoWidth`       | bool                                       | `false`  | Write (XLSX)              |

## Exceptions

Errors originating from a document or a Baresheet read/write operation are thrown as a `LeKoala\Baresheet\Exception\BaresheetException` (a `RuntimeException`), so catching that one type covers everything below. Bad API usage (invalid arguments, wrong call order) is left as native `InvalidArgumentException`/`LogicException` instead.

```text
BaresheetException
├── InvalidDocumentException   // corrupt ZIP, invalid XML, unreadable/unsafe file,
│   │                          // duplicate/ambiguous hierarchical header paths
│   └── SheetNotFoundException // requested sheet name/index doesn't exist
├── InvalidRowException        // strict-mode column count mismatch, invalid strict cast
├── MissingColumnException     // required or explicitly selected column absent from headers
├── UnsupportedFormatException // unknown/unrecognized format or extension
└── WriteException             // write destination/stream/ZIP failure
```

```php
use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Exception\MissingColumnException;
use LeKoala\Baresheet\Exception\BaresheetException;

try {
    $rows = iterator_to_array(Baresheet::read('products.csv', new Options(
        requiredColumns: ['sku', 'price'],
    )));
} catch (MissingColumnException $e) {
    // "Your file must contain the sku and price columns."
} catch (BaresheetException $e) {
    // any other document/operation error
}
```

`InvalidRowException` exposes `$row` and `$column` when available, and `MissingColumnException` exposes the missing `$columns` list, for building precise error messages.

## Required Columns Validation

Validate that input files contain expected columns before processing. This catches malformed files early, avoiding wasted cycles parsing invalid data.

```php
// Throws MissingColumnException if 'email' or 'price' columns are missing
$rows = Baresheet::read('products.csv', new Options(
    assoc: true,
    requiredColumns: ['sku', 'price', 'qty']
));

foreach ($rows as $row) {
    // All required columns are guaranteed to exist
    processProduct($row);
}
```

The validation occurs immediately after reading the header row and throws a `MissingColumnException` listing the missing columns:

```
Missing required columns: price, qty
```

Works with all reader formats (CSV, XLSX, ODS) and the `Baresheet` facade.

## Column Selection

Select and reorder specific columns when reading. This is useful for wide files where you only need a subset of columns, or when you need columns in a specific order. Selected columns must exist in the file headers (they are implicitly required).

```php
// Select specific columns (assoc mode returns named array)
$rows = Baresheet::read('data.csv', new Options(
    assoc: true,
    columns: ['email', 'name']  // Only these columns, in this order
));

foreach ($rows as $row) {
    // $row contains only ['email' => '...', 'name' => '...']
}
```

### Reordering Columns

Column selection also allows reordering:

```php
// File has: name, email, age (in that order)
// Output: age first, then name
$rows = Baresheet::read('data.csv', new Options(
    assoc: true,
    columns: ['age', 'name']
));
```

### Plain Mode with Column Selection

When using `assoc: false`, provide explicit headers and receive values in plain arrays:

```php
$rows = Baresheet::read('data.csv', new Options(
    assoc: false,
    headers: ['email', 'name', 'age'],
    columns: ['name', 'email']
));

foreach ($rows as $row) {
    // $row contains: ['John', 'john@example.com'] (values only)
}
```

### Working with Headerless Files

When reading files without header rows, you can inject column names using the `headers` option. This enables column selection and associative output even for plain data files:

```php
// File has no headers, just raw data:
// 1,John Doe,john@example.com,50000
// 2,Jane Smith,jane@example.com,60000

$rows = Baresheet::read('data.csv', new Options(
    headers: ['id', 'name', 'email', 'salary'],  // Define column structure
    columns: ['id', 'email', 'salary'],          // Select specific columns
    assoc: true                                  // Get named array output
));

foreach ($rows as $row) {
    // $row contains: ['id' => 1, 'email' => 'john@example.com', 'salary' => 50000]
}
```

This works with all reader formats (CSV, XLSX, ODS) and is useful when:

- Processing legacy data exports without headers
- Working with fixed-format data feeds
- Converting plain arrays to structured data

Column selection provides dramatic performance improvements for XLSX and ODS files by skipping XML parsing for unselected cells. For CSV, it provides a zero-overhead "direct indexing" fast path that avoids intermediate array allocations.

| Format   | 20 columns → 5 columns | **Speedup**      | **Memory/CPU Savings**            |
|----------|------------------------|------------------|-----------------------------------|
| **XLSX** | 2.94s → 1.33s          | **~2.2x faster** | **High** (Skips XML Nodes)        |
| **ODS**  | 1.80s → 1.25s          | **~1.4x faster** | **High** (Skips XML Nodes)        |
| **CSV**  | 0.28s → 0.28s          | **Baseline**     | **90%+** fewer hash-table entries |

> [!TIP]
> **XLSX & ODS Performance**: Column selection provides dramatic speedups for XLSX and ODS files by skipping XML parsing for unselected cells. CSV benefits from a streamlined mapping path with zero intermediate allocations.

### Error Handling

Missing columns throw immediately:

```
MissingColumnException: Missing required columns: missing_column
```

### Hierarchical Headers

Baresheet supports multi-row spreadsheet headers, common in real-world exports where column groups span multiple rows:

```csv
Identity,,,Contact,,,Meta,,
id,first name,last name,role,email,phone,type,status,level,department
1,John,Doe,Admin,john@example.com,555-1000,full-time,active,senior,Engineering
```

### Multi-Row Headers

Use `headerRows` to specify how many consecutive rows define the header structure. Baresheet automatically propagates parent cells horizontally and builds a nested schema:

```php
$rows = Baresheet::read('data.csv', new Options(
    assoc: true,
    headerRows: 2,
));

foreach ($rows as $row) {
    // $row = [
    //   'Identity'  => ['id' => 1, 'first name' => 'John', ...],
    //   'Contact'   => ['email' => 'john@example.com', ...],
    //   'Meta'      => ['status' => 'active', ...],
    // ]
}
```

### Hierarchical Selection

Select nested columns using the same tree-like syntax:

```php
$rows = Baresheet::read('data.csv', new Options(
    assoc: true,
    headerRows: 2,
    columns: [
        'Contact' => ['email', 'phone'],
    ],
));
// $row = ['Contact' => ['email' => '...', 'phone' => '...']]
```

### Hierarchical Required Columns

Validate that expected nested columns exist before processing:

```php
$rows = Baresheet::read('data.csv', new Options(
    assoc: true,
    headerRows: 2,
    requiredColumns: [
        'Identity' => ['id'],
        'Contact' => ['email'],
    ],
));
// Throws MissingColumnException if Identity.id or Contact.email are absent
```

### Column Aliases

Rename columns to standardized keys after selection and validation, keeping your business logic decoupled from file-specific naming:

```php
$rows = Baresheet::read('data.csv', new Options(
    assoc: true,
    headerRows: 2,
    aliases: [
        'E-mail' => 'email',
        'Contact' => ['phone' => 'phone_number'],
    ],
));
```

Aliases are applied after `requiredColumns` and `columns`, so those options always reference the original column names present in the file. Duplicate aliases created by renaming (e.g. two columns both renamed to `email`) are rejected with an `InvalidDocumentException`.

### Header Offset

Skip preamble rows (titles, metadata, comments) before the header block:

```php
// Explicit: skip 4 rows before the header
$rows = Baresheet::read('report.csv', new Options(
    assoc: true,
    headerOffset: 4,
));

// Auto-detection: scan forward until required columns are found
$rows = Baresheet::read('export.csv', new Options(
    assoc: true,
    headerOffset: 'auto',
    requiredColumns: ['customer_id', 'email'],
));
```

`headerOffset` works with CSV, XLSX, and ODS readers. The `'auto'` mode uses a streaming rolling window — no second pass, no `maxScan` limit — and requires `requiredColumns` to be set.

### Writing Hierarchical Headers

All writers (CSV, XLSX, ODS) accept the same hierarchical definition for `headers` and produce multi-row output. Nested data rows are automatically flattened to match the schema:

```php
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\XlsxWriter;
use LeKoala\Baresheet\OdsWriter;

$headers = [
    'Identity' => ['id', 'first name', 'last name'],
    'Contact'  => ['email', 'phone'],
];

$data = [
    [
        'Identity' => ['id' => 1, 'first name' => 'John', 'last name' => 'Doe'],
        'Contact'  => ['email' => 'john@example.com', 'phone' => '555-1000'],
    ],
];

// CSV
(new CsvWriter())->writeFile($data, 'output.csv');

// XLSX — supports boldHeaders for all header rows
$writer = new XlsxWriter();
$writer->headers = $headers;
$writer->boldHeaders = true;
$writer->writeFile($data, 'output.xlsx');

// ODS — same API
$writer = new OdsWriter();
$writer->headers = $headers;
$writer->writeFile($data, 'output.ods');
```

This generates:

```csv
Identity,,,Contact,
id,first name,last name,email,phone
1,John,Doe,john@example.com,555-1000
```

### Strict Mode

When `strict` is enabled, every data row must match the schema's expected column count. During header collection (with `headerRows > 1`), rows may legitimately differ in width — strict validation is deferred until the header block is resolved.

```php
// Read — throws InvalidRowException on mismatched data rows
$reader = new CsvReader(new Options(assoc: true, strict: true, headers: ['a', 'b', 'c']));

// Write — throws WriteException before flattening, catching short/long rows early
$writer = new CsvWriter(new Options(strict: true, headers: ['a', 'b', 'c']));
```

## Data Transformation

Baresheet preserves raw cell values by design. For cleaning, casting, or filtering, use the `Transform` class — generator-based pipelines that compose with readers and writers without loading data into memory.

```php
use LeKoala\Baresheet\Transform;

// Trim whitespace from all string values
$rows = Transform::trim($reader->readFile('data.csv'));

// Chain multiple transforms (PHP 8.5+ pipe operator)
$clean = $reader->readFile('data.csv')
    |> Transform::trim(...)
    |> Transform::nullAs(..., 'N/A')
    |> Transform::boolAs(..., 'Yes', 'No');

// Cast types for database inserts
$typed = Transform::cast($rows, [
    'qty' => 'int',
    'price' => 'float',
    'active' => 'bool',
    'created' => 'date',  // returns DateTimeInterface
]);

// Tell your IDE / PHPStan the expected shape after casting
/** @var Generator<array{qty: int, price: float, active: bool, created: ?DateTimeInterface}> $typed */
$typed = Transform::cast($rows, [
    'qty' => 'int',
    'price' => 'float',
    'active' => 'bool',
    'created' => 'date',
]);

// Filter rows
$active = Transform::filter($rows, fn($row) => $row['active'] === 'Yes');

// Batch insert in chunks of 1000
foreach (Transform::chunk($rows, 1000) as $batch) {
    $db->bulkInsert($batch);
}

// Slice a page of results, without loading everything into memory
$page = Transform::slice($rows, offset: 100, limit: 20);
```

## Streaming Output

For large files, streaming avoids writing a temporary file to disk. **Baresheet streams `output()` by default.**

However, keep in mind that **streaming changes how data is sent to the browser**. Because the total file size is unknown before the transfer starts, the server cannot send a `Content-Length` header. This means the browser download will not display a progress bar or an estimated time of completion.

To bypass streaming and force buffering, use `stream: false` with `output()`. Baresheet will buffer the file (either in memory for CSV, or via a temporary zip file for XLSX/ODS) to precisely calculate and send the `Content-Length` header along with it.

> **Note on XLSX/ODS:** Streaming requires an optional dependency. Install it with:

```bash
composer require maennchen/zipstream-php
```

If the `zipstream-php` dependency is missing, Baresheet will seamlessly and automatically fall back to buffered output.

```php
$writer = new XlsxWriter();
$writer->stream = false;
$writer->output($data, 'report.xlsx');

// or via Options
Baresheet::output($data, 'report.xlsx', new Options(stream: false));
```

## PSR-7 / Response Objects (Symfony, Laravel)

To avoid breaking the flow of your application or sending explicit `header()` calls directly, you should create a Response object when applicable in your framework.

Use the `writeStream()` method to generate the spreadsheet as a memory-capped `php://temp` stream resource, and feed it into your Response class:

### Symfony / Laravel (StreamedResponse)

```php
use LeKoala\Baresheet\XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

$writer = new XlsxWriter();
$stream = $writer->writeStream($data);

return new StreamedResponse(function () use ($stream) {
    fpassthru($stream);
    fclose($stream);
}, 200, [
    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'Content-Disposition' => 'attachment; filename="report.xlsx"',
]);
```

### PSR-7 (Guzzle, Nyholm, etc.)

```php
$stream = Baresheet::writeStream($data, 'xlsx');
$body = new \GuzzleHttp\Psr7\Stream($stream); // wrap the native resource

return $response
    ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    ->withHeader('Content-Disposition', 'attachment; filename="report.xlsx"')
    ->withBody($body);
```

## Performance

> **Indicative benchmarks** — These numbers are intended to catch large performance regressions and highlight architectural differences. Absolute results vary by PHP version, hardware, filesystem and workload. Run `php bin/bench-read.php` / `bin/bench-write.php` locally for results relevant to your environment.
>
> Environment: PHP 8.3.6, 64-bit, 50,000 rows × 4 columns, median of 3 runs.

Baresheet is engineered to minimize server resource footprint. The XLSX and ODS readers use an optimized `XMLReader` approach that opens `zip://` streams directly, avoiding temporary file extraction entirely.

### Reading 50,000 Rows

| Library    | CSV  | XLSX | ODS  | Peak Memory |
|------------|------|------|------|-------------|
| Baresheet  | 1.0× | 1.0× | 1.0× | 0.63 MB     |
| League     | 1.7× | —    | —    | 0.63 MB     |
| SimpleXLSX | —    | 2.2× | —    | 5.78 MB     |
| OpenSpout  | 3.2× | 5.3× | 3.5× | 0.63 MB     |

### Writing 50,000 Rows

| Library       | CSV  | XLSX | ODS  | Peak Memory  |
|---------------|------|------|------|--------------|
| Baresheet     | 1.0× | 1.0× | 1.0× | 0.52–0.95 MB |
| League        | 1.4× | —    | —    | 0.55 MB      |
| SimpleXLSXGen | —    | 1.3× | —    | 109.85 MB    |
| OpenSpout     | 2.9× | 1.9× | 1.7× | 0.47–1.01 MB |

> **XLSX write modes**: By default, Baresheet uses the fastest mode (shared strings and auto column width disabled). Enabling shared strings or auto-width trades speed for file size or presentation — see the Options table for `sharedStrings` and `autoWidth`.

Memory is measured in an isolated subprocess via `memory_get_peak_usage()`. Baresheet's 0.63 MB read footprint stays constant regardless of file size — the stream-based `XMLReader` never loads the entire document into memory.

## Security Considerations

### CSV Formula Injection

When writing CSV files, any cell beginning with `=`, `+`, `-`, or `@` could be interpreted as a formula if the file is opened in spreadsheet software like Microsoft Excel. A maliciously crafted input could lead to execution of arbitrary functions or system commands on the user's local machine.

By default, Baresheet prioritizes **data round-trip integrity**. Attempting to automatically prefix formulas with a single quote (`'`) to disable formula execution corrupts otherwise valid user inputs.

If you are exporting data to be consumed by clients opening the file in Excel, you **must opt-in** to the protection logic:

```php
$writer = new CsvWriter();
$writer->escapeFormulas = true; // Protects against formula injection by prefixing a single-quote
```

#### Selective Formula Escaping

For advanced use cases, `escapeFormulas` also accepts a **callable** that receives the cell value and column index, allowing you to selectively escape only specific columns:

```php
$writer = new CsvWriter();
$writer->escapeFormulas = function (string $cell, int $colIndex): string {
    // Skip phone columns (column 1) to preserve + prefixes
    if ($colIndex === 1) {
        return $cell;
    }
    // Apply default escaping for everything else
    $chars = "=+-@\t\r";
    if ($cell !== '' && str_contains($chars, $cell[0])) {
        return "'" . $cell;
    }
    return $cell;
};
$writer->writeFile($data, 'export.csv');
```

**Important:** Heuristic detection of "malicious" formulas is fundamentally unreliable. Attackers can use `CHAR()` functions to build strings character-by-character, and new attack vectors emerge constantly. The library takes a conservative approach: blanket escaping by default when enabled, or user-controlled selective escaping via callback. For maximum security with user-generated content, prefer **XLSX** format, which has explicit cell type metadata and is immune to formula injection.

## License

MIT
