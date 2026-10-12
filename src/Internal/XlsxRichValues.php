<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Internal;

use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Spread;
use ZipArchive;

/**
 * Resolves XLSX rich value cells (`vm` attribute) to a plain fallback value.
 *
 * Only the value shown in the cell is recovered: the rich value `fb` element when
 * present, otherwise its `_DisplayString` key (linked data types). See docs/value-types.md.
 *
 * @internal
 */
final class XlsxRichValues
{
    public const REL_METADATA = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/sheetMetadata';
    public const REL_RICH_VALUE = 'http://schemas.microsoft.com/office/2017/06/relationships/rdRichValue';
    public const REL_STRUCTURE = 'http://schemas.microsoft.com/office/2017/06/relationships/rdRichValueStructure';

    private const NS_RICH_DATA = 'http://schemas.microsoft.com/office/spreadsheetml/2017/richdata';
    private const MAX_METADATA_SIZE = 50_000_000;
    private const MAX_RICH_VALUES_SIZE = 500_000_000;
    private const FALLBACK_TYPES = ['b' => 'b', 'n' => 'n', 'e' => 'e', 's' => 'str'];

    /**
     * @param array<string, string> $parts Workbook relationship type => part path.
     * @return array<int, array{string, string}> 1-based `vm` index => [cell type, raw value].
     * @throws InvalidDocumentException
     */
    public static function load(ZipArchive $zip, array $parts): array
    {
        $metadataPath = $parts[self::REL_METADATA] ?? null;
        $richValuePath = $parts[self::REL_RICH_VALUE] ?? null;
        $structurePath = $parts[self::REL_STRUCTURE] ?? null;
        if ($metadataPath === null || $richValuePath === null || $structurePath === null) {
            return [];
        }

        $metadata = Spread::zipGetData($zip, $metadataPath, self::MAX_METADATA_SIZE);
        $vmToRichValue = $metadata ? self::readMetadata($metadata) : [];
        if ($vmToRichValue === [] || $zip->locateName($richValuePath) === false) {
            return [];
        }

        $structures = Spread::zipGetData($zip, $structurePath, self::MAX_METADATA_SIZE);
        $displayKeys = $structures ? self::readDisplayKeys($structures) : [];

        $staged = Spread::zipStageEntry($zip, $richValuePath, self::MAX_RICH_VALUES_SIZE);
        try {
            $values = self::readRichValues($staged, array_fill_keys($vmToRichValue, true), $displayKeys);
        } finally {
            unlink($staged);
        }

        $cells = [];
        foreach ($vmToRichValue as $vm => $index) {
            if (isset($values[$index])) {
                $cells[$vm] = $values[$index];
            }
        }
        return $cells;
    }

    /**
     * Map each value metadata block (1-based `vm`) to its rich value index.
     *
     * @return array<int, int>
     */
    private static function readMetadata(string $data): array
    {
        $xml = Spread::safeXml($data);

        $richType = null;
        $position = 0;
        foreach ($xml->metadataTypes->metadataType ?? [] as $type) {
            $position++;
            if ((string) $type['name'] === 'XLRICHVALUE') {
                $richType = $position;
                break;
            }
        }
        if ($richType === null) {
            return [];
        }

        $richValueIndexes = [];
        foreach ($xml->futureMetadata ?? [] as $future) {
            if ((string) $future['name'] !== 'XLRICHVALUE') {
                continue;
            }
            foreach ($future->bk as $bk) {
                $index = null;
                foreach ($bk->extLst->ext ?? [] as $ext) {
                    $i = (string) $ext->children(self::NS_RICH_DATA)->rvb?->attributes()['i'];
                    if ($i !== '' && ctype_digit($i)) {
                        $index = (int) $i;
                    }
                }
                $richValueIndexes[] = $index;
            }
        }

        $map = [];
        $vm = 0;
        foreach ($xml->valueMetadata->bk ?? [] as $bk) {
            $vm++;
            foreach ($bk->rc as $rc) {
                if ((int) $rc['t'] !== $richType) {
                    continue;
                }
                $index = $richValueIndexes[(int) $rc['v']] ?? null;
                if ($index !== null) {
                    $map[$vm] = $index;
                }
                break;
            }
        }
        return $map;
    }

    /**
     * Position of the `_DisplayString` key in each rich value structure, if any.
     *
     * @return array<int, int>
     */
    private static function readDisplayKeys(string $data): array
    {
        $xml = Spread::safeXml($data);
        $keys = [];
        $structure = 0;
        foreach ($xml->s ?? [] as $s) {
            $position = 0;
            foreach ($s->k as $k) {
                if ((string) $k['n'] === '_DisplayString') {
                    $keys[$structure] = $position;
                    break;
                }
                $position++;
            }
            $structure++;
        }
        return $keys;
    }

    /**
     * Stream the rich value part, decoding only the wanted indexes.
     *
     * @param array<int, true> $wanted
     * @param array<int, int> $displayKeys
     * @return array<int, array{string, string}>
     */
    private static function readRichValues(string $filename, array $wanted, array $displayKeys): array
    {
        $reader = StrictXmlReader::openFile(
            $filename,
            'Failed to parse rich value data (truncated or corrupt XML)',
            'Failed to open rich value data',
        );

        $values = [];
        $index = -1;
        try {
            $moved = $reader->read();
            while ($wanted !== [] && $moved) {
                if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'rv') {
                    $moved = $reader->read();
                    continue;
                }
                $index++;
                if (!isset($wanted[$index])) {
                    // next() lands on the following sibling, which may itself be an <rv>.
                    $moved = $reader->next();
                    continue;
                }
                unset($wanted[$index]);

                $structure = (int) $reader->getAttribute('s');
                $fallback = null;
                $fields = [];
                if (!$reader->isEmptyElement) {
                    $depth = $reader->depth;
                    while ($reader->read() && $reader->depth > $depth) {
                        if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->depth !== ($depth + 1)) {
                            continue;
                        }
                        if ($reader->name === 'fb') {
                            $type = self::FALLBACK_TYPES[$reader->getAttribute('t') ?? 'n'] ?? 'str';
                            $fallback = [$type, $reader->readString()];
                        } elseif ($reader->name === 'v') {
                            $fields[] = $reader->readString();
                        }
                    }
                }

                if ($fallback !== null) {
                    $values[$index] = $fallback;
                } elseif (isset($displayKeys[$structure], $fields[$displayKeys[$structure]])) {
                    $values[$index] = ['str', $fields[$displayKeys[$structure]]];
                }
                $moved = $reader->read();
            }
        } finally {
            $reader->close();
        }
        return $values;
    }
}
