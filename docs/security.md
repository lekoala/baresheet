# Security Considerations

## CSV Formula Injection

When writing CSV files, any cell beginning with `=`, `+`, `-`, or `@` could be interpreted as a formula if the file is opened in spreadsheet software like Microsoft Excel. A maliciously crafted input could lead to execution of arbitrary functions or system commands on the user's local machine.

By default, Baresheet prioritizes **data round-trip integrity**. Attempting to automatically prefix formulas with a single quote (`'`) to disable formula execution corrupts otherwise valid user inputs.

If you are exporting data to be consumed by clients opening the file in Excel, you **must opt-in** to the protection logic:

```php
$writer = new CsvWriter();
$writer->escapeFormulas = true; // Protects against formula injection by prefixing a single-quote
```

Escaping applies to strings and to `Stringable` objects (converted to their string form first). Numeric cells are never escaped, so a negative float written as a number keeps its leading `-` instead of being mistaken for a formula.

### Selective Formula Escaping

For advanced use cases, `escapeFormulas` also accepts a **callable** that receives the cell value and column index, allowing you to selectively escape only specific columns:

```php
$writer = new CsvWriter();
$writer->escapeFormulas = function (string $cell, int $colIndex): string {
    // Skip phone columns (column 1) to preserve + prefixes
    if ($colIndex === 1) {
        return $cell;
    }
    // Apply default escaping for everything else
    $chars = "=+-@\t\r";
    if ($cell !== '' && str_contains($chars, $cell[0])) {
        return "'" . $cell;
    }
    return $cell;
};
$writer->writeFile($data, 'export.csv');
```

**Important:** Heuristic detection of "malicious" formulas is fundamentally unreliable. Attackers can use `CHAR()` functions to build strings character-by-character, and new attack vectors emerge constantly. The library takes a conservative approach: blanket escaping by default when enabled, or user-controlled selective escaping via callback. For untrusted textual data, **XLSX** is preferable here because Baresheet writes PHP strings as explicit text cells rather than formulas.

## XLSX Sheet Protection

Lock an exported sheet to discourage accidental edits:

```php
// The user can remove the protection without a password.
Baresheet::write($data, 'readonly.xlsx', new Options(sheetProtection: true));

// The user must enter the password to remove the protection in Excel.
Baresheet::write($data, 'protected.xlsx', new Options(sheetProtection: 'change-me'));
```

Sheet protection is not encryption and does not secure the workbook's contents. It is intentionally limited to Excel's legacy sheet-protection verifier: adding a stronger password hash would give a false impression of security because the protection can still be removed from the XLSX archive. It is intended to prevent ordinary edits, not to protect sensitive data.

To prevent the workbook from being read, encrypt the completed file with a separate encryption process before delivering or storing it.
