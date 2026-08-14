<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use LeKoala\Baresheet\Exception\WriteException;
use RuntimeException;

/**
 * Minimal seekable ZIP writer used to package XLSX output.
 *
 * Streams each entry directly into the output: chunks are hashed and compressed
 * as they arrive, then the local file header is patched in-place with the final
 * CRC and sizes via fseek(). This avoids the data descriptors and the
 * measure-then-send double pass that streaming ZIP libraries need on
 * non-seekable sinks, keeping peak memory bounded by the producer's chunk size.
 *
 * Capabilities:
 *  - classic ZIP and ZIP64 (entry- and archive-level, only when required)
 *  - DEFLATE and STORE compression
 *  - UTF-8 filenames (EFS flag)
 *  - seekable output only
 *  - DOS timestamps
 *
 * This is an XLSX packaging primitive, not a general-purpose ZIP library.
 */
final class DirectZipWriter
{
    private const LOCAL_FILE_HEADER_SIGNATURE = 0x0403_4b50;
    private const CENTRAL_DIRECTORY_SIGNATURE = 0x0201_4b50;
    private const END_OF_CENTRAL_DIRECTORY_SIGNATURE = 0x0605_4b50;
    private const ZIP64_END_OF_CENTRAL_DIRECTORY_SIGNATURE = 0x0606_4b50;
    private const ZIP64_END_OF_CENTRAL_DIRECTORY_LOCATOR_SIGNATURE = 0x0706_4b50;
    private const ZIP64_EXTRA_FIELD_TAG = 0x0001;

    private const VERSION_CLASSIC = 20; // ZIP 2.0
    private const VERSION_ZIP64 = 45; // ZIP 4.5

    private const METHOD_STORE = 0;
    private const METHOD_DEFLATE = 8;

    private const UTF8_FLAG = 0x0800;

    private const UINT16_MAX = 0xFFFF;
    private const UINT32_MAX = 0xFFFF_FFFF;

    /**
     * @var resource
     */
    private $output;

    /**
     * @var list<array{
     *     name:string,
     *     crc:int,
     *     compressedSize:int,
     *     uncompressedSize:int,
     *     offset:int,
     *     dosTime:int,
     *     dosDate:int,
     *     flags:int,
     *     method:int
     * }>
     */
    private array $entries = [];

    private bool $finished = false;

    /**
     * @param resource $output Seekable writable stream.
     */
    public function __construct(
        $output,
        private readonly int $compressionLevel = 6,
    ) {
        if (!is_resource($output)) {
            throw new RuntimeException('DirectZipWriter output must be a writable stream resource');
        }

        $meta = stream_get_meta_data($output);
        if ($meta['seekable'] !== true) {
            throw new WriteException('DirectZipWriter requires a seekable output stream');
        }

        if ($compressionLevel < -1 || $compressionLevel > 9) {
            throw new WriteException('Compression level must be between -1 and 9');
        }

        if (!function_exists('deflate_init')) {
            throw new WriteException('The zlib extension is required to write ZIP archives');
        }

        $this->output = $output;
    }

    public static function create(string $filename, int $compressionLevel = 6): self
    {
        $stream = fopen($filename, 'w+b');
        if ($stream === false) {
            throw new WriteException("Unable to open ZIP output: {$filename}");
        }

        return new self($stream, $compressionLevel);
    }

    /**
     * Add a small in-memory entry.
     */
    public function addString(
        string $name,
        string $contents,
        ?DateTimeInterface $lastModificationDateTime = null,
        bool $store = false,
    ): void {
        $this->addEntry(
            name: $name,
            contents: $contents,
            stream: null,
            readChunkSize: null,
            producer: null,
            lastModificationDateTime: $lastModificationDateTime,
            store: $store,
        );
    }

    /**
     * Add an entry from the current position of a readable stream.
     *
     * @param resource $stream
     */
    public function addStream(
        string $name,
        $stream,
        int $readChunkSize = 1024 * 1024,
        ?DateTimeInterface $lastModificationDateTime = null,
    ): void {
        if (!is_resource($stream)) {
            throw new WriteException('DirectZipWriter input must be a readable stream resource');
        }

        if ($readChunkSize < 1) {
            throw new WriteException('Read chunk size must be greater than zero');
        }

        $this->addEntry(
            name: $name,
            contents: null,
            stream: $stream,
            readChunkSize: $readChunkSize,
            producer: null,
            lastModificationDateTime: $lastModificationDateTime,
            store: false,
        );
    }

