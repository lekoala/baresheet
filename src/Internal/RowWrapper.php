<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Internal;

use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\HeaderSchema;

/**
 * Shared row preparation for the XLSX/ODS writers.
 */
final class RowWrapper
{
    /**
     * Wrap data with header rows (flat or hierarchical) according to the schema.
     *
     * Without a schema, the first associative row defines the columns: its keys
     * become the header and every following associative row is aligned on them.
     *
     * @param iterable<array<int|string, mixed>> $data
     * @param string $sheetName Sheet name used in the unknown-column error.
     * @return iterable<array<int|string, mixed>>
     */
    public static function wrapRows(iterable $data, ?HeaderSchema $schema, string $sheetName): iterable
    {
        if ($schema !== null) {
            yield from $schema->headerRows();
            foreach ($data as $row) {
                yield $schema->flattenRow((array) $row);
            }
            return;
        }

        $firstSeen = false;
        $columnKeys = null;
        $columnKeysMap = null;
        foreach ($data as $row) {
            $isList = array_is_list($row);
            if (!$firstSeen) {
                $firstSeen = true;
                if (!$isList) {
                    $columnKeys = array_keys($row);
                    $columnKeysMap = array_flip($columnKeys);
                    yield $columnKeys;
                }
            }

            if ($isList || $columnKeys === null) {
                // Positional rows are written as-is. Once the first row is a list no
                // header is invented mid-stream, so later associative rows keep their
                // array order too (matching positional semantics).
                yield array_values($row);
                continue;
            }

            if (array_keys($row) === $columnKeys) {
                yield array_values($row);
                continue;
            }

            // Unknown keys would be silently dropped by alignment, so they are
            // rejected instead of losing data.
            foreach ($row as $key => $_value) {
                if (!isset($columnKeysMap[$key])) {
                    throw new WriteException(
                        "Row contains column key '{$key}' absent from the header (sheet '{$sheetName}')",
                    );
                }
            }
            $aligned = [];
            foreach ($columnKeys as $key) {
                $aligned[] = $row[$key] ?? null;
            }
            yield $aligned;
        }
    }
}
