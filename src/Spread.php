<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTime;
use ZipArchive;
use Exception;
use Generator;
use RuntimeException;

/**
 * Static utility methods shared by readers and writers.
 */
class Spread
{
    /**
     * @return string
     */
    public static function getTempFilename(): string
    {
        $result = tempnam(sys_get_temp_dir(), 'BSH');
        if ($result === false) {
            throw new Exception("Unable to create temp file");
        }
        return $result;
    }

    /**
     * Determine format by inspecting raw bytes.
     * ZIP magic = check mimetype entry for ODS, otherwise XLSX. Non-ZIP = CSV.
     * Returns 'ods', 'xlsx', or 'csv'.
     */
    public static function getExtensionForContent(string $contents): string
    {
        // ZIP magic = PK \x03 \x04
        if (str_starts_with($contents, "\x50\x4B\x03\x04")) {
            // ZIP file — check for ODS mimetype marker
            if (str_contains($contents, 'application/vnd.oasis.opendocument.spreadsheet')) {
                return 'ods';
            }
            return 'xlsx';
        }
        return 'csv';
    }

    /**
     * Uses php://temp with a 4 MB memory cap before spilling to disk.
     *
     * @return resource
     */
    public static function getMaxMemTempStream()
    {
        $mb = 4;
        $stream = fopen('php://temp/maxmemory:' . ($mb * 1024 * 1024), 'r+');
        if (!$stream) {
            throw new RuntimeException("Failed to open stream");
        }
        return $stream;
    }

    /**
     * @return resource
     */
    public static function getOutputStream(string $filename = 'php://output')
    {
        $stream = fopen($filename, 'w');
        if (!$stream) {
            throw new RuntimeException("Failed to open stream");
        }
        return $stream;
    }

    /**
     * @return resource
     */
    public static function getInputStream(string $filename)
    {
        $stream = fopen($filename, 'r');
        if (!$stream) {
            throw new RuntimeException("Failed to open stream");
        }
        return $stream;
    }

    public static function ensureExtension(string $filename, string $ext): string
    {
        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($fileExt !== strtolower($ext)) {
            $filename .= ".$ext";
        }
        return $filename;
    }

    public static function outputHeaders(string $contentType, string $filename, ?int $size = null): void
    {
        if (headers_sent()) {
            throw new RuntimeException("Headers already sent");
        }

        header('Content-Type: ' . $contentType);
        header(
            'Content-Disposition: attachment; ' .
                'filename="' . rawurlencode($filename) . '"; ' .
                'filename*=UTF-8\'\'' . rawurlencode($filename)
        );
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        if ($size !== null && $size > 0) {
            header('Content-Length: ' . $size);
        }
    }

    /**
     * @param string $lower
     * @param string $upper
     * @return Generator<string>
     */
    public static function columnRange(string $lower = 'A', string $upper = 'ZZ'): Generator
    {
        $start = self::columnIndex($lower);
        $end = self::columnIndex($upper);
        for ($i = $start; $i <= $end; $i++) {
            yield self::columnLetter($i);
        }
    }

