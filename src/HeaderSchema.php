<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\MissingColumnException;

final class HeaderSchema
{
    /** @var array<int, string[]> Paths per column in selection order (sequential keys for mapRow iteration). */
    private array $paths;

    /** @var string[] Logical column names in schema order (leaf of each path). */
    private array $columnNames;

    /** @var bool Whether all paths are single-element (flat headers). */
    private bool $isFlat;

    /** @var bool Whether paths have sequential zero-based keys (enables array_combine fast path). */
    private bool $pathsAreSequential;

    /** @var ?int[] Physical column indices for each path position (null = same as keys). Used by XLSX/ODS fast path. */
    private ?array $physIndices;

    /**
     * @param array<int, string[]> $paths
     * @param ?int[] $physIndices Physical indices or null (defaults to array_keys($paths))
     */
    private function __construct(array $paths, ?array $physIndices = null)
    {
        $this->paths = $paths;
        $this->physIndices = $physIndices;

        $this->isFlat = true;
        $this->columnNames = [];
        foreach ($paths as $path) {
            $leaf = (string) end($path);
            $this->columnNames[] = $leaf;
            if (count($path) !== 1) {
                $this->isFlat = false;
            }
        }

        $this->pathsAreSequential = array_keys($paths) === range(0, count($paths) - 1);

        $this->validate();
    }

    // ─── Named constructors ────────────────────────────────────

    /**
     * @param string[] $headers
     * @param null|callable(string): string $normalizer
     */
    public static function fromFlatHeaders(array $headers, ?callable $normalizer = null): self
    {
        $paths = [];
        foreach (array_map('strval', $headers) as $idx => $name) {
            if ($normalizer !== null && $name !== '') {
                $name = (string) $normalizer($name);
            }
            $paths[$idx] = [$name];
        }
        /** @var array<int, string[]> $paths */
        return new self($paths);
    }

    /**
     * @param array<int, array<int, ?string>> $rows
     * @param null|callable(string): string $normalizer
     */
    public static function fromRows(array $rows, ?callable $normalizer = null): self
    {
        if (empty($rows)) {
            return new self([]);
        }
        if (count($rows) === 1) {
            /** @var string[] $flatRow */
            $flatRow = $rows[0];
            return self::fromFlatHeaders($flatRow, $normalizer);
        }
        return new self(self::buildHierarchicalPaths($rows, $normalizer));
    }

    /**
     * @param array<string|int, string|array<array-key, mixed>> $definition
     */
    public static function fromDefinition(array $definition): self
    {
        foreach ($definition as $v) {
            if (is_array($v)) {
                return new self(self::walkDefinition($definition, []));
            }
        }
        /** @var string[] $definition */
        return self::fromFlatHeaders($definition);
    }

    /**
     * @param string[]|array<int, array<int, ?string>> $headers
     * @param int $headerRows
     * @param null|callable(string): string $normalizer
     */
    public static function fromHeaders(array $headers, int $headerRows = 1, ?callable $normalizer = null): self
    {
        if ($headerRows > 1) {
            if (empty($headers)) {
                return new self([]);
            }
            $first = reset($headers);
            if (!is_array($first)) {
                /** @var string[] $headers */
                return self::fromFlatHeaders($headers, $normalizer);
            }
            /** @var array<int, array<int, ?string>> $headers */
            return self::fromRows($headers, $normalizer);
        }
        /** @var string[] $headers */
        return self::fromFlatHeaders($headers, $normalizer);
    }

    // ─── Accessors ─────────────────────────────────────────────

    /** @return string[] */
    public function columnNames(): array
    {
        return $this->columnNames;
    }

    public function columnCount(): int
    {
        return count($this->paths);
    }

    /** @return array<int, string[]> */
    public function paths(): array
    {
        return $this->paths;
    }

    /** @return int[] Physical column indices in schema order. */
    public function indices(): array
    {
        if ($this->physIndices !== null) {
            return $this->physIndices;
        }
        return array_keys($this->paths);
    }

    // ─── Checks ────────────────────────────────────────────────

    /**
     * @param array<string|int, string|array<array-key, mixed>> $requiredColumns
     */
    public function checkRequiredColumns(array $requiredColumns): void
    {
        if (empty($requiredColumns)) {
            return;
        }
        $requiredPaths = self::normalizePaths($requiredColumns);

        $existing = [];
        foreach ($this->paths as $path) {
            $existing[implode("\0", $path)] = true;
        }

        $missing = [];
        foreach ($requiredPaths as $rp) {
            $k = implode("\0", $rp);
            if (!isset($existing[$k])) {
                $missing[] = implode('.', $rp);
            }
        }
        if (!empty($missing)) {
            throw new MissingColumnException($missing);
        }
    }

