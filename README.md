# Baresheet

Fast, zero-dependency CSV, XLSX, and ODS reader/writer for PHP.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Requirements

- PHP 8.1+
- ext-mbstring (Required for all formats; Symfony polyfill is a valid alternative)

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

// Auto-detect format from extension
$rows = Baresheet::read('data.xlsx', new Options(assoc: true));
foreach ($rows as $row) {
    echo $row['email'];
}

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
- **Column Selection** — Efficiently select, reorder, and alias columns during read.
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

## Options

There are two ways to pass options:

**1. Directly on instances:**

```php
$reader = new CsvReader();
$reader->assoc = true;
$reader->separator = ";";

// Or directly in the constructor
$reader = new CsvReader(new Options(assoc: true, separator: ";"));
```

**2. Options object** (works on any method, including the `Baresheet` facade). The constructor provides **full IDE autocomplete**:

```php
use LeKoala\Baresheet\Options;

$opts = new Options(
    assoc: true, 
    separator: 'auto',
    meta: ['creator' => 'My App']
);
$rows = Baresheet::read('data.csv', $opts);
```

| Option            | Type              | Default  | Applies to                |
|-------------------|-------------------|----------|---------------------------|
| `assoc`           | bool              | `false`  | Read (All)                |
| `strict`          | bool              | `false`  | Read (CSV, XLSX, ODS)     |
| `stream`          | bool              | `true`   | Output (Any)              |
| `limit`           | ?int              | `null`   | Read (All)                |
| `offset`          | int               | `0`      | Read (All)                |
| `skipEmptyLines`  | bool              | `true`   | Read (All)                |
| `headers`         | string[]          | `[]`     | Write (All), Read (CSV)   |
| `separator`       | string            | `"auto"` | Read (CSV)                |
| `enclosure`       | string            | `"`      | Read (CSV)                |
| `escape`          | string            | `""`     | Read (CSV)                |
| `eol`             | string            | `\r\n`   | Write (CSV)               |
| `inputEncoding`   | ?string           | `null`   | Read (CSV)                |
| `outputEncoding`  | ?string           | `null`   | Read (CSV)                |
| `bom`             | bool\|string\|Bom | `true`   | Write (CSV)               |
| `escapeFormulas`  | bool/callable     | `false`  | Write (CSV)               |
| `meta`            | array/Meta        | `null`   | Write (XLSX, ODS)         |
| `autofilter`      | ?string           | `null`   | Write (XLSX)              |
| `freezePane`      | ?string           | `null`   | Write (XLSX)              |
| `sheet`           | string/int        | `null`   | Read/Write (XLSX, ODS)    |
| `boldHeaders`     | bool              | `false`  | Write (XLSX, ODS)         |
| `tempPath`        | ?string           | `null`   | Any (Temp files location) |
| `sharedStrings`   | bool              | `false`  | Write (XLSX)              |
| `autoWidth`       | bool              | `false`  | Write (XLSX)              |
| `requiredColumns` | string[]          | `[]`     | Read (CSV, XLSX, ODS)     |
| `columns`         | string[]          | `[]`     | Read (CSV, XLSX, ODS)     |

## Required Columns Validation

Validate that input files contain expected columns before processing. This catches malformed files early, avoiding wasted cycles parsing invalid data.

```php
// Throws RuntimeException if 'email' or 'price' columns are missing
$rows = Baresheet::read('products.csv', new Options(
    assoc: true,
    requiredColumns: ['sku', 'price', 'qty']
));

foreach ($rows as $row) {
    // All required columns are guaranteed to exist
    processProduct($row);
}
```

The validation occurs immediately after reading the header row and throws a descriptive exception listing the missing columns:

```
RuntimeException: Missing required columns: price, qty
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
> **The CSV "Practical Ceiling"**: While CSV reading cannot skip bytes (as `fgetcsv` must tokenize every field to track quotes/delimiters), Baresheet uses a direct numeric indexing map. This avoids creating a full associative array for the entire row before subsetting, effectively reaching the maximum performance possible for column selection in PHP.

### Error Handling

Missing columns throw immediately:

```
RuntimeException: Missing required columns: missing_column
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

Baresheet is explicitly engineered to minimize server resource footprint.

The XLSX and ODS readers use an optimized `XMLReader` approach that opens `zip://` streams directly. This avoids any temporary file extraction, cutting I/O overhead by 50% compared to standard zip extraction methods.

Here are the results extracting/writing 50,000 rows (4 columns) locally against other common industry standard libraries:

### Reading (Parsing) 50,000 Rows

#### Reading CSV

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (CSV) | 0.0057       | 0.63             |
| League (CSV)    | 0.0089       | 0.63             |
| OpenSpout (CSV) | 0.0201       | 0.63             |

#### Reading XLSX

| Library           | Avg Time (s) | Peak Memory (MB) |
|-------------------|--------------|------------------|
| Baresheet (XLSX)  | 0.0391       | 0.63             |
| SimpleXLSX (XLSX) | 0.0816       | 5.78             |
| OpenSpout (XLSX)  | 0.2114       | 0.63             |

#### Reading ODS

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (ODS) | 0.0484       | 0.63             |
| OpenSpout (ODS) | 0.1592       | 0.63             |

### Writing 50,000 Rows

#### Writing CSV

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (CSV) | 0.0956       | 0.50             |
| League (CSV)    | 0.1288       | 0.55             |
| OpenSpout (CSV) | 0.2770       | 0.47             |

#### Writing XLSX

| Library                           | Avg Time (s) | Peak Memory (MB) |
|-----------------------------------|--------------|------------------|
| Baresheet (XLSX)                  | 0.4504       | 0.79             |
| Baresheet (XLSX - Auto Width)     | 0.4429       | 0.79             |
| SimpleXLSXGen (XLSX)              | 0.6612       | 109.77           |
| Baresheet (XLSX - Shared Strings) | 0.8780       | 34.26            |
| OpenSpout (XLSX)                  | 0.9011       | 1.01             |
| Baresheet (XLSX - Full)           | 0.9351       | 34.26            |

> Note: By default, Baresheet uses the fastest mode (shared strings and auto column width disabled). You can re-enable them via Options.

#### Writing ODS

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (ODS) | 0.7693       | 0.93             |
| OpenSpout (ODS) | 1.2474       | 0.88             |

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