    /**
     * Add an entry produced incrementally by a callback.
     *
     * The producer receives a single write function and pushes any number of
     * chunks through it. ZIP64 is decided automatically from the final sizes.
     *
     * @param callable(callable(string):void):void $producer
     */
    public function addCallback(
        string $name,
        callable $producer,
        ?DateTimeInterface $lastModificationDateTime = null,
    ): void {
        $this->addEntry(
            name: $name,
            contents: null,
            stream: null,
            readChunkSize: null,
            producer: $producer,
            lastModificationDateTime: $lastModificationDateTime,
            store: false,
        );
    }

    /**
     * @param callable(callable(string):void):void|null $producer
     * @param resource|null $stream
     */
    private function addEntry(
        string $name,
        ?string $contents,
        $stream,
        ?int $readChunkSize,
        ?callable $producer,
        ?DateTimeInterface $lastModificationDateTime,
        bool $store,
    ): void {
        $this->assertOpen();
        $this->validateName($name);

        $method = $store ? self::METHOD_STORE : self::METHOD_DEFLATE;
        $offset = $this->position();

        [$dosTime, $dosDate] = self::dosDateTime(
            $lastModificationDateTime ?? new DateTimeImmutable(),
        );

        $flags = self::UTF8_FLAG;
        $nameLength = strlen($name);
        if ($nameLength > self::UINT16_MAX) {
            throw new WriteException('ZIP entry name is too long');
        }

        // Every local header reserves exactly 20 bytes for the ZIP64 extra
        // field. It is initially a valid Microsoft Open Packaging Growth Hint
        // (0xA220) so classic entries remain spec-conformant; if the entry
        // turns out to exceed 4 GiB, the same 20 bytes are rewritten in place
        // as the ZIP64 0x0001 field (also exactly 20 bytes). This keeps the
        // classic/ZIP64 decision transparent and single-pass.
        $localExtra = self::packGrowthHint();

        $localHeader = pack(
            'VvvvvvVVVvv',
            self::LOCAL_FILE_HEADER_SIGNATURE,
            self::VERSION_ZIP64, // patched in place to 20 if the entry is classic
            $flags,
            $method,
            $dosTime,
            $dosDate,
            0, // CRC-32 placeholder, patched after streaming
            0, // compressed size placeholder
            0, // uncompressed size placeholder
            $nameLength,
            strlen($localExtra),
        );

        $this->writeAll($localHeader);
        $this->writeAll($name);
        $this->writeAll($localExtra);

        $crcContext = hash_init('crc32b');
        $compressedSize = 0;
        $uncompressedSize = 0;

        $deflateContext = null;
        if ($method === self::METHOD_DEFLATE) {
            $deflateContext = deflate_init(ZLIB_ENCODING_RAW, ['level' => $this->compressionLevel]);
            if ($deflateContext === false) {
                throw new WriteException('Unable to initialize DEFLATE context');
            }
        }

        $write = function (string $chunk) use (
            $crcContext,
            $deflateContext,
            $method,
            &$compressedSize,
            &$uncompressedSize,
        ): void {
            if ($chunk === '') {
                return;
            }

            $uncompressedSize += strlen($chunk);
            hash_update($crcContext, $chunk);

            if ($method === self::METHOD_STORE) {
                $out = $chunk;
            } else {
                $out = deflate_add($deflateContext, $chunk, ZLIB_NO_FLUSH);
                if ($out === false) {
                    throw new WriteException('Unable to deflate ZIP entry data');
                }
            }

            if ($out !== '') {
                $compressedSize += strlen($out);
                $this->writeAll($out);
            }
        };

        if ($contents !== null) {
            $write($contents);
        } elseif ($producer !== null) {
            $producer($write);
        } else {
            if (!is_resource($stream) || $readChunkSize === null || $readChunkSize < 1) {
                throw new WriteException('ZIP stream entry requires a readable stream and a chunk size');
            }
            while (!feof($stream)) {
                $chunk = fread($stream, $readChunkSize);
                if ($chunk === false) {
                    throw new WriteException('Unable to read ZIP input stream');
                }
                if ($chunk !== '') {
                    $write($chunk);
                }
            }
        }

        if ($deflateContext !== null) {
            $tail = deflate_add($deflateContext, '', ZLIB_FINISH);
            if ($tail === false) {
                throw new WriteException('Unable to finish DEFLATE stream');
            }
            if ($tail !== '') {
                $compressedSize += strlen($tail);
                $this->writeAll($tail);
            }
        }

        $crcHex = hash_final($crcContext);
        // crc32b returns network-byte-order bytes; hexdec reads them as the
        // unsigned numeric CRC, which ZIP stores little-endian via pack('V').
        $crc = (int) hexdec($crcHex);
        $endOffset = $this->position();

        // Decide classic vs ZIP64 from the actual sizes and patch in place.
        [$version, $zip64, $sizePatch, $extraPatch] = self::localHeaderMetadata(
            $crc,
            $compressedSize,
            $uncompressedSize,
        );

        $this->seek($offset + 4);
        $this->writeAll(pack('v', $version));
        $this->seek($offset + 14);
        $this->writeAll($sizePatch);
        if ($extraPatch !== null) {
            $this->seek($offset + 30 + $nameLength);
            $this->writeAll($extraPatch);
        }
        $this->seek($endOffset);

        $this->entries[] = [
            'name' => $name,
            'crc' => $crc,
            'compressedSize' => $compressedSize,
            'uncompressedSize' => $uncompressedSize,
            'offset' => $offset,
            'dosTime' => $dosTime,
            'dosDate' => $dosDate,
            'flags' => $flags,
            'method' => $method,
        ];
    }