    // ─── Transformations ───────────────────────────────────────

    /**
     * Select a subset of columns by path.
     *
     * @param array<string|int, string|array<array-key, mixed>> $columns
     */
    public function select(array $columns): self
    {
        if (empty($columns)) {
            return clone $this;
        }

        $selectedPaths = self::normalizePaths($columns);

        $pathToIndex = [];
        foreach ($this->paths as $idx => $path) {
            $pathToIndex[implode("\0", $path)] = $idx;
        }

        $newPaths = [];
        $newPhysIndices = [];
        $missing = [];

        foreach ($selectedPaths as $i => $selPath) {
            $key = implode("\0", $selPath);
            if (isset($pathToIndex[$key])) {
                $physIdx = $pathToIndex[$key];
                $newPaths[$i] = $this->paths[$physIdx];
                $newPhysIndices[$i] = $physIdx;
            } else {
                $missing[] = implode('.', $selPath);
            }
        }

        if (!empty($missing)) {
            throw new MissingColumnException($missing);
        }

        return new self($newPaths, $newPhysIndices);
    }

    /**
     * Rename columns (last path segment) via alias map.
     *
     * @param array<string|int, string|array<array-key, mixed>> $aliases
     */
    public function rename(array $aliases): self
    {
        if (empty($aliases)) {
            return $this;
        }

        $aliasMap = self::buildAliasMap($aliases);
        $newPaths = [];

        foreach ($this->paths as $i => $path) {
            $key = implode("\0", $path);
            if (isset($aliasMap[$key])) {
                $renamed = $path;
                $renamed[count($renamed) - 1] = $aliasMap[$key];
                $newPaths[$i] = $renamed;
            } else {
                $newPaths[$i] = $path;
            }
        }

        return new self($newPaths, $this->physIndices);
    }

    // ─── Row mapping ───────────────────────────────────────────

    /**
     * Map a flat data row (numeric indices) to an associative array.
     *
     * @param array<int, mixed> $row
     * @return array<string|int, mixed>
     */
    public function mapRow(array $row): array
    {
        if ($this->isFlat && $this->pathsAreSequential && $this->physIndices === null) {
            $c = count($row);
            $n = count($this->columnNames);
            if ($c < $n) {
                $row = array_pad($row, $n, null);
            } elseif ($c > $n) {
                $row = array_slice($row, 0, $n);
            }
            return array_combine($this->columnNames, $row);
        }

        $result = [];
        $phys = $this->physIndices;
        foreach ($this->paths as $i => $path) {
            $physIdx = $phys !== null ? $phys[$i] : $i;
            $value = $row[$physIdx] ?? null;
            if ($this->isFlat) {
                $result[$path[0]] = $value;
            } else {
                $this->setNestedValue($result, $path, $value);
            }
        }
        return $result;
    }

    /**
     * Flatten a nested row back to a numeric array matching paths.
     *
     * @param array<int|string, mixed> $nestedRow
     * @return array<int, mixed>
     */
    public function flattenRow(array $nestedRow): array
    {
        if ($this->physIndices === null && array_keys($nestedRow) === range(0, count($nestedRow) - 1)) {
            $n = count($nestedRow);
            $total = $this->columnCount();
            if ($n < $total) {
                /** @var array<int, mixed> */
                return array_pad($nestedRow, $total, null);
            }
            if ($n > $total) {
                /** @var array<int, mixed> */
                return array_slice($nestedRow, 0, $total);
            }
            /** @var array<int, mixed> $nestedRow */
            return $nestedRow;
        }

        // After early null-return above, physIndices is known non-null
        $physIdxList = $this->physIndices;
        if ($physIdxList !== null && count($physIdxList) > 0) {
            $maxPhysIdx = max($physIdxList);
        } else {
            $maxPhysIdx = 0;
        }
        $flat = array_fill(0, max($this->columnCount(), $maxPhysIdx + 1), null);
        foreach ($this->paths as $i => $path) {
            $physIdx = $this->physIndices !== null ? $this->physIndices[$i] : $i;
            $value = $nestedRow;
            // Handle already-flat rows with non-sequential physIndices
            if (array_keys($nestedRow) === range(0, count($nestedRow) - 1)) {
                $value = $nestedRow[$physIdx] ?? null;
            } else {
                foreach ($path as $seg) {
                    if (is_array($value) && array_key_exists($seg, $value)) {
                        $value = $value[$seg];
                    } else {
                        $value = null;
                        break;
                    }
                }
            }
            $flat[$physIdx] = $value;
        }
        return $flat;
    }

