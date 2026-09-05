# Baresheet

Fast, lightweight CSV, XLSX, and ODS reader/writer for PHP with no runtime Composer dependencies.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE.md)

## Requirements

- PHP 8.1.2+
- ext-mbstring (required for all formats)

### Format-specific (XLSX/ODS)

- ext-zip
- ext-zlib
- ext-xmlreader, ext-simplexml, ext-libxml (standard XML extensions, usually bundled together)

### Optional

- ext-iconv (required only for CSV BOM transcoding)

## Installation

```bash
composer require lekoala/baresheet
```

## Quick Start

```php
use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Options;

// Read — format is auto-detected from the extension
$rows = Baresheet::read('data.xlsx', new Options(assoc: true));
foreach ($rows as $row) {
    echo $row['email'];
}

// Write — format from extension
Baresheet::write($data, 'output.xlsx');
```

That's it. The `Baresheet` facade always creates a fresh reader/writer, applies the options, and reads/writes once.

## Why Baresheet?

|                              | CSV | XLSX | ODS |
|------------------------------|-----|------|-----|
| Streaming read/write         | ✓   | ✓    | ✓   |
| Sheet selection              | —   | ✓    | ✓   |
| Native values                | —   | ✓    | ✓   |
| Column selection             | ✓   | ✓    | ✓   |
| Hierarchical headers         | ✓   | ✓    | ✓   |
| Auto width / freeze / filter | —   | ✓    | —   |