    /**
     * Decide the local-header metadata once an entry's final sizes are known.
     *
     * The classic header uses 32-bit sizes; ZIP64 sets them to the 0xFFFFFFFF
     * sentinel and carries both 64-bit sizes in the 20-byte 0x0001 extra field
     * (the reserved growth-hint space is rewritten in place). Pure, so it can
     * be unit-tested with synthetic sizes without writing 4 GiB.
     *
     * @return array{0:int, 1:bool, 2:string, 3:?string} [version, zip64, sizePatch, extraPatch]
     */
    public static function localHeaderMetadata(int $crc, int $compressedSize, int $uncompressedSize): array
    {
        $zip64 = $compressedSize > self::UINT32_MAX || $uncompressedSize > self::UINT32_MAX;
        $version = $zip64 ? self::VERSION_ZIP64 : self::VERSION_CLASSIC;

        if ($zip64) {
            // Local ZIP64 headers MUST carry BOTH 64-bit sizes together.
            return [
                $version,
                true,
                pack('VVV', $crc, self::UINT32_MAX, self::UINT32_MAX),
                pack('vv', self::ZIP64_EXTRA_FIELD_TAG, 16) . pack('PP', $uncompressedSize, $compressedSize),
            ];
        }

        return [
            $version,
            false,
            pack('VVV', $crc, $compressedSize, $uncompressedSize),
            null,
        ];
    }

    /**
     * Microsoft Open Packaging Growth Hint (extra field 0xA220), used as the
     * reserved 20-byte local-header space that becomes the ZIP64 field when
     * needed. Valid for classic entries, exactly matching the ZIP64 size.
     */
    private static function packGrowthHint(): string
    {
        // tag + TSize(Sig+PadVal+Padding) + Sig(0xA028) + PadVal + 12 null bytes.
        return pack('vvvv', 0xA220, 0x0010, 0xA028, 0x000C) . str_repeat("\0", 12);
    }

