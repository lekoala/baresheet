# Data Transformation

Baresheet preserves raw cell values by design. For cleaning, casting, or filtering, use the `Transform` class — generator-based pipelines that compose with readers and writers without loading data into memory.

All methods accept `iterable<array>` and yield arrays lazily, so you can chain them with readers, writers, or each other.

## Trimming and Replacing

Use ordinary method calls to chain transformations on PHP 8.1.2 and later.

```php
use LeKoala\Baresheet\Transform;

// Trim whitespace from all string values
$rows = Transform::trim($reader->readFile('data.csv'));

// Replace nulls with a fallback string
$rows = Transform::nullAs($rows, 'N/A');

// Render booleans as human-readable strings
$rows = Transform::boolAs($rows, 'Yes', 'No');
```

## Chaining with the Pipe Operator

### PHP 8.5+

The pipe operator passes the result of each stage to a callable. Use a first-class callable for `trim()` and parenthesized arrow functions to supply the replacement values. [PHP manual on the pipe operator](https://www.php.net/manual/en/language.operators.functional.php)

```php
$clean = $reader->readFile('data.csv')
    |> Transform::trim(...)
    |> (static fn(iterable $rows) => Transform::nullAs($rows, 'N/A'))
    |> (static fn(iterable $rows) => Transform::boolAs($rows, 'Yes', 'No'));
```

### PHP 8.6+

Partial function application lets you supply fixed arguments and use `?` for the input from the previous stage. [PHP manual on partial function application](https://www.php.net/manual/en/functions.partial-application.php)

```php
$clean = $reader->readFile('data.csv')
    |> Transform::trim(...)
    |> Transform::nullAs(?, 'N/A')
    |> Transform::boolAs(?, 'Yes', 'No');
```

With associative rows, you can normalize headers, keep rows that have an `email` key, cast numeric columns, and process the results in batches. The generator produces each batch as you iterate.

```php
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\Transform;

$reader = new CsvReader(new Options(assoc: true));
$batches = $reader->readFile('data.csv')
    |> Transform::trim(...)
    |> Transform::mapRows(?, array_change_key_case(?))
    |> Transform::filter(?, array_key_exists('email', ?))
    |> Transform::cast(?, [
        'qty' => 'int',
        'price' => 'float',
    ])
    |> Transform::chunk(?, 1000);
```

## Casting Types

Cast columns to specific types. `cast()` is permissive (invalid values become defaults); `castStrict()` throws an `InvalidRowException` with row and column context instead.

```php
$typed = Transform::cast($rows, [
    'qty' => 'int',
    'price' => 'float',
    'active' => 'bool',
    'created' => 'date',  // returns DateTimeImmutable
]);
```

Supported types are `int`, `float`, `bool`, `string`, and `date`. Prefix any type with `?` to allow null (`'?int'`, `'?date'`, ...). Dates are parsed as ISO 8601 strings and returned as `DateTimeImmutable`; an incoming `DateTimeInterface` value passes through as an immutable instance.

For strict casting that fails loudly:

```php
$typed = Transform::castStrict($rows, [
    'qty' => 'int',
    'created' => 'date',
]); // throws InvalidRowException on bad values
```

### Static Typing

The generators don't expose their row shape to static analysis. Annotate the variable so your IDE and PHPStan know the expected shape:

```php
/** @var Generator<array{qty: int, price: float, active: bool, created: ?DateTimeImmutable}> $typed */
$typed = Transform::cast($rows, [
    'qty' => 'int',
    'price' => 'float',
    'active' => 'bool',
    'created' => 'date',
]);
```

## Custom Transforms

```php
// Map each cell (callback receives value and column key)
$rows = Transform::map($rows, fn(mixed $cell, int|string $col) => match ($col) {
    'email' => strtolower($cell),
    default => $cell,
});

// Map each row (callback receives the row and its index)
$rows = Transform::mapRows($rows, fn(array $row, int $i) => [...$row, 'position' => $i]);

// Filter rows
$active = Transform::filter($rows, fn($row) => $row['active'] === 'Yes');
```

On PHP 8.6+, use `?` when your callback needs only the cell value or row. `map()` also passes the column key, and `mapRows()` and `filter()` also pass the row index. A partial callable with `?` and no `...` discards those extra arguments. With `trim(?)`, for example, the column key cannot reach `trim()` as its optional `$characters` argument.

```php
// Lowercase every cell when all cells contain strings
$rows = Transform::map($rows, strtolower(?));

// Lowercase column names
$rows = Transform::mapRows($rows, array_change_key_case(?));

// Keep rows that have an email key, including rows where the value is null
$rows = Transform::filter($rows, array_key_exists('email', ?));
```

## Batching and Paging

```php
// Batch insert in chunks of 1000
foreach (Transform::chunk($rows, 1000) as $batch) {
    $db->bulkInsert($batch);
}

// Slice a page of results, without loading everything into memory
$page = Transform::slice($rows, offset: 100, limit: 20);
```
