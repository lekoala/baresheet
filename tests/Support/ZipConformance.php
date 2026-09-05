<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Support;

/**
 * Container-level conformance checks for the archives the writers emit.
 *
 * Excel refuses a workbook whose ZIP container uses features it does not
 * implement, and the dialog it shows names neither the offending part nor the
 * reason. These checks keep the container inside the classic ZIP subset Excel
 * accepts, so that class of defect fails in CI instead of at a user's desk.
 *
 * The case that already shipped is ZIP64: entries written to a non-seekable
 * stream advertise ZIP64 up front because their final sizes are not yet known,
 * and Excel rejects the result even though ZipArchive and most PHP readers
 * accept it.
 *
 * Works on raw bytes only, so it stays valid as the writers change.
 *
 * @internal
 */
final class ZipConformance
{
    private const LOCAL_SIGNATURE = 0x0403_4b50;
    private const CENTRAL_SIGNATURE = 0x0201_4b50;
    private const EOCD_SIGNATURE = "PK\x05\x06";
    private const ZIP64_EOCD_SIGNATURE = "PK\x06\x06";
    private const ZIP64_LOCATOR_SIGNATURE = "PK\x06\x07";
    private const ZIP64_EXTRA_TAG = 0x0001;

    /** Highest "version needed to extract" in the classic subset: 2.0, i.e. deflate. ZIP64 is 4.5. */
    private const MAX_VERSION_NEEDED = 20;

    private const UINT16_MAX = 0xFFFF;
    private const UINT32_MAX = 0xFFFF_FFFF;

    private const EOCD_SIZE = 22;
    private const LOCATOR_SIZE = 20;
    private const CENTRAL_HEADER_SIZE = 46;
    private const LOCAL_HEADER_SIZE = 30;

    private const FLAG_ENCRYPTED = 0x0001;
    private const FLAG_DATA_DESCRIPTOR = 0x0008;
    private const FLAG_UTF8_NAMES = 0x0800;

