# Headers and Schemas

Baresheet builds a schema from the spreadsheet's header block and lets you validate, select, rename, and normalize columns around it. Everything in this document applies to all reader formats (CSV, XLSX, ODS) and, where noted, to writers too.

## Required Columns

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

### Performance

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

## Injected Headers

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

## Hierarchical Headers

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

## Aliases

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

## Header Discovery

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

## Header Normalization

Normalize source headers once, before schema validation and before `requiredColumns`, `columns`, and `aliases` are applied:

```php
$rows = Baresheet::read('export.csv', new Options(
    assoc: true,
    headerNormalizer: fn(string $header): string => strtolower(trim($header)),
    requiredColumns: ['first name', 'email'],
    columns: ['email', 'first name'],
));
```

The callback receives each non-empty header cell and must return the normalized string. It applies to headers read from the file (including `headerOffset: 'auto'` detection) and to injected `headers`. It does **not** apply to `requiredColumns`, `columns`, or `aliases` — those must already be written in the normalized form.

```php
// Source: " First Name " → normalized to "first_name"
$rows = Baresheet::read('export.csv', new Options(
    assoc: true,
    headerOffset: 'auto',
    requiredColumns: ['first_name'],
    headerNormalizer: fn(string $header): string => strtolower(str_replace(' ', '_', trim($header))),
));
```

Two source headers that normalize to the same value (e.g. `"Name"` and `"name"` under `strtolower`) are rejected with an `InvalidDocumentException`, since the resulting schema would contain a duplicate path.

## Strict Mode

When `strict` is enabled, every data row must match the schema's expected column count. During header collection (with `headerRows > 1`), rows may legitimately differ in width — strict validation is deferred until the header block is resolved.

```php
// Read — throws InvalidRowException on mismatched data rows
$reader = new CsvReader(new Options(assoc: true, strict: true, headers: ['a', 'b', 'c']));

// Write — throws WriteException before flattening, catching short/long rows early
$writer = new CsvWriter(new Options(strict: true, headers: ['a', 'b', 'c']));
```