    /**
     * Write the central directory (with ZIP64 records when required) and the
     * end-of-central-directory records.
     */
    public function finish(): void
    {
        $this->assertOpen();

        $centralDirectoryOffset = $this->position();
        $entryCount = count($this->entries);

        foreach ($this->entries as $entry) {
            $nameLength = strlen($entry['name']);
            // Per the ZIP spec, each field is set to its 0xFFFFFFFF sentinel and
            // added to the ZIP64 extra field ONLY when that field overflows.
            $ucsOverflow = $entry['uncompressedSize'] > self::UINT32_MAX;
            $csOverflow = $entry['compressedSize'] > self::UINT32_MAX;
            $offsetOverflow = $entry['offset'] > self::UINT32_MAX;

            $cdExtra = '';
            $cdExtraLength = 0;
            if ($ucsOverflow || $csOverflow || $offsetOverflow) {
                $fields = '';
                $fieldSize = 0;
                if ($ucsOverflow) {
                    $fields .= pack('P', $entry['uncompressedSize']);
                    $fieldSize += 8;
                }
                if ($csOverflow) {
                    $fields .= pack('P', $entry['compressedSize']);
                    $fieldSize += 8;
                }
                if ($offsetOverflow) {
                    $fields .= pack('P', $entry['offset']);
                    $fieldSize += 8;
                }
                $cdExtra = pack('vv', self::ZIP64_EXTRA_FIELD_TAG, $fieldSize) . $fields;
                $cdExtraLength = strlen($cdExtra);
            }

            $version = $ucsOverflow || $csOverflow ? self::VERSION_ZIP64 : self::VERSION_CLASSIC;

            $header = pack(
                'VvvvvvvVVVvvvvvVV',
                self::CENTRAL_DIRECTORY_SIGNATURE,
                $version, // version made by
                $version, // version needed to extract
                $entry['flags'],
                $entry['method'],
                $entry['dosTime'],
                $entry['dosDate'],
                $entry['crc'],
                $csOverflow ? self::UINT32_MAX : $entry['compressedSize'],
                $ucsOverflow ? self::UINT32_MAX : $entry['uncompressedSize'],
                $nameLength,
                $cdExtraLength,
                0, // file comment length
                0, // disk number start
                0, // internal file attributes
                0, // external file attributes
                $offsetOverflow ? self::UINT32_MAX : $entry['offset'],
            );

            $this->writeAll($header);
            $this->writeAll($entry['name']);
            if ($cdExtra !== '') {
                $this->writeAll($cdExtra);
            }
        }

        $centralDirectoryEnd = $this->position();
        $centralDirectorySize = $centralDirectoryEnd - $centralDirectoryOffset;

        $zip64EocdNeeded =
            $entryCount > self::UINT16_MAX
            || $centralDirectoryOffset > self::UINT32_MAX
            || $centralDirectorySize > self::UINT32_MAX;

        if ($zip64EocdNeeded) {
            $zip64EocdOffset = $this->position();
            $zip64Eocd = pack(
                'VPvvVVPPPP',
                self::ZIP64_END_OF_CENTRAL_DIRECTORY_SIGNATURE,
                44, // size of record excluding signature and size fields
                self::VERSION_ZIP64, // version made by
                self::VERSION_ZIP64, // version needed to extract
                0, // number of this disk
                0, // disk with central directory
                $entryCount,
                $entryCount,
                $centralDirectorySize,
                $centralDirectoryOffset,
            );
            $this->writeAll($zip64Eocd);

            $locator = pack(
                'VVPV',
                self::ZIP64_END_OF_CENTRAL_DIRECTORY_LOCATOR_SIGNATURE,
                0, // disk with the ZIP64 EOCD
                $zip64EocdOffset,
                1, // total number of disks
            );
            $this->writeAll($locator);

            $eocd = pack(
                'VvvvvVVv',
                self::END_OF_CENTRAL_DIRECTORY_SIGNATURE,
                self::UINT16_MAX, // number of this disk
                self::UINT16_MAX, // disk with central directory
                self::UINT16_MAX, // entries on this disk
                self::UINT16_MAX, // total entries
                self::UINT32_MAX, // central directory size
                self::UINT32_MAX, // central directory offset
                0, // ZIP comment length
            );
        } else {
            $eocd = pack(
                'VvvvvVVv',
                self::END_OF_CENTRAL_DIRECTORY_SIGNATURE,
                0, // number of this disk
                0, // disk with central directory
                $entryCount,
                $entryCount,
                $centralDirectorySize,
                $centralDirectoryOffset,
                0, // ZIP comment length
            );
        }

        $this->writeAll($eocd);
        fflush($this->output);

        $this->finished = true;
    }

    /**
     * @return resource
     */
    public function getStream()
    {
        return $this->output;
    }

    private function assertOpen(): void
    {
        if ($this->finished) {
            throw new WriteException('ZIP archive has already been finished');
        }
    }

    private function validateName(string $name): void
    {
        if ($name === '') {
            throw new WriteException('ZIP entry name cannot be empty');
        }

        if (str_contains($name, "\0")) {
            throw new WriteException('ZIP entry name cannot contain NUL bytes');
        }
    }

    private function seek(int $offset): void
    {
        if (fseek($this->output, $offset, SEEK_SET) !== 0) {
            throw new WriteException('Unable to seek in ZIP output stream');
        }
    }

    private function position(): int
    {
        $position = ftell($this->output);
        if ($position === false) {
            throw new WriteException('Unable to determine ZIP output position');
        }

        return $position;
    }

    private function writeAll(string $data): void
    {
        $length = strlen($data);
        $offset = 0;

        while ($offset < $length) {
            $written = fwrite($this->output, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new WriteException('Unable to write ZIP output');
            }

            $offset += $written;
        }
    }

    /**
     * @return array{int, int}
     */
    private static function dosDateTime(DateTimeInterface $dateTime): array
    {
        $year = (int) $dateTime->format('Y');
        $month = (int) $dateTime->format('n');
        $day = (int) $dateTime->format('j');
        $hour = (int) $dateTime->format('G');
        $minute = (int) $dateTime->format('i');
        $second = (int) $dateTime->format('s');

        // DOS timestamps can represent years from 1980 through 2107.
        $year = max(1980, min(2107, $year));

        $dosTime = ($hour << 11) | ($minute << 5) | intdiv($second, 2);

        $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;

        return [$dosTime, $dosDate];
    }
}