    /**
     * Every way the container falls outside the subset Excel accepts.
     * An empty list means the archive is conformant.
     *
     * @return list<string>
     */
    public static function violations(string $bytes): array
    {
        $violations = [];

        $eocd = self::locateEndOfCentralDirectory($bytes, $violations);
        if ($eocd === null) {
            return $violations;
        }

        self::checkEndOfCentralDirectory($bytes, $eocd, $violations);

        $entries = self::readCentralHeaders($bytes, $eocd['cdOffset'], $eocd['entries'], $violations);
        foreach ($entries as $entry) {
            self::checkEntryName($entry, $violations);
            self::checkCentralHeader($entry, $violations);
            self::checkLocalHeader($bytes, $entry, $violations);
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    public static function violationsOfFile(string $path): array
    {
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return ["unable to read {$path}"];
        }

        return self::violations($bytes);
    }

    /**
     * Entry names in central-directory order, for package-level assertions.
     *
     * @return list<string>
     */
    public static function entryNames(string $bytes): array
    {
        $ignored = [];
        $eocd = self::locateEndOfCentralDirectory($bytes, $ignored);
        if ($eocd === null) {
            return [];
        }

        $names = [];
        foreach (self::readCentralHeaders($bytes, $eocd['cdOffset'], $eocd['entries'], $ignored) as $entry) {
            $names[] = $entry['name'];
        }

        return $names;
    }

    /**
     * @param list<string> $violations
     * @return array<string, int>|null
     */
    private static function locateEndOfCentralDirectory(string $bytes, array &$violations): ?array
    {
        if (strlen($bytes) < self::EOCD_SIZE) {
            $violations[] = 'archive is shorter than an end-of-central-directory record';
            return null;
        }

        $offset = strrpos($bytes, self::EOCD_SIGNATURE);
        if ($offset === false) {
            $violations[] = 'no end-of-central-directory record found';
            return null;
        }

        // A conformant archive ends exactly on the EOCD: no ZIP comment, and no
        // trailing bytes from a stream that kept writing after finish().
        $trailing = strlen($bytes) - ($offset + self::EOCD_SIZE);
        if ($trailing !== 0) {
            $violations[] = "{$trailing} byte(s) follow the end-of-central-directory record";
        }

        $format = 'Vsignature/vdisk/vcdDisk/vdiskEntries/ventries/VcdSize/VcdOffset/vcommentLength';
        $fields = self::unpackAt($bytes, $offset, $format, self::EOCD_SIZE);
        if ($fields === null) {
            $violations[] = 'truncated end-of-central-directory record';
            return null;
        }
        $fields['offset'] = $offset;

        return $fields;
    }

    /**
     * @param array<string, int> $eocd
     * @param list<string> $violations
     */
    private static function checkEndOfCentralDirectory(string $bytes, array $eocd, array &$violations): void
    {
        $offset = $eocd['offset'];

        // The ZIP64 locator sits immediately before the EOCD when present, so
        // its absence is the strongest single signal that no ZIP64 record exists.
        $locator = substr($bytes, $offset - self::LOCATOR_SIZE, 4);
        if ($offset >= self::LOCATOR_SIZE && $locator === self::ZIP64_LOCATOR_SIGNATURE) {
            $violations[] = 'ZIP64 end-of-central-directory locator present';
        }

        // Scan the structural tail only: the central directory and the end
        // records never hold compressed data, so a signature match here cannot
        // be a false positive from deflated bytes.
        $tail = substr($bytes, $eocd['cdOffset']);
        if (str_contains($tail, self::ZIP64_EOCD_SIGNATURE)) {
            $violations[] = 'ZIP64 end-of-central-directory record present';
        }

        $sentinels = [
            'entry count' => [$eocd['entries'], self::UINT16_MAX],
            'central directory size' => [$eocd['cdSize'], self::UINT32_MAX],
            'central directory offset' => [$eocd['cdOffset'], self::UINT32_MAX],
        ];
        foreach ($sentinels as $label => [$value, $sentinel]) {
            if ($value === $sentinel) {
                $violations[] = "end-of-central-directory {$label} is the ZIP64 sentinel";
            }
        }

        if ($eocd['disk'] !== 0 || $eocd['cdDisk'] !== 0) {
            $violations[] = 'archive claims to span multiple disks';
        }
        if ($eocd['diskEntries'] !== $eocd['entries']) {
            $violations[] = "entry count mismatch: {$eocd['diskEntries']} on this disk, {$eocd['entries']} in total";
        }
        if ($eocd['commentLength'] !== 0) {
            $violations[] = 'archive carries a ZIP comment';
        }
        if (($eocd['cdOffset'] + $eocd['cdSize']) !== $offset) {
            $violations[] = 'central directory does not end where the end-of-central-directory record begins';
        }
    }

    /**
     * @param list<string> $violations
     * @return list<array<string, mixed>>
     */
    private static function readCentralHeaders(string $bytes, int $offset, int $count, array &$violations): array
    {
        $entries = [];
        $format =
            'Vsignature/vversionMadeBy/vversionNeeded/vflags/vmethod/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize'
            . '/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset';

        for ($i = 0; $i < $count; $i++) {
            $header = self::unpackAt($bytes, $offset, $format, self::CENTRAL_HEADER_SIZE);
            if ($header === null || $header['signature'] !== self::CENTRAL_SIGNATURE) {
                $violations[] = "central directory entry #{$i} is missing or malformed";
                return $entries;
            }

            $entry = $header;
            $entry['index'] = $i;
            $entry['name'] = substr($bytes, $offset + self::CENTRAL_HEADER_SIZE, $header['nameLength']);
            $entry['extra'] = substr(
                $bytes,
                $offset + self::CENTRAL_HEADER_SIZE + $header['nameLength'],
                $header['extraLength'],
            );
            $entries[] = $entry;

            $offset +=
                self::CENTRAL_HEADER_SIZE + $header['nameLength'] + $header['extraLength'] + $header['commentLength'];
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $violations
     */
    private static function checkCentralHeader(array $entry, array &$violations): void
    {
        $label = self::label($entry);
        $max = self::MAX_VERSION_NEEDED;

        if ($entry['versionNeeded'] > $max) {
            $violations[] = "{$label}: central header needs version {$entry['versionNeeded']} to extract, classic ZIP is at most {$max}";
        }
        if (self::hasZip64Extra($entry['extra'])) {
            $violations[] = "{$label}: central header carries a ZIP64 extra field";
        }
        self::checkFlags($label, 'central header', $entry['flags'], $violations);

        $sentinels = [
            'compressed size' => $entry['compressedSize'],
            'uncompressed size' => $entry['uncompressedSize'],
            'local header offset' => $entry['localOffset'],
        ];
        foreach ($sentinels as $field => $value) {
            if ($value === self::UINT32_MAX) {
                $violations[] = "{$label}: central header {$field} is the ZIP64 sentinel";
            }
        }

        if ($entry['diskStart'] !== 0) {
            $violations[] = "{$label}: entry starts on disk {$entry['diskStart']}";
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $violations
     */
    private static function checkLocalHeader(string $bytes, array $entry, array &$violations): void
    {
        $label = self::label($entry);
        $offset = $entry['localOffset'];
        $max = self::MAX_VERSION_NEEDED;

        $format = 'Vsignature/vversionNeeded/vflags/vmethod/vmodTime/vmodDate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength';
        $header = self::unpackAt($bytes, $offset, $format, self::LOCAL_HEADER_SIZE);
        if ($header === null || $header['signature'] !== self::LOCAL_SIGNATURE) {
            $violations[] = "{$label}: no local header at offset {$offset}";
            return;
        }

        if ($header['versionNeeded'] > $max) {
            $violations[] = "{$label}: local header needs version {$header['versionNeeded']} to extract, classic ZIP is at most {$max}";
        }

        $extra = substr($bytes, $offset + self::LOCAL_HEADER_SIZE + $header['nameLength'], $header['extraLength']);
        if (self::hasZip64Extra($extra)) {
            $violations[] = "{$label}: local header carries a ZIP64 extra field";
        }
        self::checkFlags($label, 'local header', $header['flags'], $violations);

        $localName = substr($bytes, $offset + self::LOCAL_HEADER_SIZE, $header['nameLength']);
        if ($localName !== $entry['name']) {
            $violations[] = "{$label}: local header names the entry '{$localName}'";
        }

        // Without a data descriptor the local header must already carry the
        // final metadata, which is what Excel reads before the central directory.
        if (($header['flags'] & self::FLAG_DATA_DESCRIPTOR) !== 0) {
            return;
        }
        foreach (['crc', 'compressedSize', 'uncompressedSize'] as $field) {
            if ($header[$field] !== $entry[$field]) {
                $violations[] = "{$label}: local header {$field} {$header[$field]} does not match central directory {$entry[$field]}";
            }
        }
    }

    /**
     * @param list<string> $violations
     */
    private static function checkFlags(string $label, string $where, int $flags, array &$violations): void
    {
        if (($flags & self::FLAG_DATA_DESCRIPTOR) !== 0) {
            $violations[] = "{$label}: {$where} defers sizes to a data descriptor, general purpose bit 3";
        }
        if (($flags & self::FLAG_ENCRYPTED) !== 0) {
            $violations[] = "{$label}: {$where} marks the entry encrypted";
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param list<string> $violations
     */
    private static function checkEntryName(array $entry, array &$violations): void
    {
        $name = $entry['name'];
        $label = self::label($entry);

        if ($name === '') {
            $violations[] = "{$label}: empty entry name";
            return;
        }
        if (str_starts_with($name, '/')) {
            $violations[] = "{$label}: entry name is absolute";
        }
        if (str_contains($name, '\\')) {
            $violations[] = "{$label}: entry name uses a backslash separator";
        }
        if (str_contains($name, "\0")) {
            $violations[] = "{$label}: entry name contains a NUL byte";
        }
        if (in_array('..', explode('/', $name), true)) {
            $violations[] = "{$label}: entry name traverses out of the archive";
        }
        if (preg_match('/[^\x20-\x7E]/', $name) === 1 && ($entry['flags'] & self::FLAG_UTF8_NAMES) === 0) {
            $violations[] = "{$label}: non-ASCII entry name without the UTF-8 flag, general purpose bit 11";
        }
    }

    private static function hasZip64Extra(string $extra): bool
    {
        $offset = 0;
        $length = strlen($extra);

        while (($offset + 4) <= $length) {
            $field = self::unpackAt($extra, $offset, 'vtag/vsize', 4);
            if ($field === null) {
                return false;
            }
            if ($field['tag'] === self::ZIP64_EXTRA_TAG) {
                return true;
            }
            $offset += 4 + $field['size'];
        }

        return false;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function label(array $entry): string
    {
        $name = $entry['name'];

        return $name === '' ? "entry #{$entry['index']}" : "entry '{$name}'";
    }

    /**
     * @return array<string, int>|null
     */
    private static function unpackAt(string $bytes, int $offset, string $format, int $size): ?array
    {
        if ($offset < 0 || ($offset + $size) > strlen($bytes)) {
            return null;
        }

        $fields = unpack($format, substr($bytes, $offset, $size));

        return $fields === false ? null : $fields;
    }
}
