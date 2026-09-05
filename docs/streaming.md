# Streaming

Baresheet streams CSV, XLSX, and ODS `output()` by default, avoiding temporary files on disk for large exports.

```php
use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Options;

// Stream as download (sends HTTP headers)
Baresheet::output($data, 'report.xlsx');

// Write to a PHP resource (for PSR-7 or Laravel Responses)
$stream = Baresheet::writeStream($data, 'xlsx');
```

## Output Modes

`stream` chooses between two I/O strategies. For XLSX and ODS it is not a memory decision — both modes hold no more than a small buffer — but a decision about *when the HTTP response commits*.

**`stream = true` (the default)** sends bytes to `php://output` as they are produced.

- No complete temporary archive; nothing on disk grows with the export.
- The download starts immediately, whatever the export size.
- No `Content-Length`, so no progress bar or estimated time.
- Headers are already sent when generation begins, so a failure part-way through cannot become a clean HTTP error. The client may receive a truncated or unusable file instead.

**`stream = false`** builds the document first, then sends it.

- Generation finishes before any header is sent, so an error becomes a real HTTP error response instead of a corrupt download.
- `Content-Length` is known and sent, which also lets the client detect a truncated transfer.
- Costs temporary disk proportional to the output, for the duration of the request.

For user-facing XLSX or ODS downloads where reporting generation errors cleanly matters more than immediate delivery, `stream: false` can be preferable when temporary disk space is available: the workbook is fully built before the response starts, so nothing half-written reaches the user. Keep streaming for large exports, low-latency downloads, and environments where temporary disk matters — a hundred concurrent 100 MB exports mean up to 10 GB of temporary space under buffering.

```php
$writer = new XlsxWriter();
$writer->stream = false;
$writer->output($data, 'report.xlsx');

// or via Options
Baresheet::output($data, 'report.xlsx', new Options(stream: false));
```

### CSV buffers in memory, not on disk

The two modes are not equivalent across formats. XLSX and ODS build into a temporary file, so buffering trades disk. CSV has no archive to build, so it materialises the whole document as a PHP string instead:

| Rows    | CSV `stream = true` | CSV `stream = false` |
|---------|---------------------|----------------------|
| 10,000  | 1.8 MB              | 2.4 MB               |
| 100,000 | 1.8 MB              | 7.3 MB               |
| 500,000 | 1.8 MB              | 31.3 MB              |

Total `memory_get_peak_usage()` of a CLI process with generator input, so each figure includes a ~1.8 MB baseline for the runtime and autoloader. On the same measurement XLSX and ODS stay flat in both modes, around 2.4 MB and 2.8 MB at any row count.

So `stream: false` is a disk trade for XLSX and ODS, and a memory trade for CSV. Prefer setting it on the writer rather than through a shared `Options` instance when one request produces several formats.

## Write Memory and Modes

With generator input and default options, worksheet XML (XLSX) and `content.xml` (ODS) are compressed in a single pass and incremental PHP-managed memory remains approximately flat as row count grows. Pre-built arrays remain owned by the caller and are intentionally excluded from this streaming guarantee.

- **XLSX**: the ~1.3 MB PHP peak reflects this single-pass path. Enabling `sharedStrings` keeps the de-duplication table in memory. By default, Baresheet uses the fastest mode (shared strings and auto column width disabled); enabling either trades speed for file size or presentation. Seekable outputs use ZIP64 only when required by final sizes or offsets. Non-seekable outputs stay on classic ZIP and refuse to pass 4 GiB.
- **ODS**: `content.xml` follows the same generator-based direct compression path. The ~1.5 MB PHP peak reflects its 1,000-row XML buffer; native zlib allocations are not included.

## Seekable and Non-Seekable Outputs

Both XLSX and ODS use Baresheet's built-in ZIP writer (`DirectZipWriter`) for seekable destinations and true non-seekable streaming to `php://output`; no ZIP streaming dependency is needed.

For ODS, the required first `mimetype` entry is written from known metadata as STORE, without an extra field or data descriptor, while subsequent entries use the normal streaming strategy.

Non-seekable XLSX output uses classic local headers with a trailing 32-bit data descriptor: the entry sizes are not known when its header is written, so they follow the data instead. The interoperability suite exercises the captured `php://output` bytes directly:

| Reader     | Non-seekable XLSX output                  |
|------------|-------------------------------------------|
| ZipArchive | Supported                                 |
| Baresheet  | Supported                                 |
| OpenSpout  | Supported                                 |
| SimpleXLSX | Supported                                 |
| xlswriter  | Supported when the extension is installed |

> **ZIP64 and the 4 GiB ceiling.** Excel opens no archive carrying ZIP64, in any form, so spreadsheets meant for it cap at 4 GiB. Past that, streaming throws a `WriteException` (the response has already begun, so the download arrives truncated) while buffering emits a ZIP64 archive that most PHP readers accept and Excel does not. Split the export instead.

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
