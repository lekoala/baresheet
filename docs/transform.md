# Data Transformation

Baresheet preserves raw cell values by design. For cleaning, casting, or filtering, use the `Transform` class — generator-based pipelines that compose with readers and writers without loading data into memory.

All methods accept `iterable<array>` and yield arrays lazily, so you can chain them with readers, writers, or each other.

## Trimming and Replacing

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

All methods return generators, so they compose with the PHP 8.5+ pipe operator:

```php
$clean = $reader->readFile('data.csv')
    |> Transform::trim(...)
    |> Transform::nullAs(..., 'N/A')
    |> Transform::boolAs(..., 'Yes', 'No');
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

## Batching and Paging

```php
// Batch insert in chunks of 1000
foreach (Transform::chunk($rows, 1000) as $batch) {
    $db->bulkInsert($batch);
}

// Slice a page of results, without loading everything into memory
$page = Transform::slice($rows, offset: 100, limit: 20);
```
