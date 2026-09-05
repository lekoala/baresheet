# Value Types

Baresheet preserves the fundamental spreadsheet value kinds where PHP has a natural representation. In native mode (`stringifyValues: false`), the readers return typed values instead of legacy CSV-like strings.

## Reading

| Spreadsheet   | PHP                                    |
|---------------|----------------------------------------|
| text          | `string`                               |
| number        | `int\|float`                           |
| boolean       | `bool`                                 |
| date/datetime | `DateTimeImmutable`                    |
| time          | canonical string (`HH:MM:SS[.ffffff]`) |
| duration      | canonical string (`H:MM:SS[.ffffff]`)  |

`int\|float` is a convenient PHP representation of a spreadsheet Number; the distinction itself is not guaranteed to survive a round-trip (`12.0` reads back as `12`).

### Numeric precision

When writing numbers, Baresheet serializes floats with 17 significant digits so the `double` value round-trips exactly, regardless of PHP's `precision` ini setting or the active `LC_NUMERIC` locale.

Two downstream limits apply:

- **Excel stores at most 15 significant digits.** A float with more digits is written faithfully but Excel rounds it for storage and display. This is expected for `float` values, which are IEEE doubles anyway.
- **Large PHP integers above `2^53` are not exact in Excel.** Excel parses numeric cells as doubles, so a PHP `int` such as `9_900_000_000_000_000_123` loses the digits beyond the 15th — an exact round-trip is not guaranteed. Keep long identifiers (order numbers, IDs) as **strings** to preserve them.

## Writing

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

`TimeValue` and `DurationValue` are optional writer markers: a caller who never uses them never sees them. The readers never inject Baresheet objects into ordinary rows — `DateTimeImmutable` is standard PHP.

## Timezone-Free Civil Values

Spreadsheet dates are **timezone-free civil values**: an offset present in the source is used for validation only and is not part of the round-trip contract.

## Microsecond Precision

Temporal values are **stored at microsecond precision**. XLSX serials preserve the full microsecond value even though the default number formats display `hh:mm:ss` / `[h]:mm:ss`.

A `Time\Duration` carrying sub-microsecond precision is truncated toward zero (`999` nanoseconds → `0` microseconds). On PHP < 8.6, install `symfony/polyfill-time` if you want to write `Time\Duration` values.

## 32-bit PHP

Baresheet supports 32-bit PHP for normal temporal reading and writing. `TimeValue::fromMicroseconds()` and `toMicroseconds()` require 64-bit integers.

## Excel Number Formats

XLSX dates are identified from their cell number format. A numeric Excel serial stored with the `General` format cannot reliably be distinguished from an ordinary number, so it is read back as a raw value.
