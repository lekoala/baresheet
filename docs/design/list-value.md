# Design: `ListValue`

> **Status: not implemented.** This note records decisions taken ahead of time, so the contract does not have to be re-argued once in-cell lists become common in Excel. Nothing here describes current behavior.

## Goal

Support a one-dimensional list stored as the value of a single cell (`tags = [customer, active]`), with a portable text fallback for formats that have no such concept.

## Read / write contract

The reader describes the data; the writer asks for intent when a PHP type is ambiguous.

| Direction                                 | Representation                                  |
|-------------------------------------------|-------------------------------------------------|
| Read, native (`stringifyValues: false`)   | PHP `list` (`['customer', 'active']`)           |
| Read, stringify (`stringifyValues: true`) | canonical JSON string (`["customer","active"]`) |
| Write                                     | `new ListValue(['customer', 'active'])`         |

- Readers never inject Baresheet objects into rows, as stated in [value-types.md](../value-types.md). `ListValue` is a writer-only marker, like `TimeValue` and `DurationValue`.
- A raw PHP array in a cell keeps throwing a `WriteException`: arrays already carry structural meaning (rows, hierarchical headers). Re-writing a list read in native mode therefore requires wrapping it in `ListValue`; the error message should say so.
- The CSV reader never infers a list from JSON-looking text. Converting a column back to `ListValue` is the application's decision.

## `ListValue`

- A `final` class with a `public readonly array $values`, not `Stringable`: every writer handles it explicitly, so a writer that forgets it fails instead of silently writing text.
- The constructor rejects, with `InvalidArgumentException`:
  - a non-list array (`array_is_list()` false);
  - any item that is not `string|int|float|bool|null`;
  - non-finite floats (NaN, ±INF);
  - strings that are not valid UTF-8, so encoding can never fail later.

## Canonical JSON fallback

Used by CSV, ODS, and by XLSX under `forceText` or a text column format.

| Item     | Encoding                                                         |
|----------|------------------------------------------------------------------|
| `string` | JSON string (`JSON_UNESCAPED_UNICODE \| JSON_UNESCAPED_SLASHES`) |
| `int`    | decimal integer                                                  |
| `float`  | `Spread::serializeFloat()` (`%.17G`)                             |
| `bool`   | `true` / `false`                                                 |
| `null`   | `null`                                                           |

- `json_encode()` is never used for numbers: its output depends on `serialize_precision`, while Baresheet's float serialization is independent of ini settings and locale.
- `1.0` encodes as `1`, without `JSON_PRESERVE_ZERO_FRACTION`: the int/float distinction is not part of the spreadsheet round-trip contract.
- Floats use the same form as a numeric cell, so `0.1` encodes as `0.10000000000000001`. This is intentional.
- Items are joined with `,` and no whitespace: `["customer",1,true,null]`.

## Native XLSX (later)

Waiting for real Excel files containing a list. What is already known:

- The Geography sample (`tests/data/rich-geography.xlsx`) stores in-cell arrays as a rich value of structure `_array` (key `array:a`) that references `xl/richData/rdarray.xml`, e.g. `<a r="3"><v t="s">Néerlandais</v><v t="s">Allemand</v><v t="s">Français</v></a>`. A native list is expected to use the same mechanism; this needs to be confirmed.
- Reading should reuse the rich value resolver (`vm` → `metadata.xml` → `rdrichvalue.xml`).
- Writing needs infrastructure that does not exist yet:
  - relationships and `[Content_Types].xml` written after the worksheet, since a streamed generator only reveals list cells while rows are written;
  - rich value data buffered in a temp file, not in memory;
  - `metadata.xml` and the structures part generated at the end.
- Switching XLSX output from JSON text to a native list later would be a visible behavior change, so `ListValue` ships only once the native XLSX form is supported.