    /**
     * Return the header rows needed to write this schema.
     *
     * @return array<int, string[]>
     */
    public function headerRows(): array
    {
        if (empty($this->paths)) {
            return [];
        }
        $maxDepth = (int) max(array_map('count', $this->paths));
        $totalCols = $this->columnCount();
        if ($this->physIndices !== null) {
            $physIdxList = $this->physIndices;
            if (count($physIdxList) > 0) {
                $maxPhysIdx = max($physIdxList);
            } else {
                $maxPhysIdx = 0;
            }
            $totalCols = max($totalCols, $maxPhysIdx + 1);
        }
        /** @var array<int, string[]> $rows */
        $rows = array_fill(0, $maxDepth, array_fill(0, $totalCols, ''));
        foreach ($this->paths as $i => $path) {
            $physIdx = $this->physIndices !== null ? $this->physIndices[$i] : $i;
            foreach ($path as $depth => $segment) {
                $rows[$depth][$physIdx] = $segment;
            }
        }
        /** @var array<int, string[]> $rows */
        return $rows;
    }

    // ─── Auto-detection ────────────────────────────────────────

    /**
     * Stream-friendly header detection using a rolling window.
     *
     * @param array<string|int, string|array<array-key, mixed>> $requiredColumns
     * @param int $headerRows
     * @param array<int, array<int, ?string>> $window Initial window (modified in-place)
     * @param callable(): (array<int, ?string>|false) $readNext Called to fetch next row
     * @param int $maxScan
     * @param null|callable(string): string $normalizer
     * @return int|null 0-based offset of first header row, or null
     */
    public static function detectHeaderOffset(
        array $requiredColumns,
        int $headerRows,
        array &$window,
        callable $readNext,
        int $maxScan = 50,
        ?callable $normalizer = null,
    ): ?int {
        $requiredPaths = self::normalizePaths($requiredColumns);

        $offset = 0;

        while (count($window) >= $headerRows && $offset < $maxScan) {
            $candidate = array_slice($window, 0, $headerRows);

            try {
                $schema = self::fromRows($candidate, $normalizer);
            } catch (InvalidDocumentException) {
                // Not a valid header block — advance window
                $next = self::readNextNonEmpty($readNext);
                if ($next === null) {
                    break;
                }
                array_shift($window);
                $window[] = $next;
                $offset++;
                continue;
            }

            $found = true;
            foreach ($requiredPaths as $rp) {
                $rk = implode("\0", $rp);
                $ok = false;
                foreach ($schema->paths as $sp) {
                    if (implode("\0", $sp) === $rk) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    $found = false;
                    break;
                }
            }

            if ($found) {
                return $offset;
            }

            $next = self::readNextNonEmpty($readNext);
            if ($next === null) {
                break;
            }
            array_shift($window);
            $window[] = $next;
            $offset++;
        }

        return null;
    }

    /**
     * @param callable(): (array<int, ?string>|false) $readNext
     * @return array<int, ?string>|null
     */
    private static function readNextNonEmpty(callable $readNext): ?array
    {
        while (true) {
            $row = $readNext();
            if ($row === false) {
                return null;
            }
            if ($row === [null]) {
                continue;
            }
            return $row;
        }
    }

    // ─── Internal ──────────────────────────────────────────────

    private function validate(): void
    {
        if (empty($this->paths)) {
            return;
        }

        // Duplicate full paths
        $pathStrings = array_map(static fn(array $p) => implode("\0", $p), $this->paths);
        $duplicates = array_keys(array_filter(
            array_count_values($pathStrings),
            static fn(int $c) => $c > 1,
        ));
        if (!empty($duplicates)) {
            $dup = array_map(static fn(string $s) => implode('.', explode("\0", $s)), $duplicates);
            throw new InvalidDocumentException('Duplicate header path(s) found: ' . implode(', ', $dup));
        }

        // No path is a strict prefix of another (e.g. ['Domaine'] and ['Domaine', 'Date'])
        foreach ($this->paths as $aIdx => $pathA) {
            foreach ($this->paths as $bIdx => $pathB) {
                if ($aIdx === $bIdx) {
                    continue;
                }
                $minLen = min(count($pathA), count($pathB));
                if ($minLen === 0) {
                    continue;
                }
                $match = true;
                for ($i = 0; $i < $minLen; $i++) {
                    if ($pathA[$i] !== $pathB[$i]) {
                        $match = false;
                        break;
                    }
                }
                if ($match && count($pathA) !== count($pathB)) {
                    $shorter = count($pathA) < count($pathB) ? $pathA : $pathB;
                    $longer = count($pathA) < count($pathB) ? $pathB : $pathA;
                    throw new InvalidDocumentException(
                        'Ambiguous header: "'
                        . implode('.', $shorter)
                        . '" cannot coexist with "'
                        . implode('.', $longer)
                        . '" as one is a prefix of the other.',
                    );
                }
            }
        }
    }

