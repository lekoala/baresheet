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

## Content-Length and Buffering

Because the total file size is unknown before the transfer starts, streaming means the server cannot send a `Content-Length` header. The browser download will therefore not display a progress bar or an estimated time of completion.

To bypass streaming and force buffering, use `stream: false` with `output()`. Baresheet will buffer the file (either in memory for CSV, or via a temporary zip file for XLSX/ODS) to precisely calculate and send the `Content-Length` header along with it.

```php
$writer = new XlsxWriter();
$writer->stream = false;
$writer->output($data, 'report.xlsx');

// or via Options
Baresheet::output($data, 'report.xlsx', new Options(stream: false));
```

## Write Memory and Modes

With generator input and default options, worksheet XML (XLSX) and `content.xml` (ODS) are compressed in a single pass and incremental PHP-managed memory remains approximately flat as row count grows. Pre-built arrays remain owned by the caller and are intentionally excluded from this streaming guarantee.

- **XLSX**: the ~1.09 MB PHP peak reflects this single-pass path. Enabling `sharedStrings` keeps the de-duplication table in memory. By default, Baresheet uses the fastest mode (shared strings and auto column width disabled); enabling either trades speed for file size or presentation. Seekable outputs use ZIP64 only when required by final sizes or offsets. Non-seekable outputs use ZIP64-capable local headers proactively because entry sizes are not known in advance (64-bit PHP is required for archives beyond 4 GiB).
- **ODS**: `content.xml` follows the same generator-based direct compression path. The ~1.39 MB PHP peak reflects its 1,000-row XML buffer; native zlib allocations are not included.

## Seekable and Non-Seekable Outputs

Both XLSX and ODS use Baresheet's built-in ZIP writer (`DirectZipWriter`) for seekable destinations and true non-seekable streaming to `php://output`; no ZIP streaming dependency is needed.

For ODS, the required first `mimetype` entry is written from known metadata as STORE, without an extra field or data descriptor, while subsequent entries use the normal streaming strategy.

Non-seekable XLSX output uses standard ZIP64-capable local headers and data descriptors. The interoperability suite exercises the captured `php://output` bytes directly:

| Reader     | Non-seekable XLSX output                                                       |
|------------|--------------------------------------------------------------------------------|
| ZipArchive | Supported                                                                      |
| Baresheet  | Supported                                                                      |
| OpenSpout  | Supported                                                                      |
| xlswriter  | Supported when the extension is installed                                      |
| SimpleXLSX | Not supported; its ZIP parser does not currently accept this descriptor layout |

The SimpleXLSX limitation is specific to this non-seekable ZIP layout. Seekable XLSX files written by Baresheet use patched local headers and remain compatible with SimpleXLSX.

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
