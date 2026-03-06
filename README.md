# Baresheet

Fast, zero-dependency CSV, XLSX, and ODS reader/writer for PHP.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Requirements

- PHP 8.1+
- `ext-zip` (for XLSX and ODS)
- `ext-simplexml` (for XLSX and ODS)

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

// Write to stream (PSR-7 Responses)
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
- **RFC 4180 compliant** — handles enclosures and escapes according to standard behavior
- **Stream reading** — `readStream()` for reading from any PHP resource

### XLSX

- **Blazing fast reading** — optimized `XMLReader` with direct `zip://` streaming (2x faster than SimpleXLSX)
- **Data offset** & **Empty line skipping** — safely skip arbitrary leading rows or completely empty lines
- **Extreme memory efficiency** — unified 0.63MB footprint regardless of file size
- **Shared string table** — opt-in de-duplication for smaller files (default: `false` for speed)
- **Auto column widths** — opt-in automatic column sizing (default: `false` for speed)
- **DateTime support** — pass `DateTimeInterface` objects directly, seamlessly handles 1900/1904 calendar systems
- **Freeze Pane & Autofilter** — simple options for improved sheet usability
- **Document properties** — set creator, title, subject, keywords, etc. via `meta`

### ODS

- **Streaming reader** — handles large files with minimal 0.63MB memory usage
- **Data offset** & **Empty line skipping** — safely skip arbitrary leading rows or completely empty lines
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

| Option           | Type              | Default  | Applies to                                                                             |
|------------------|-------------------|----------|----------------------------------------------------------------------------------------|
| `assoc`          | bool              | `false`  | Read (All)                                                                             |
| `strict`         | bool              | `false`  | Read (CSV, XLSX, ODS)                                                                  |
| `stream`         | bool              | `true`   | Output (Any). If `true`, uses streaming. If `false`, buffers to send `Content-Length`. |
| `limit`          | ?int              | `null`   | Read (All)                                                                             |
| `offset`         | int               | `0`      | Read (All)                                                                             |
| `skipEmptyLines` | bool              | `true`   | Read (All)                                                                             |
| `headers`        | string[]          | `[]`     | Write (All), Read (CSV)                                                                |
| `separator`      | string            | `"auto"` | Read (CSV)                                                                             |
| `enclosure`      | string            | `"`      | Read (CSV)                                                                             |
| `escape`         | string            | `""`     | Read (CSV)                                                                             |
| `eol`            | string            | `\n`     | Write (CSV)                                                                            |
| `inputEncoding`  | ?string           | `null`   | Read (CSV)                                                                             |
| `outputEncoding` | ?string           | `null`   | Read (CSV)                                                                             |
| `bom`            | bool\|string\|Bom | `true`   | Write (CSV)                                                                            |
| `escapeFormulas` | bool              | `false`  | Write (CSV)                                                                            |
| `meta`           | array/Meta        | `null`   | Write (XLSX, ODS)                                                                      |
| `autofilter`     | ?string           | `null`   | Write (XLSX)                                                                           |
| `freezePane`     | ?string           | `null`   | Write (XLSX)                                                                           |
| `sheet`          | string/int        | `null`   | Read/Write (XLSX, ODS)                                                                 |
| `boldHeaders`    | bool              | `false`  | Write (XLSX, ODS)                                                                      |
| `tempPath`       | ?string           | `null`   | Any (Temp files location)                                                              |
| `sharedStrings`  | bool              | `false`  | Write (XLSX)                                                                           |
| `autoWidth`      | bool              | `false`  | Write (XLSX)                                                                           |

> [!important]
> **Behavioral Change**: From version 2.x, `output()` defaults to **streaming** (`stream: true`). This is more efficient but means no `Content-Length` header is sent. Set `stream: false` if you need a progress bar for downloads.

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

## Performance

Baresheet is explicitly engineered to minimize server resource footprint.

The XLSX and ODS readers use an optimized `XMLReader` approach that opens `zip://` streams directly. This avoids any temporary file extraction, cutting I/O overhead by 50% compared to standard zip extraction methods.

Here are the results extracting/writing 50,000 rows (4 columns) locally against other common industry standard libraries:

### Reading (Parsing) 50,000 Rows

#### Reading CSV

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (CSV) | 0.0053       | 0.63             |
| League (CSV)    | 0.0096       | 0.63             |
| OpenSpout (CSV) | 0.0206       | 0.63             |

#### Reading XLSX

| Library           | Avg Time (s) | Peak Memory (MB) |
|-------------------|--------------|------------------|
| Baresheet (XLSX)  | 0.0410       | 0.63             |
| SimpleXLSX (XLSX) | 0.0840       | 5.78             |
| OpenSpout (XLSX)  | 0.1971       | 0.63             |

#### Reading ODS

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (ODS) | 0.0600       | 0.63             |
| OpenSpout (ODS) | 0.1709       | 0.63             |

### Writing 50,000 Rows

#### Writing CSV

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (CSV) | 0.0995       | 0.48             |
| League (CSV)    | 0.1390       | 0.55             |
| OpenSpout (CSV) | 0.2824       | 0.47             |

#### Writing XLSX

| Library                           | Avg Time (s) | Peak Memory (MB) |
|-----------------------------------|--------------|------------------|
| Baresheet (XLSX)                  | 0.4739       | 0.76             |
| SimpleXLSXGen (XLSX)              | 0.6714       | 109.77           |
| OpenSpout (XLSX)                  | 0.9841       | 1.01             |
| Baresheet (XLSX - Shared Strings) | 0.9969       | 34.23            |

> Note: By default, Baresheet uses the fastest mode (shared strings and auto column width disabled). You can re-enable them via Options.

#### Writing ODS

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| Baresheet (ODS) | 0.9208       | 0.85             |
| OpenSpout (ODS) | 1.3569       | 0.88             |

## Security Considerations

### CSV Formula Injection

When writing CSV files, any cell beginning with `=`, `+`, `-`, or `@` could be interpreted as a formula if the file is opened in spreadsheet software like Microsoft Excel. A maliciously crafted input could lead to execution of arbitrary functions or system commands on the user's local machine.

By default, Baresheet prioritizes **data round-trip integrity**. Attempting to automatically prefix formulas with a single quote (`'`) to disable formula execution corrupts otherwise valid user inputs.

If you are exporting data to be consumed by clients opening the file in Excel, you **must opt-in** to the protection logic:

```php
$writer = new CsvWriter();
$writer->escapeFormulas = true; // Protects against formula injection by prefixing a single-quote
```

## License

MIT