    /**
     * Column letter to index. A = 1, AA = 27, etc.
     */
    public static function columnIndex(string $letter): int
    {
        $length = strlen($letter);
        $index = 0;
        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord(strtoupper($letter[$i])) - 64);
        }
        return $index;
    }

    /**
     * Convert Excel serial date to a formatted string.
     *
     * Handles the 1900 date system including the Lotus 1-2-3 leap year bug.
     *
     * @link https://docs.sheetjs.com/docs/csf/features/dates/#1904-and-1900-date-systems
     */
    public static function excelDateToString(float|string $value, ?string $format = null, bool $is1904 = false): string
    {
        if (!is_numeric($value)) {
            return $value;
        }

        $floatValue = is_string($value) ? floatval($value) : $value;
        $value = (string) $value;

        if ($format === null) {
            if ($floatValue < 1 && $floatValue > 0) {
                $format = 'H:i:s';
            } else {
                $format = str_contains($value, '.') ? 'Y-m-d H:i:s' : 'Y-m-d';
            }
        }

        /** @var array<string, \DateTime> */
        static $base1904 = [];
        /** @var array<string, \DateTime> */
        static $base1899_31 = [];
        /** @var array<string, \DateTime> */
        static $base1899_30 = [];
        /** @var array<string, int> */
        static $driftThresholds = [];

        $tz = date_default_timezone_get();

        if (!isset($base1904[$tz])) {
            $base1904[$tz] = new DateTime('1904-01-01');
            $base1899_31[$tz] = new DateTime('1899-12-31');
            $base1899_30[$tz] = new DateTime('1899-12-30');
            // Cache the strtotime result for the drift threshold in this timezone
            $driftThresholds[$tz] = (int) strtotime('1582-10-15');
        }

        if ($is1904) {
            $dt = clone $base1904[$tz];
        } else {
            // Excel day 60 = Feb 29 1900 (non-existent) — Lotus 1-2-3 bug compensation
            $dt = clone ($floatValue < 60 && $floatValue > 0 ? $base1899_31[$tz] : $base1899_30[$tz]);
        }

        $days = (int) floor($floatValue);
        $partDay = fmod($floatValue, 1);

        if ($days >= 0) {
            $days = '+' . $days;
        }
        $interval = "$days days";

        $dt->modify($interval);

        if ($partDay > 0) {
            $totalSeconds = (int) round($partDay * 86400);
            $hours = intdiv($totalSeconds, 3600);
            $totalSeconds %= 3600;
            $minutes = intdiv($totalSeconds, 60);
            $seconds = $totalSeconds % 60;
            $dt->setTime($hours, $minutes, $seconds);
        }

        // Handle Julian to Gregorian calendar drift (approx 1 day every 128 years).
        // This adjustment is for historical dates before the Gregorian calendar transition (1582-10-15).
        // It treats Excel numbers as representing historical Julian dates.
        if ($dt->getTimestamp() < $driftThresholds[$tz]) {
            $year = (int) $dt->format('Y');
            // Cumulative drift formula: 10 days in 1582, increasing by 1 every century not divisible by 400.
            $drift = floor($year / 100) - floor($year / 400) - 2;
            if ($drift > 0) {
                $dt->modify("- $drift days");
            }
        }

        return $dt->format($format);
    }

    /**
     * Convert a DateTime to an Excel serial date number.
     */
    public static function dateToExcel(\DateTimeInterface $dt, bool $is1904 = false): float
    {
        /** @var array<string, \DateTime> */
        static $base1904 = [];
        /** @var array<string, \DateTime> */
        static $base1899 = [];
        /** @var array<string, int> */
        static $driftThresholds = [];

        $tz = date_default_timezone_get();

        if (!isset($base1904[$tz])) {
            $base1904[$tz] = new DateTime('1904-01-01');
            $base1899[$tz] = new DateTime('1899-12-30');
            $driftThresholds[$tz] = (int) strtotime('1582-10-15');
        }

        $base = $is1904 ? $base1904[$tz] : $base1899[$tz];

        // Ensure we are diffing against a DateTime object
        if (!$dt instanceof DateTime) {
            $dt = DateTime::createFromInterface($dt);
        }

        $diff = $base->diff($dt);
        $days = (int) $diff->format('%r%a');

        if (!$is1904) {
            // Adjust for Lotus 1-2-3 leap year bug
            $ymd = $dt->format('Y-m-d');
            if ($ymd >= '1900-01-01' && $ymd <= '1900-02-28') {
                $days -= 1;
            }
        }

        $timeFraction = ($dt->format('H') * 3600 + $dt->format('i') * 60 + $dt->format('s')) / 86400;
        $serial = $days + $timeFraction;

        // Inverse Julian-to-Gregorian correction for historical dates
        if ($dt->getTimestamp() < $driftThresholds[$tz]) {
            $year = (int) $dt->format('Y');
            $drift = floor($year / 100) - floor($year / 400) - 2;
            if ($drift > 0) {
                $serial += $drift;
            }
        }

        return $serial;
    }

    /**
     * Read properties from any supported file (xlsx, ods, csv).
     *
     * @return array{format:string, meta: array{creator?: string, title?: string, subject?: string, keywords?: string, description?: string, category?: string, language?: string}, sheets:string[]}
     */
    public static function getProperties(string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $arr = [
            'format' => $ext,
            'meta' => [],
            'sheets' => [],
        ];

        if ($ext === 'xlsx') {
            $zip = new ZipArchive();
            $result = $zip->open($filename);
            if ($result !== true) {
                throw new Exception("Failed to open zip archive, code: " . self::zipError($result));
            }

            $props = self::zipGetData($zip, 'docProps/core.xml');
            if ($props) {
                $matches = [];
                $res = preg_match_all("/<(?:dc|cp):([\w]*)>(.*)<\/(?:dc|cp):([\w]*)>/", $props, $matches);
                if ($res !== false && $res > 0) {
                    $combine = array_combine($matches[1], $matches[2]);
                    if ($combine) {
                        foreach (['title', 'subject', 'creator', 'description'] as $key) {
                            if (isset($combine[$key])) {
                                $arr['meta'][$key] = $combine[$key];
                            }
                        }
                    }
                }
            }

            $arr['sheets'] = self::getXlsxSheetNames($zip);
            $zip->close();
        } elseif ($ext === 'ods') {
            $zip = new ZipArchive();
            $result = $zip->open($filename);
            if ($result !== true) {
                throw new Exception("Failed to open zip archive, code: " . self::zipError($result));
            }

            $meta = self::zipGetData($zip, 'meta.xml');
            if ($meta) {
                $xml = self::safeXml($meta);
                $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                $xml->registerXPathNamespace('meta', 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0');

                $title = $xml->xpath('//dc:title');
                $creator = $xml->xpath('//dc:creator');
                $description = $xml->xpath('//dc:description');
                $arr['meta']['title'] = $title ? (string)$title[0] : '';
                $arr['meta']['creator'] = $creator ? (string)$creator[0] : '';
                $arr['meta']['description'] = $description ? (string)$description[0] : '';
            }

            $arr['sheets'] = self::getOdsSheetNames($zip);
            $zip->close();
        }
        // CSV has no embedded metadata; return defaults

        /** @var array{format:lowercase-string, meta: array{creator?: string, title?: string, subject?: string, keywords?: string, description?: string, category?: string, language?: string}, sheets:array<string>} $arr */
        return $arr;
    }

    /**
     * List sheet names from an XLSX or ODS file.
     *
     * @return string[]
     */
    public static function getSheetNames(string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $zip = new ZipArchive();
        $result = $zip->open($filename);
        if ($result !== true) {
            throw new Exception("Failed to open zip archive, code: " . self::zipError((int)$result));
        }
        $names = match ($ext) {
            'xlsx' => self::getXlsxSheetNames($zip),
            'ods' => self::getOdsSheetNames($zip),
            default => [],
        };
        $zip->close();

        return $names;
    }

    /**
     * @return string[]
     */
    private static function getXlsxSheetNames(ZipArchive $zip): array
    {
        $wbData = self::zipGetData($zip, 'xl/workbook.xml');
        if (!$wbData) {
            return [];
        }

        $xml = self::safeXml($wbData);
        $names = [];
        foreach ($xml->sheets->sheet as $sheet) {
            $names[] = (string)$sheet->attributes()->name;
        }
        return $names;
    }

    /**
     * @return string[]
     */
    private static function getOdsSheetNames(ZipArchive $zip): array
    {
        $contentData = self::zipGetData($zip, 'content.xml');
        if (!$contentData) {
            return [];
        }

        $xml = self::safeXml($contentData);
        $nsTable = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
        $nsOffice = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
        $xml->registerXPathNamespace('table', $nsTable);
        $xml->registerXPathNamespace('office', $nsOffice);

        $tables = $xml->xpath('//office:body/office:spreadsheet/table:table');
        if (!$tables) {
            return [];
        }

        $names = [];
        foreach ($tables as $table) {
            $names[] = (string)$table->attributes($nsTable)->name;
        }
        return $names;
    }

    /**
     * Column index to letter. 1 = A, 27 = AA, etc.
     */
    public static function columnLetter(int $index): string
    {
        /** @var array<int, string> $cache */
        static $cache = [];
        if (isset($cache[$index])) {
            return $cache[$index];
        }

        $n = $index - 1;
        for ($r = ""; $n >= 0; $n = intval($n / 26) - 1) {
            $r = chr($n % 26 + 0x41) . $r;
        }

        $cache[$index] = $r;
        return $r;
    }

    // -- Zip helpers --

    public static function zipError(int $code): string
    {
        return match ($code) {
            ZipArchive::ER_EXISTS => 'File already exists.',
            ZipArchive::ER_INCONS => 'Zip archive inconsistent.',
            ZipArchive::ER_INVAL => 'Invalid argument.',
            ZipArchive::ER_MEMORY => 'Malloc failure.',
            ZipArchive::ER_NOENT => 'No such file.',
            ZipArchive::ER_NOZIP => 'Not a zip archive.',
            ZipArchive::ER_OPEN => 'Can\'t open file.',
            ZipArchive::ER_READ => 'Read error.',
            ZipArchive::ER_SEEK => 'Seek error.',
            default => 'Unknown error code ' . $code . '.',
        };
    }

    public static function zipGetData(ZipArchive $zip, string $name): ?string
    {
        $idx = $zip->locateName($name);
        if ($idx !== false) {
            $result = $zip->getFromIndex($idx);
            if ($result !== false) {
                return $result;
            }
        }
        return null;
    }

    /**
     * Parse XML string into SimpleXMLElement with LIBXML_NONET to prevent
     * external entity resolution (XXE/SSRF mitigation).
     */
    public static function safeXml(string $data): \SimpleXMLElement
    {
        return new \SimpleXMLElement($data, LIBXML_NONET);
    }

    /**
     * Build a cell address like "A1" or "$A$1".
     */
    public static function cellAddress(int $row = 0, int $column = 0, bool $absolute = false): string
    {
        $r = self::columnLetter($column + 1);
        if ($absolute) {
            return '$' . $r . '$' . ($row + 1);
        }
        return $r . ($row + 1);
    }

    /**
     * Escape string for XML, stripping control chars (\x00-\x1F) except tab, LF, CR.
     */
    public static function escapeXml(string $str): string
    {
        if ($str === '') {
            return '';
        }
        if (strpbrk($str, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F") !== false) {
            $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str) ?? $str;
        }
        if (strpbrk($str, '&<>"\'') !== false) {
            return htmlspecialchars($str, ENT_XML1 | ENT_COMPAT, 'UTF-8');
        }
        return $str;
    }

    /**
     * Escape string for XML attributes (includes quotes)
     */
    public static function escapeXmlAttr(string $str): string
    {
        if ($str === '') {
            return '';
        }
        if (strpbrk($str, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F") !== false) {
            $str = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str) ?? $str;
        }
        if (strpbrk($str, '&<>"\'') !== false) {
            return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $str);
        }
        return $str;
    }
}
