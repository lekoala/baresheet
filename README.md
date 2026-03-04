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
Baresheet::write($data, 'output.xlsx', new Options(creator: 'My App'));
Baresheet::write($data, 'output.ods');

// Write to string
$csv = Baresheet::writeString($data, 'csv');
$xlsx = Baresheet::writeString($data, 'xlsx');
$ods = Baresheet::writeString($data, 'ods');

// Stream as download
Baresheet::output($data, 'report.xlsx');
```

## Direct Reader/Writer Usage

Concrete classes allow setting properties directly:

```php
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

// CSV
$reader = new CsvReader();
$reader->assoc = true;
$rows = $reader->readFile('data.csv');

$writer = new CsvWriter();
$writer->escapeFormulas = true;
$writer->writeFile($data, 'safe-export.csv');

// XLSX
$reader = new XlsxReader();
$reader->sheet = 'Data';
$rows = $reader->readFile('report.xlsx');

$writer = new XlsxWriter();
$writer->creator = 'My App';
$writer->writeFile($data, 'report.xlsx');
```

## Features

### CSV

- **Auto delimiter detection** — analyzes a sample to pick the best separator (default: `auto`)
- **BOM handling** — reads/writes UTF-8 BOM transparently
- **Formula injection protection** — `escapeFormulas: true` (opt-in security flag, see Security section)
- **PHP 8.4 compliant** — default escape character is empty string (RFC 4180)
- **Stream reading** — `readStream()` for reading from any PHP resource

### XLSX

- **Shared string table** — de-duplicates strings for smaller file sizes
- **Auto column widths** — columns sized to content length
- **DateTime support** — pass `DateTimeInterface` objects directly
- **Date format detection** — built-in format IDs 0–70 including CJK locales
- **Document properties** — set creator, title, subject, keywords, etc.
- **Broader XML escaping** — strips control characters `\x00-\x1F` (except tab/LF/CR)
- **Freeze Pane & Autofilter** — simple options for sheet usability

### ODS

- **Zero-dependency** — uses ZipArchive + SimpleXML (same as XLSX)
- **DateTime support** — dates stored as ISO 8601
- **Document properties** — set creator and title
- **Sheet selection** — read specific sheets by name or index
- **Round-trip safe** — write and read back data accurately

## Options

There are two ways to pass options:

**1. Directly on instances:**

```php
$reader = new CsvReader();
$reader->assoc = true;
$reader->separator = ";";
```

**2. Options object** (works on any method, including the `Baresheet` facade). The constructor provides **full IDE autocomplete**:

```php
use LeKoala\Baresheet\Options;

$opts = new Options(
    assoc: true, 
    separator: 'auto',
    creator: 'My App'
);
$rows = $reader->readFile('data.csv', $opts);
```

| Option           | Type       | Default  | Applies to          |
|------------------|------------|----------|---------------------|
| `assoc`          | bool       | `false`  | Read (CSV, XLSX)    |
| `strict`         | bool       | `false`  | Read (CSV, XLSX)    |
| `stream`         | bool       | `false`  | Output (Any)        |
| `limit`          | ?int       | `null`   | Read (CSV, XLSX)    |
| `headers`        | string[]   | `[]`     | Write (CSV, XLSX)   |
| `separator`      | string     | `"auto"` | Read (CSV)          |
| `enclosure`      | string     | `"`      | Read (CSV)          |
| `escape`         | string     | `""`     | Read (CSV)          |
| `eol`            | string     | `\n`     | Write (CSV)         |
| `inputEncoding`  | ?string    | `null`   | Read (CSV)          |
| `outputEncoding` | ?string    | `null`   | Read (CSV)          |
| `bom`            | bool       | `true`   | Write (CSV)         |
| `escapeFormulas` | bool       | `false`  | Write (CSV)         |
| `meta`           | array      | `[]`     | Write (XLSX, ODS)   |
| `autofilter`     | ?string    | `null`   | Write (XLSX)        |
| `freezePane`     | ?string    | `null`   | Write (XLSX)        |
| `sheet`          | string/int | `null`   | Read/Wt (XLSX, ODS) |
| `boldHeaders`    | bool       | `false`  | Write (XLSX, ODS)   |
| `tempPath`       | ?string    | `null`   | Write (XLSX, ODS)   |

## Streaming Output

For large XLSX or ODS files, streaming avoids writing a temporary file to disk.

However, keep in mind that **streaming changes how data is sent to the browser**. Because the total file size is unknown before the transfer starts, the server cannot send a `Content-Length` header. This means the browser download will not display a progress bar or an estimated time of completion.

To enable streaming for XLSX or ODS, install the optional dependency:

```bash
composer require maennchen/zipstream-php
```

Then use `stream: true` with `output()`:

```php
$writer = new XlsxWriter();
$writer->stream = true;
$writer->output($data, 'report.xlsx');

// or via Options
Baresheet::output($data, 'report.xlsx', new Options(stream: true));
```

> Note: CSV `output()` natively writes directly to `php://output` as rows are processed.

## Performance

Baresheet is explicitly engineered to minimize server resource footprint during high-volume operations by operating strictly via streaming paradigms.

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
| Baresheet (CSV) | 0.1082       | 0.46             |
| League (CSV)    | 0.1388       | 0.55             |
| OpenSpout (CSV) | 0.2817       | 0.47             |

#### Writing XLSX

| Library                           | Avg Time (s) | Peak Memory (MB) |
|-----------------------------------|--------------|------------------|
| Baresheet (XLSX)                  | 0.6015       | 1.39             |
| SimpleXLSXGen (XLSX)              | 0.6915       | 109.77           |
| OpenSpout (XLSX)                  | 0.9215       | 1.01             |
| Baresheet (XLSX - Shared Strings) | 1.0462       | 34.23            |

> Note: By default, Baresheet uses the fastest mode (shared strings and auto column width disabled). You can re-enable them via Options.

#### Writing ODS

| Library         | Avg Time (s) | Peak Memory (MB) |
|-----------------|--------------|------------------|
| OpenSpout (ODS) | 1.2388       | 0.88             |
| Baresheet (ODS) | 1.4021       | 0.78             |

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