- **Streaming by default** — reads and writes (including browser `output()`) are streamed, so PHP memory stays flat regardless of file size.
- **Low memory** — ~0.15 MB for CSV, ~0.7 MB peak reading or ~1.3 MB writing 50,000 XLSX rows (see [Performance](#performance)).
- **No runtime Composer dependencies** — only PHP core extensions; XLSX/ODS packaging uses an internal ZIP writer.
- **Pragmatic headers** — required columns, selection, aliases, injected and hierarchical headers, header discovery, normalization, and strict mode ([docs/headers.md](docs/headers.md)).
- **Native values** — in XLSX/ODS, numbers, booleans, and dates come back as real PHP types, not strings; CSV is textual by nature ([Native values](#native-values)).

## Core API

### Baresheet facade

Format is detected from the extension (or from the content when a string is passed):

```php
$rows = Baresheet::read('data.csv');                            // Generator of rows
$rows = Baresheet::read('data.xlsx', new Options(assoc: true));
$rows = Baresheet::readString($contents, 'csv');                // from string content
Baresheet::write($data, 'output.ods');                          // to file
$string = Baresheet::writeString($data, 'csv');                 // to string
$stream = Baresheet::writeStream($data, 'xlsx');                // to resource
Baresheet::output($data, 'report.xlsx');                        // to browser download
```

### Direct readers/writers

Concrete classes allow setting properties directly or passing an `Options` object to the constructor:

```php
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\XlsxWriter;

// CSV — manual pattern
$reader = new CsvReader();
$reader->assoc = true;
$rows = $reader->readFile('data.csv');

// XLSX — Options pattern
$writer = new XlsxWriter(new Options(
    meta: ['creator' => 'My App'],
));
$writer->writeFile($data, 'report.xlsx');
```

### Options

Readers and writers are configured objects: you set their options once, then read/write as many times as you like with that same configuration. Use named arguments when constructing `Options` — the parameter list is large and its order is not part of the API contract.

```php
$opts = new Options(
    assoc: true,
    separator: 'auto',
    meta: ['creator' => 'My App'],
);
$opts->applyTo($reader); // full IDE autocomplete, reconfigures an existing instance
```

`readFile()`, `readString()`, `writeFile()`, etc. take no `Options` argument — they read/write using whatever configuration the reader/writer instance currently holds. This avoids ambiguity about whether a per-call option leaks into subsequent calls: the instance's configuration *is* its state. The `Baresheet` facade keeps the convenient one-shot form, since it always creates a fresh reader/writer internally, applies the options to it, then reads/writes once.

## Options

| Option                | Type                                       | Default     | Applies to                |
|-----------------------|--------------------------------------------|-------------|---------------------------|
| `assoc`               | bool                                       | `false`     | Read (All)                |
| `strict`              | bool                                       | `false`     | Read (All), Write (CSV)   |
| `stream`              | bool                                       | `true`      | Output (Any)              |
| `skipEmptyLines`      | bool                                       | `true`      | Read (All)                |
| `offset`              | int                                        | `0`         | Read (All)                |
| `limit`               | ?int                                       | `null`      | Read (All)                |
| `tempPath`            | ?string                                    | `null`      | Any (Temp files location) |
| `headers`             | string[]\|array<int, string[]>             | `[]`        | Read (All), Write (All)   |
| `headerRows`          | int                                        | `1`         | Read (All), Write (All)   |
| `headerOffset`        | int\|string\|null                          | `null`      | Read (All)                |
| `headerNormalizer`    | null\|callable(string): string             | `null`      | Read (All)                |
| `requiredColumns`     | string[]\|array<string\|int,string\|array> | `[]`        | Read (All)                |
| `columns`             | string[]\|array<string\|int,string\|array> | `[]`        | Read (All)                |
| `aliases`             | array<string\|int,string\|array>           | `[]`        | Read (All)                |
| `stringifyValues`     | bool                                       | `true`      | Read (XLSX, ODS)          |
| `inferNumericStrings` | bool                                       | `true`      | Write (XLSX, ODS)         |
| `separator`           | string                                     | `"auto"`    | Read (CSV)                |
| `enclosure`           | string                                     | `"`         | Read (CSV)                |
| `escape`              | string                                     | `""`        | Read (CSV)                |
| `eol`                 | string                                     | `\r\n`      | Write (CSV)               |
| `inputEncoding`       | ?string                                    | `null`      | Read (CSV)                |
| `outputEncoding`      | ?string                                    | `null`      | Read/Write (CSV)          |
| `skipInputBOM`        | bool                                       | `true`      | Read (CSV)                |
| `transcodeBomInput`   | bool                                       | `true`      | Read (CSV)                |
| `bom`                 | bool\|string\|Bom                          | `true`      | Write (CSV)               |
| `escapeFormulas`      | bool/callable                              | `false`     | Write (CSV)               |
| `meta`                | array/Meta                                 | `null`      | Write (XLSX, ODS)         |
| `autofilter`          | ?string                                    | `null`      | Write (XLSX)              |
| `freezePane`          | ?string                                    | `null`      | Write (XLSX)              |
| `sheetProtection`     | bool\|string                               | `false`     | Write (XLSX)              |
| `sheet`               | string/int                                 | `null`      | Read/Write (XLSX, ODS)    |
| `boldHeaders`         | bool                                       | `false`     | Write (XLSX, ODS)         |
| `sharedStrings`       | bool                                       | `false`     | Write (XLSX)              |
| `autoWidth`           | bool                                       | `false`     | Write (XLSX)              |
| `maxWorksheetSize`    | ?int                                       | `500000000` | Read (XLSX, ODS)          |

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
use LeKoala\Baresheet\Options;
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

## Native Values

Baresheet preserves the fundamental spreadsheet value kinds where PHP has a natural representation. In native mode (`stringifyValues: false`), the readers return:

| Spreadsheet   | PHP                                    |
|---------------|----------------------------------------|
| text          | `string`                               |
| number        | `int\|float`                           |
| boolean       | `bool`                                 |
| date/datetime | `DateTimeImmutable`                    |
| time          | canonical string (`HH:MM:SS[.ffffff]`) |
| duration      | canonical string (`H:MM:SS[.ffffff]`)  |

The writers map PHP values to spreadsheet cells:

| PHP                 | Spreadsheet                |
|---------------------|----------------------------|
| `string`            | text                       |
| `int\|float`        | number                     |
| `bool`              | boolean                    |
| `DateTimeInterface` | date/datetime              |
| `null`              | blank                      |
| `TimeValue`         | time (explicit marker)     |
| `DurationValue`     | duration (explicit marker) |
| `Time\Duration`     | duration (when available)  |

```php
use LeKoala\Baresheet\Value\TimeValue;
use LeKoala\Baresheet\Value\DurationValue;

$writer->writeFile([
    [
        'opening_time' => new TimeValue(9, 30),
        'elapsed' => new DurationValue(hours: 36, minutes: 30, seconds: 15),
    ],
], 'report.xlsx');
```

`TimeValue` and `DurationValue` are optional writer markers: a caller who never uses them never sees them. The readers never inject Baresheet objects into ordinary rows — `DateTimeImmutable` is standard PHP. See [docs/value-types.md](docs/value-types.md) for timezone semantics, precision, and 32-bit notes.

### CSV specifics

CSV has no native value types. Baresheet therefore uses conventional textual representations: booleans as `1`/`0` and `null` as an empty cell. Type distinctions that CSV cannot represent, such as `false` vs numeric `0` or `null` vs an empty string, are intentionally not preserved.

To make export output deterministic across PHP configurations, the CSV writer serializes scalars explicitly: `bool` as `1`/`0`, finite floats with up to 17 significant digits (enough to round-trip a PHP float exactly, independent of the `precision` ini setting and of `LC_NUMERIC`), and `null` as an empty cell. This keeps `false` (`0`) distinct from `null` (empty). Non-finite floats (`INF`/`NAN`) are rejected with a `WriteException`, consistently with the XLSX/ODS writers.

With `escapeFormulas`, formula protection applies to strings and `Stringable` objects; numeric cells are never mistaken for formulas, so a negative float keeps its leading `-`.

When `outputEncoding` targets a non-UTF-8 encoding, the whole CSV stream — including separators and end-of-line bytes — is transcoded, also when a raw string BOM is provided (`ext-iconv` is required and bundled with PHP by default). Cells that are not valid UTF-8 or cannot be represented in the target encoding raise a `WriteException` instead of being silently substituted.

## Advanced Usage

- [Headers and column mapping](docs/headers.md) — required columns, column selection, injected and hierarchical headers, aliases, header discovery, normalization, strict mode
- [Streaming](docs/streaming.md) — output modes and what each costs when generation fails, ZIP64 and non-seekable output, PSR-7 / Symfony / Laravel responses
- [Value types](docs/value-types.md) — timezone-free civil dates, microsecond precision, 32-bit PHP
- [Security](docs/security.md) — CSV formula injection, XLSX sheet protection

Also in the package:

- [`Transform`](docs/transform.md) — generator-based pipelines for trimming, casting, filtering, and chunking without loading data into memory
- `Spread::getSheetNames()` — inspect the sheets of a workbook before choosing which to import

## Performance

> **Indicative benchmarks** — These numbers are intended to catch large performance regressions and highlight architectural differences. Absolute results vary by PHP version, hardware, filesystem and workload. Run `php bin/bench-read.php` / `bin/bench-write.php` / `bin/bench-write-memory.php` / `bin/bench-xlsx-stream.php` / `bin/bench-ods-stream.php` locally for results relevant to your environment.
>
> Environment: PHP 8.3.6, 64-bit, 50,000 rows × 4 columns, median of 5 runs. Libraries are compared end-to-end through their public APIs; this is not a compressor-only comparison.

### Reading 50,000 Rows

| Library    | CSV  | XLSX | ODS  | Peak PHP Memory                                       |
|------------|------|------|------|-------------------------------------------------------|
| Baresheet  | 1.0× | 1.0× | 1.0× | 0.15 MB (CSV) · 0.64 MB (XLSX) · 0.48 MB (ODS)        |
| League     | 1.6× | —    | —    | 0.31 MB                                               |
| SimpleXLSX | —    | 2.0× | —    | 34.1 MB                                               |
| OpenSpout  | 3.0× | 6.0× | 3.4× | 0.15–0.55 MB                                          |

### Writing 50,000 Rows

| Library       | CSV  | XLSX | ODS  | Peak PHP Memory                                        |
|---------------|------|------|------|--------------------------------------------------------|
| Baresheet     | 1.0× | 1.0× | 1.0× | 0.14 MB (CSV) · 1.31 MB (XLSX) · 1.48 MB (ODS)         |
| League        | 1.2× | —    | —    | 0.25 MB                                                |
| SimpleXLSXGen | —    | 2.7× | —    | 109.85 MB                                              |
| OpenSpout     | 2.4× | 3.5× | 5.2× | 0.12–0.70 MB                                           |

Memory is measured in an isolated subprocess via `memory_get_peak_usage()`, covering PHP-managed allocations only (not native allocations inside zlib or libzip). Baresheet's stream-based `XMLReader` never loads the entire worksheet document into PHP memory. See [docs/streaming.md](docs/streaming.md) for write-memory details.

## Security

CSV formula escaping is opt-in via `escapeFormulas`. See [docs/security.md](docs/security.md).

## License

MIT