    /**
     * @param array<string|int, mixed> &$result
     * @param string[] $path
     * @param mixed $value
     */
    private function setNestedValue(array &$result, array $path, $value): void
    {
        $current = &$result;
        foreach ($path as $i => $key) {
            if ($i === (count($path) - 1)) {
                $current[$key] = $value;
            } else {
                if (!isset($current[$key]) || !is_array($current[$key])) {
                    $current[$key] = [];
                }
                $current = &$current[$key];
            }
        }
    }

    /**
     * @param array<string|int, string|array<array-key, mixed>> $def
     * @return array<int, string[]>
     */
    public static function normalizePaths(array $def): array
    {
        /** @var array<int, string[]> $paths */
        $paths = [];
        $walk = static function (array $items, array $prefix) use (&$walk, &$paths): void {
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    /** @var array<string|int, string|array<array-key, mixed>> $value */
                    /** @var string[] $prefix */
                    $walk($value, array_merge($prefix, [(string) $key]));
                } elseif (is_string($value)) {
                    $paths[] = array_merge($prefix, [$value]);
                }
            }
        };
        $walk($def, []);
        /** @var array<int, string[]> $paths */
        return $paths;
    }

    /**
     * @param array<string|int, string|array<array-key, mixed>> $aliases
     * @return array<string, string> full-path-key → new leaf name
     */
    private static function buildAliasMap(array $aliases): array
    {
        $map = [];
        $walk = static function (array $items, array $prefix) use (&$walk, &$map): void {
            foreach ($items as $key => $value) {
                if (is_array($value)) {
                    /** @var array<string|int, string|array<array-key, mixed>> $value */
                    /** @var string[] $prefix */
                    $walk($value, array_merge($prefix, [(string) $key]));
                } elseif (is_string($value)) {
                    /** @var string[] $full */
                    $full = array_merge($prefix, [(string) $key]);
                    $map[implode("\0", $full)] = $value;
                }
            }
        };
        $walk($aliases, []);
        return $map;
    }

    /**
     * @param array<string|int, string|array<array-key, mixed>> $definition
     * @param string[] $prefix
     * @return array<int, string[]>
     */
    private static function walkDefinition(array $definition, array $prefix): array
    {
        /** @var array<int, string[]> $paths */
        $paths = [];
        foreach ($definition as $key => $value) {
            if (is_array($value)) {
                /** @var array<string|int, string|array<array-key, mixed>> $value */
                $paths = array_merge($paths, self::walkDefinition($value, array_merge($prefix, [(string) $key])));
            } else {
                $paths[] = array_merge($prefix, [(string) $value]);
            }
        }
        return $paths;
    }

    /**
     * @param array<int, array<int, ?string>> $rows
     * @param null|callable(string): string $normalizer
     * @return array<int, string[]>
     */
    private static function buildHierarchicalPaths(array $rows, ?callable $normalizer = null): array
    {
        $levelCount = count($rows);
        $counts = array_map('count', $rows);
        $maxWidth = $counts !== [] ? max($counts) : 0;

        $cells = [];
        for ($level = 0; $level < $levelCount; $level++) {
            $cells[$level] = [];
            for ($col = 0; $col < $maxWidth; $col++) {
                $cell = isset($rows[$level][$col]) ? (string) $rows[$level][$col] : '';
                if ($normalizer !== null && $cell !== '') {
                    $cell = (string) $normalizer($cell);
                }
                $cells[$level][$col] = $cell;
            }
        }

        $prevValues = array_fill(0, $levelCount, '');
        $paths = [];

        for ($col = 0; $col < $maxWidth; $col++) {
            $path = array_fill(0, $levelCount, '');
            $resetBelow = $levelCount + 1;

            for ($level = 0; $level < $levelCount; $level++) {
                $cell = $cells[$level][$col];

                if ($cell !== '') {
                    $path[$level] = $cell;
                    if (
                        $col > 0
                        && $prevValues[$level] !== ''
                        && $cell !== $prevValues[$level]
                    ) {
                        $resetBelow = min($resetBelow, $level + 1);
                    }
                } elseif ($col > 0 && $prevValues[$level] !== '' && $level < $resetBelow) {
                    $path[$level] = $prevValues[$level];
                }
            }

            // Update prevValues for the next column.
            // When a parent changes, also clear all deeper prevValues so they
            // can't leak across group boundaries.
            for ($level = 0; $level < $levelCount; $level++) {
                if ($path[$level] !== '') {
                    $prevValues[$level] = $path[$level];
                } elseif ($level >= $resetBelow) {
                    $prevValues[$level] = '';
                }
            }

            $filtered = [];
            foreach ($path as $seg) {
                if ($seg !== '') {
                    $filtered[] = $seg;
                }
            }
            $paths[$col] = $filtered;
        }

        return $paths;
    }
}
