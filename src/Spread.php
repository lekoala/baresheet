<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use LeKoala\Baresheet\Exception\BaresheetException;
use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Exception\MissingColumnException;
use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Value\TimeValue;
use LogicException;
use ZipArchive;

/**
 * Static utility methods shared by readers and writers.
 */
class Spread
{
    private const MAX_DATE_CACHE_SIZE = 10_000;

    // Proleptic-Gregorian day numbers (civilToDays) of fixed calendar anchors,
    // precomputed so the hot serial conversions never re-derive them per cell.
    private const DAYS_1899_12_30 = -25_569;
    private const DAYS_1899_12_31 = -25_568;
    private const DAYS_1904_01_01 = -24_107;
    private const DAYS_1582_10_15 = -141_427;

    /**
     * @return string
     */
    public static function getTempFilename(): string
    {
        $result = tempnam(sys_get_temp_dir(), 'BSH');
        if ($result === false) {
            throw new BaresheetException('Unable to create temp file');
        }
        return $result;
    }

    /**
     * @throws InvalidDocumentException
     */
    public static function isSafePath(string $path): void
    {
        if (preg_match('/^([a-zA-Z0-9+\-.]+):\/\//', $path, $matches)) {
            $scheme = strtolower($matches[1]);
            $allowedSchemes = ['php', 'file', 'zip'];
            if (!in_array($scheme, $allowedSchemes, true)) {
                throw new InvalidDocumentException('Invalid stream wrapper: ' . $scheme . ' is not allowed');
            }
        }

        if (str_contains(strtolower($path), 'phar://')) {
            throw new InvalidDocumentException('Phar deserialization is not allowed');
        }

        // "php://filter/resource=..." (or "/read=..." etc.) chains an arbitrary inner
        // resource behind the php:// scheme, which defeats the scheme allow-list above.
        // Only the plain data streams are legitimate targets for a filename argument.
        if (preg_match('/^php:\/\//i', $path)) {
            $allowedPhpPaths = [
                'php://input',
                'php://output',
                'php://temp',
                'php://memory',
                'php://stdin',
                'php://stdout',
                'php://stderr',
            ];
            if (!in_array(strtolower($path), $allowedPhpPaths, true)) {
                throw new InvalidDocumentException("Invalid php:// stream: {$path} is not allowed");
            }
        }
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
            throw new BaresheetException('Failed to open stream');
        }
        return $stream;
    }

    /**
     * @return resource
     * @throws WriteException
     */
    public static function getOutputStream(string $filename = 'php://output')
    {
        self::isSafePath($filename);
        $stream = @fopen($filename, 'w');
        if (!$stream) {
            throw new WriteException('Failed to open stream');
        }
        return $stream;
    }

    /**
     * @return resource
     * @throws InvalidDocumentException
     */
    public static function getInputStream(string $filename)
    {
        self::isSafePath($filename);
        $stream = @fopen($filename, 'r');
        if (!$stream) {
            throw new InvalidDocumentException('Failed to open stream');
        }
        return $stream;
    }

    public static function ensureExtension(string $filename, string $ext): string
    {
        $fileExt = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($fileExt !== strtolower($ext)) {
            $filename .= ".{$ext}";
        }
        return $filename;
    }

    public static function outputHeaders(string $contentType, string $filename, ?int $size = null): void
    {
        if (headers_sent()) {
            throw new LogicException('Headers already sent');
        }

        header('Content-Type: ' . $contentType);
        header(
            'Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\''
                . rawurlencode($filename),
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
        /** @var array<string, int> $cache */
        static $cache = [];
        if (isset($cache[$letter])) {
            return $cache[$letter];
        }

        $length = strlen($letter);
        $index = 0;
        for ($i = 0; $i < $length; $i++) {
            $index = ($index * 26) + (ord(strtoupper($letter[$i])) - 64);
        }

        $cache[$letter] = $index;
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

        $cacheKey = $floatValue . '|' . $format . '|' . ($is1904 ? '1' : '0');

        /** @var array<string, string> */
        static $dateCache = [];

        if (isset($dateCache[$cacheKey])) {
            return $dateCache[$cacheKey];
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
        $interval = "{$days} days";

        $dt->modify($interval);

        if ($partDay > 0) {
            $totalSeconds = (int) round($partDay * 86_400);
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
                $dt->modify("- {$drift} days");
            }
        }

        $result = $dt->format($format);

        if (count($dateCache) >= self::MAX_DATE_CACHE_SIZE) {
            $dateCache = [];
        }

        $dateCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Convert a DateTimeInterface to an Excel serial date number.
     *
     * The conversion reasons purely on the DateTimeInterface's own civil calendar
     * components (as displayed in its timezone), never on absolute time:
     * spreadsheet dates carry no timezone semantics, so no timezone
     * conversion is ever performed. The default PHP timezone is never read.
     *
     * The Excel quirks are preserved: the 1900/1904 date systems, the Lotus
     * 1-2-3 fake 1900-02-29 and the pre-Gregorian (Julian) day drift.
     */
    public static function dateToExcel(DateTimeInterface $dt, bool $is1904 = false): float
    {
        $year = (int) $dt->format('Y');
        $month = (int) $dt->format('n');
        $day = (int) $dt->format('j');
        $civilDays = self::civilToDays($year, $month, $day);

        if ($is1904) {
            $days = $civilDays - self::DAYS_1904_01_01;
        } else {
            $days = $civilDays - self::DAYS_1899_12_30;

            // Lotus 1-2-3 leap bug: 1900-01-01..1900-02-28 are shifted down by one
            // because Excel wrongly treats 1900 as a leap year (day 60).
            $ymd = $dt->format('Y-m-d');
            if ($ymd >= '1900-01-01' && $ymd <= '1900-02-28') {
                $days -= 1;
            }
        }

        // Inverse Julian-to-Gregorian correction for historical dates
        if ($civilDays < self::DAYS_1582_10_15) {
            $drift = (int) (floor($year / 100) - floor($year / 400) - 2);
            if ($drift > 0) {
                $days += $drift;
            }
        }

        $hour = (int) $dt->format('G');
        $minute = (int) $dt->format('i');
        $second = (int) $dt->format('s');
        $microsecond = (int) $dt->format('u');
        $timeFraction = (($hour * 3600) + ($minute * 60) + $second + ($microsecond / 1_000_000)) / 86_400;

        return $days + $timeFraction;
    }

    private static ?DateTimeZone $utc = null;

    /**
     * Shared neutral timezone for spreadsheet civil dates.
     */
    public static function utc(): DateTimeZone
    {
        return self::$utc ??= new DateTimeZone('UTC');
    }

    /**
     * Convert an Excel serial to a UTC DateTimeImmutable carrying the civil
     * spreadsheet components. Deterministic at microsecond precision and
     * independent of the default PHP timezone.
     */
    public static function excelDateToImmutable(float|string $value, bool $is1904 = false): DateTimeImmutable
    {
        $c = self::excelSerialToComponents($value, $is1904);

        return new DateTimeImmutable(sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d.%06d',
            $c['year'],
            $c['month'],
            $c['day'],
            $c['hour'],
            $c['minute'],
            $c['second'],
            $c['microsecond'],
        ), self::utc());
    }

    /**
     * Strictly parse an ISO-8601 date/datetime (as stored in OOXML t="d"
     * cells) into a UTC-neutral DateTimeImmutable.
     *
     * Only a narrow set of ISO forms is accepted, and the string must be fully
     * consumed: out-of-range dates and trailing garbage are rejected rather
     * than normalized. The result keeps the source's civil components but
     * carries no timezone — an embedded offset is used for validation only.
     *
     * @return DateTimeImmutable|null Null when $value is not a valid ISO date.
     */
    public static function parseIsoDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        // Narrow full-match shape: date[ T time][.fraction][offset]. The
        // offset is validated as a form only (Z or ±HH:MM); createFromFormat
        // below handles the calendar/range validation of the civil part.
        if (
            preg_match(
                '/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?)?(Z|[+-]\d{2}:\d{2})?$/D',
                $value,
                $m,
                PREG_UNMATCHED_AS_NULL,
            ) !== 1
        ) {
            return null;
        }

        if ($m[4] !== null && $m[4] !== 'Z') {
            [$offsetHours, $offsetMinutes] = array_map('intval', explode(':', substr($m[4], 1)));
            // XML Schema timezone offsets range from -14:00 to +14:00, with
            // minutes forced to zero at the ±14 hour boundary.
            if ($offsetHours > 14 || $offsetMinutes > 59 || ($offsetHours === 14 && $offsetMinutes !== 0)) {
                return null;
            }
        }

        $core = $m[1];
        $format = '!Y-m-d';
        if ($m[2] !== null) {
            $core .= 'T' . $m[2];
            if ($m[3] !== null) {
                $core .= '.' . str_pad($m[3], 6, '0');
                $format = '!Y-m-d\TH:i:s.u';
            } else {
                $format = '!Y-m-d\TH:i:s';
            }
        }

        $date = DateTimeImmutable::createFromFormat($format, $core);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return new DateTimeImmutable($date->format('Y-m-d H:i:s.u'), self::utc());
    }

    /**
     * Canonical time-of-day string ("HH:MM:SS[.ffffff]").
     *
     * @internal
     */
    public static function formatTimeComponents(int $hour, int $minute, int $second, int $microsecond = 0): string
    {
        $result = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
        if ($microsecond > 0) {
            $result .= '.' . str_pad((string) $microsecond, 6, '0', STR_PAD_LEFT);
        }
        return $result;
    }

    /**
     * Convert an Excel day fraction into a time of day.
     */
    public static function excelTimeToTimeValue(float|string $value): TimeValue
    {
        $clock = self::excelTimeToClock($value);
        return new TimeValue($clock['hour'], $clock['minute'], $clock['second'], $clock['microsecond']);
    }

    /**
     * Convert an Excel day fraction into a canonical time-of-day string.
     */
    public static function excelTimeToString(float|string $value): string
    {
        $clock = self::excelTimeToClock($value);
        return self::formatTimeComponents($clock['hour'], $clock['minute'], $clock['second'], $clock['microsecond']);
    }

    /**
     * Decompose an Excel day fraction into clock components (within a day).
     *
     * @return array{hour: int, minute: int, second: int, microsecond: int}
     */
    private static function excelTimeToClock(float|string $value): array
    {
        $fraction = is_string($value) ? (float) $value : $value;
        $fraction -= floor($fraction);
        $clock = self::clockFromSecondsFloat($fraction * 86_400);
        return [
            'hour' => $clock['hour'],
            'minute' => $clock['minute'],
            'second' => $clock['second'],
            'microsecond' => $clock['microsecond'],
        ];
    }

    /**
     * Convert a time of day into an Excel day fraction.
     */
    public static function timeToExcel(TimeValue $time): float
    {
        return (
            (($time->hour * 3600.0) + ($time->minute * 60) + $time->second + ($time->microsecond / 1_000_000)) / 86_400
        );
    }

    /**
     * Days since 1970-01-01 in the proleptic Gregorian calendar, using pure
     * integer arithmetic. No timezone, no extension, no Unix timestamp.
     *
     * Algorithm from Howard Hinnant's chrono civil calendar; intdiv()
     * truncates toward zero exactly like C++ integer division.
     */
    private static function civilToDays(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year, 400);
        $yoe = $year - ($era * 400);
        $doy = intdiv((153 * ($month + ($month > 2 ? -3 : 9))) + 2, 5) + $day - 1;
        $doe = ($yoe * 365) + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;
        return ($era * 146_097) + $doe - 719_468;
    }

    /**
     * Inverse of {@see self::civilToDays()}.
     *
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private static function daysToCivil(int $days): array
    {
        $z = $days + 719_468;
        $era = intdiv($z, 146_097);
        $doe = $z - ($era * 146_097);
        $yoe = intdiv($doe - intdiv($doe, 1_460) + intdiv($doe, 36_524) - intdiv($doe, 146_096), 365);
        $year = $yoe + ($era * 400);
        $doy = $doe - ((365 * $yoe) + intdiv($yoe, 4) - intdiv($yoe, 100));
        $monthIndex = intdiv((5 * $doy) + 2, 153);
        $day = $doy - intdiv((153 * $monthIndex) + 2, 5) + 1;
        $month = $monthIndex + ($monthIndex < 10 ? 3 : -9);
        return [$year + ($month <= 2 ? 1 : 0), $month, $day];
    }

    /**
     * @return array{year:int, month:int, day:int, hour:int, minute:int, second:int, microsecond:int}
     */
    private static function excelSerialToComponents(float|string $value, bool $is1904): array
    {
        $floatValue = is_string($value) ? (float) $value : $value;
        $days = (int) floor($floatValue);
        $fraction = $floatValue - $days;

        if ($is1904) {
            $epoch = self::DAYS_1904_01_01;
        } else {
            // Serial 0 = 1899-12-30. Serials 1-59 are anchored to 1899-12-31
            // because Excel skips the non-existent 1900-02-29 (Lotus bug);
            // serials 60+ fall back onto 1899-12-30, so day 60 collapses to
            // 1900-02-28 exactly like Excel displays it.
            $epoch = $days > 0 && $days < 60 ? self::DAYS_1899_12_31 : self::DAYS_1899_12_30;
        }
        $totalDays = $epoch + $days;

        // Pre-Gregorian correction: serials are treated as Julian dates before
        // the 1582-10-15 Gregorian transition, mirroring excelDateToString().
        if ($totalDays < self::DAYS_1582_10_15) {
            $year = self::daysToCivil($totalDays)[0];
            $drift = (int) (floor($year / 100) - floor($year / 400) - 2);
            if ($drift > 0) {
                $totalDays -= $drift;
            }
        }

        [$year, $month, $day] = self::daysToCivil($totalDays);

        if ($fraction === 0.0) {
            // Fast path: midnight date-only cells avoid the full clock math.
            $clock = ['hour' => 0, 'minute' => 0, 'second' => 0, 'microsecond' => 0, 'carryDay' => false];
        } else {
            $clock = self::clockFromSecondsFloat($fraction * 86_400);
            if ($clock['carryDay']) {
                $totalDays++;
                [$year, $month, $day] = self::daysToCivil($totalDays);
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'hour' => $clock['hour'],
            'minute' => $clock['minute'],
            'second' => $clock['second'],
            'microsecond' => $clock['microsecond'],
        ];
    }

    /**
     * Convert a day fraction (seconds since midnight) into a clock time.
     *
     * The conversion works in two steps — whole seconds first (via floor),
     * then the microsecond remainder — because a direct fraction
     * * 86_400_000_000 amplifies the double error up to a full second.
     *
     * A fraction of exactly 86400 seconds (e.g. 86399.9999996 rounding to
     * a whole microsecond) carries into the next day: carryDay is set and the
     * clock wraps to 00:00:00. A fraction of 86399.6 (23:59:59.600000) does
     * NOT carry — it is a time within the day.
     *
     * @return array{hour:int, minute:int, second:int, microsecond:int, carryDay:bool}
     */
    private static function clockFromSecondsFloat(float $secondsFloat): array
    {
        $seconds = (int) floor($secondsFloat);
        $microsecond = (int) round(($secondsFloat - $seconds) * 1_000_000);
        if ($microsecond >= 1_000_000) {
            $seconds++;
            $microsecond = 0;
        }

        $carryDay = false;
        if ($seconds >= 86_400) {
            $seconds -= 86_400;
            $carryDay = true;
        }

        $hour = intdiv($seconds, 3_600);
        $seconds %= 3_600;
        $minute = intdiv($seconds, 60);
        $second = $seconds % 60;
        return [
            'hour' => $hour,
            'minute' => $minute,
            'second' => $second,
            'microsecond' => $microsecond,
            'carryDay' => $carryDay,
        ];
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
                throw new InvalidDocumentException('Failed to open zip archive, code: ' . self::zipError($result));
            }

            $props = self::zipGetData($zip, 'docProps/core.xml');
            if ($props) {
                $xml = self::safeXml($props);
                $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                $xml->registerXPathNamespace(
                    'cp',
                    'http://schemas.openxmlformats.org/package/2006/metadata/core-properties',
                );

                $xpathMap = [
                    'title' => '//dc:title',
                    'subject' => '//dc:subject',
                    'creator' => '//dc:creator',
                    'keywords' => '//cp:keywords',
                    'description' => '//dc:description',
                    'category' => '//cp:category',
                    'language' => '//dc:language',
                ];
                $arr['meta'] = self::extractMeta($xml, $xpathMap);
            }

            $arr['sheets'] = self::getXlsxSheetNames($zip);
            $zip->close();
        } elseif ($ext === 'ods') {
            $zip = new ZipArchive();
            $result = $zip->open($filename);
            if ($result !== true) {
                throw new InvalidDocumentException('Failed to open zip archive, code: ' . self::zipError($result));
            }

            $meta = self::zipGetData($zip, 'meta.xml');
            if ($meta) {
                $xml = self::safeXml($meta);
                $xml->registerXPathNamespace('dc', 'http://purl.org/dc/elements/1.1/');
                $xml->registerXPathNamespace('meta', 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0');

                $xpathMap = [
                    'title' => '//dc:title',
                    'subject' => '//dc:subject',
                    'creator' => '//dc:creator',
                    'keywords' => '//meta:keyword',
                    'description' => '//dc:description',
                    'language' => '//dc:language',
                ];
                $arr['meta'] = self::extractMeta($xml, $xpathMap);
            }

            $arr['sheets'] = self::getOdsSheetNames($zip);
            $zip->close();
        }
        // CSV has no embedded metadata; return defaults

        /** @var array{format:lowercase-string, meta: array{creator?: string, title?: string, subject?: string, keywords?: string, description?: string, category?: string, language?: string}, sheets:array<string>} $arr */
        return $arr;
    }

    /**
     * Extract metadata from an XML document based on a mapping of keys to XPaths.
     *
     * @param \SimpleXMLElement $xml
     * @param array<string, string> $xpathMap Map of metadata key to XPath expression
     * @return array<string, string>
     */
    private static function extractMeta(\SimpleXMLElement $xml, array $xpathMap): array
    {
        $meta = [];
        foreach ($xpathMap as $key => $xpath) {
            $result = $xml->xpath($xpath);
            if ($result) {
                if ($key === 'keywords') {
                    $keywordStrings = array_map(static fn($k) => (string) $k, $result);
                    $meta[$key] = implode(', ', $keywordStrings);
                } else {
                    $meta[$key] = (string) $result[0];
                }
            }
        }
        return $meta;
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
            throw new InvalidDocumentException('Failed to open zip archive, code: ' . self::zipError((int) $result));
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
            $names[] = (string) $sheet->attributes()->name;
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
            $names[] = (string) $table->attributes($nsTable)->name;
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
        for ($r = ''; $n >= 0; $n = intval($n / 26) - 1) {
            $r = chr(($n % 26) + 0x41) . $r;
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

    public static function zipGetData(ZipArchive $zip, string $name, int $maxSize = 50_000_000): ?string
    {
        $idx = $zip->locateName($name);
        if ($idx === false) {
            return null;
        }

        $stat = $zip->statIndex($idx);
        if ($stat === false) {
            return null;
        }

        if ($stat['size'] > $maxSize) {
            throw new InvalidDocumentException("ZIP entry '{$name}' exceeds maximum allowed size ({$maxSize} bytes).");
        }

        $result = $zip->getFromIndex($idx);
        return $result !== false ? $result : null;
    }

    /**
     * Parse XML string into SimpleXMLElement with LIBXML_NONET to prevent
     * external entity resolution (XXE/SSRF mitigation).
     *
     * @throws InvalidDocumentException If the XML can't be parsed.
     */
    public static function safeXml(string $data): \SimpleXMLElement
    {
        // Route libxml warnings through the internal error queue instead of the standard
        // error handler; SimpleXMLElement still throws on failure either way.
        $previous = libxml_use_internal_errors(true);
        try {
            return new \SimpleXMLElement($data, LIBXML_NONET);
        } catch (\Throwable $e) {
            throw new InvalidDocumentException('Invalid XML document', previous: $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
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
     * Strip invalid XML control characters (\x00-\x1F except tab, LF, CR).
     */
    private static function stripControlChars(string $str): string
    {
        if (
            strpbrk(
                $str,
                "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F",
            ) !== false
        ) {
            return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $str) ?? $str;
        }
        return $str;
    }

    /**
     * Escape string for XML, stripping control chars (\x00-\x1F) except tab, LF, CR.
     */
    public static function escapeXml(string $str): string
    {
        if ($str === '') {
            return '';
        }
        // Fast path for common plain-text strings: return early if no XML special chars or invalid control chars exist
        if (
            strpbrk(
                $str,
                "&<>\"'\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F",
            ) === false
        ) {
            return $str;
        }
        $str = self::stripControlChars($str);
        return htmlspecialchars($str, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }

    /**
     * Escape string for XML attributes (includes quotes)
     */
    public static function escapeXmlAttr(string $str): string
    {
        if ($str === '') {
            return '';
        }
        // Fast path for common plain-text strings: return early if no XML special chars or invalid control chars exist
        if (
            strpbrk(
                $str,
                "&<>\"'\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F",
            ) === false
        ) {
            return $str;
        }
        $str = self::stripControlChars($str);
        return str_replace(['&', '<', '>', '"', "'"], ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'], $str);
    }

    /**
     * Validate a sheet name against Excel restrictions.
     *
     * @throws WriteException
     */
    public static function validateSheetName(string $name): string
    {
        if ($name === '') {
            throw new WriteException('Sheet name must not be empty');
        }
        if (mb_strlen($name) > 31) {
            throw new WriteException("Invalid XLSX sheet name: {$name}");
        }
        if (preg_match('~[\\\\/*?:\[\]]~u', $name)) {
            throw new WriteException("Invalid XLSX sheet name: {$name}");
        }
        if (str_starts_with($name, "'")) {
            throw new WriteException("Invalid XLSX sheet name: {$name}");
        }
        if (str_ends_with($name, "'")) {
            throw new WriteException("Invalid XLSX sheet name: {$name}");
        }
        if (strcasecmp($name, 'History') === 0) {
            throw new WriteException("Invalid XLSX sheet name: {$name}");
        }

        return $name;
    }

    /**
     * Reject headers containing the same name more than once. Duplicate headers can't
     * be represented by array_combine() (one of the columns silently disappears) and
     * would make column selection ambiguous.
     *
     * @param string[] $headers
     * @throws InvalidDocumentException
     */
    public static function checkNoDuplicateHeaders(array $headers): void
    {
        $duplicates = array_keys(array_filter(array_count_values($headers), static fn(int $count) => $count > 1));
        if (!empty($duplicates)) {
            throw new InvalidDocumentException('Duplicate header(s) found: ' . implode(', ', $duplicates));
        }
    }

    /**
     * Validate that all required columns are present in the headers.
     *
     * @param string[] $requiredColumns
     * @param string[] $headers
     * @throws MissingColumnException
     */
    public static function checkRequiredColumns(array $requiredColumns, array $headers): void
    {
        if (!empty($requiredColumns)) {
            $missing = array_diff($requiredColumns, $headers);
            if (!empty($missing)) {
                throw new MissingColumnException(array_values($missing));
            }
        }
    }

    /**
     * Decide whether a value should be written as a numeric cell.
     *
     * Native int/float values are always numeric. Strings are classified as numeric
     * only when they are a canonical decimal — no sign (except a leading minus), no
     * leading zeros, no exponent — and have at most 15 significant digits. Excel only
     * guarantees 15 significant digits, so longer digit strings (IDs, EAN codes,
     * administrative or card numbers) must stay text to avoid silent truncation.
     */
    public static function isNumericCellValue(mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }
        if ($value instanceof \Stringable) {
            $value = $value->__toString();
        }
        if (!is_string($value)) {
            return false;
        }

        if (preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $value) !== 1) {
            return false;
        }

        // Count significant digits only: strip the sign and the decimal point, then
        // drop the non-significant leading zeros ("0.0000012300" → "12300"). Trailing
        // zeros are part of the encoded precision and stay counted.
        $digits = str_replace(['-', '.'], '', $value);
        $significantDigits = ltrim($digits, '0');

        return strlen($significantDigits) <= 15;
    }

    /**
     * Deterministically parse a spreadsheet numeric cell into int|float.
     *
     * Excel only knows "number", not integer vs decimal, so this is the chosen
     * PHP representation, not a recovered semantic: an integer lexical value
     * (no sign, no leading zeros, no exponent) inside the PHP_INT range maps to
     * int; everything else maps to float.
     */
    public static function parseNumericValue(string $value): int|float
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $value) === 1) {
            $maxMagnitude = (string) PHP_INT_MAX;
            $negative = str_starts_with($value, '-');
            $magnitude = $negative ? substr($value, 1) : $value;
            $magnitudeLength = strlen($magnitude);
            if ($magnitudeLength < strlen($maxMagnitude)) {
                return (int) $value;
            }
            if ($magnitudeLength === strlen($maxMagnitude)) {
                if (!$negative && $magnitude <= $maxMagnitude) {
                    return (int) $value;
                }
                if ($negative && $magnitude <= substr((string) PHP_INT_MIN, 1)) {
                    return (int) $value;
                }
            }
        }
        return (float) $value;
    }

    /**
     * Classify an Excel number format code into a semantic temporal type.
     *
     * This is an extension of what Baresheet already recognizes, not a full
     * Excel format parser. It deliberately checks for the literal elapsed
     * markers ([h], [mm], [s]) before stripping buckets, so colours/conditions/
     * locales ([Red], [>=100], [$-409]) never trigger a duration classification.
     *
     * @return 'number'|'date'|'datetime'|'time'|'duration'
     */
    public static function classifyNumberFormat(string $excelFormatCode): string
    {
        $lowerCode = strtolower($excelFormatCode);
        if ($lowerCode === 'general') {
            return 'number';
        }

        // Elapsed (duration) markers — only these brackets denote a duration.
        if (preg_match('/\[(?:h+|m+|s+)\]/', $lowerCode) === 1) {
            return 'duration';
        }

        // Strip colour/condition/locale buckets and quoted literals ("year",
        // "hour"), and drop escaped characters (\h displays a literal "h") so
        // they can't trigger false positives.
        $clean = (string) preg_replace('/\[[^\]]*\]/', '', $lowerCode);
        $clean = (string) preg_replace('/"[^"]*"/', '', $clean);
        $clean = (string) preg_replace('/\\\\./', '', $clean);

        // Week-of-year format code.
        if ($clean === 'ww') {
            return 'date';
        }

        // Conservative marker detection, mirroring the legacy behavior: a
        // letter is only a real marker as a double or inside a separator
        // pattern, so isolated literal letters never classify as temporal.
        $hasDate = (bool) preg_match('/yy|dd|mmm|d\/m|m\/d/', $clean);
        $hasTime = (bool) preg_match('/hh|ss|h:m|m:s|am\/pm|a\/p/', $clean);

        if ($hasDate) {
            return $hasTime ? 'datetime' : 'date';
        }
        return $hasTime ? 'time' : 'number';
    }

    /**
     * Whether a number format code renders as a date/time/duration cell.
     */
    public static function isDateTimeFormatCode(string $excelFormatCode): bool
    {
        return self::classifyNumberFormat($excelFormatCode) !== 'number';
    }

    /**
     * Canonical, deliberately lossy string representation of a native value.
     *
     * Used by the stringifyValues compatibility mode to reproduce the CSV-like
     * strings Baresheet historically returned. Not a formatting engine: it only
     * needs to be deterministic and stable.
     */
    public static function stringifyValue(mixed $value): string
    {
        if ($value instanceof TimeValue) {
            return (string) $value;
        }
        if ($value instanceof \Time\Duration) {
            return self::stringifyDuration($value);
        }
        if ($value instanceof DateTimeInterface) {
            $time =
                (int) $value->format('H')
                + (int) $value->format('i')
                + (int) $value->format('s')
                + (int) $value->format('u');
            if ($time === 0) {
                return $value->format('Y-m-d');
            }
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        return '';
    }

    /**
     * Canonical duration string: total hours may exceed 24, negative allowed.
     */
    public static function stringifyDuration(\Time\Duration $duration): string
    {
        $c = self::durationComponents($duration);
        return self::formatDurationComponents(
            $c['negative'],
            $c['hours'],
            $c['minutes'],
            $c['seconds'],
            $c['microsecond'],
        );
    }

    /**
     * Canonical duration string ("[-]H:MM:SS[.ffffff]").
     *
     * @internal
     */
    public static function formatDurationComponents(
        bool $negative,
        int $hours,
        int $minutes,
        int $seconds,
        int $microsecond = 0,
    ): string {
        // A zero duration is never negative.
        if ($negative && $hours === 0 && $minutes === 0 && $seconds === 0 && $microsecond === 0) {
            $negative = false;
        }
        $result = sprintf('%s%d:%02d:%02d', $negative ? '-' : '', $hours, $minutes, $seconds);
        if ($microsecond > 0) {
            $result .= '.' . str_pad((string) $microsecond, 6, '0', STR_PAD_LEFT);
        }
        return $result;
    }

    /**
     * Convert a duration to an Excel serial (days).
     */
    public static function durationToSerial(\Time\Duration $duration): float
    {
        $c = self::durationComponents($duration);
        return self::durationComponentsToSerial(
            $c['negative'],
            $c['hours'],
            $c['minutes'],
            $c['seconds'],
            $c['microsecond'],
        );
    }

    /**
     * Convert a duration in components to an Excel serial (days).
     *
     * @internal
     */
    public static function durationComponentsToSerial(
        bool $negative,
        int $hours,
        int $minutes,
        int $seconds,
        int $microsecond = 0,
    ): float {
        $totalSeconds = ($hours * 3600.0) + ($minutes * 60) + $seconds + ($microsecond / 1_000_000);
        $serial = $totalSeconds / 86_400;
        return $negative ? -$serial : $serial;
    }

    /**
     * Convert an Excel serial (days) to the canonical duration string.
     *
     * Component/float based so it never needs a 64-bit microsecond total.
     *
     * @throws InvalidArgumentException On non-finite serials.
     */
    public static function durationSerialToString(float $serial): string
    {
        if (!is_finite($serial)) {
            throw new InvalidArgumentException('Invalid duration serial: ' . var_export($serial, true));
        }

        $negative = $serial < 0;
        $secondsFloat = abs($serial) * 86_400.0;
        $hours = (int) floor($secondsFloat / 3600);
        $secondsFloat -= $hours * 3600;
        $minutes = (int) floor($secondsFloat / 60);
        $secondsFloat -= $minutes * 60;
        $seconds = (int) floor($secondsFloat);
        $microsecond = (int) round(($secondsFloat - $seconds) * 1_000_000);
        if ($microsecond >= 1_000_000) {
            $microsecond = 0;
            $seconds++;
            if ($seconds >= 60) {
                $seconds = 0;
                $minutes++;
                if ($minutes >= 60) {
                    $minutes = 0;
                    $hours++;
                }
            }
        }

        return self::formatDurationComponents($negative, $hours, $minutes, $seconds, $microsecond);
    }

    /**
     * Decompose a Time\Duration into its components.
     *
     * Sub-microsecond precision is truncated toward zero (not rounded), so a
     * duration of 999 nanoseconds maps to 0 microseconds.
     *
     * @return array{negative: bool, hours: int, minutes: int, seconds: int, microsecond: int}
     */
    private static function durationComponents(\Time\Duration $duration): array
    {
        $seconds = $duration->seconds;
        $hours = intdiv($seconds, 3_600);
        $seconds %= 3_600;
        $minutes = intdiv($seconds, 60);
        $seconds %= 60;
        return [
            'negative' => $duration->negative,
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
            'microsecond' => intdiv($duration->nanoseconds, 1_000),
        ];
    }

    /**
     * ISO-8601 duration for ODS time cells, e.g. "PT14H30M15S" or "-PT36H30M15.5S".
     *
     * @internal
     */
    public static function formatIsoDurationComponents(
        bool $negative,
        int $hours,
        int $minutes,
        int $seconds,
        int $microsecond = 0,
    ): string {
        // A zero duration is never negative.
        if ($negative && $hours === 0 && $minutes === 0 && $seconds === 0 && $microsecond === 0) {
            $negative = false;
        }
        $secondsString = (string) $seconds;
        if ($microsecond > 0) {
            $secondsString .= '.' . str_pad((string) $microsecond, 6, '0', STR_PAD_LEFT);
        }
        return ($negative ? '-' : '') . "PT{$hours}H{$minutes}M{$secondsString}S";
    }

    /**
     * ISO-8601 duration for a Time\Duration (ODS writer).
     *
     * @internal
     */
    public static function formatIsoDuration(\Time\Duration $duration): string
    {
        $c = self::durationComponents($duration);
        return self::formatIsoDurationComponents(
            $c['negative'],
            $c['hours'],
            $c['minutes'],
            $c['seconds'],
            $c['microsecond'],
        );
    }

    /**
     * Parse an ISO-8601 duration (as stored in ODS office:time-value) into
     * its components.
     *
     * Only the spreadsheet-relevant subset is accepted:
     *
     *     -?P[nD][T[nH][nM][n[.ffffff]S]]
     *
     * Every part is optional (at least one must be present) and the T is
     * required only when a time component is present; the optional fraction is
     * limited to six digits, the whole string must be consumed, and a leading
     * minus is supported. Any other component (Y, M-as-month, W) or trailing
     * garbage is rejected.
     *
     * Out-of-range components are normalized upward (PT90M parses as 1h30m),
     * and a negative zero parses as positive zero.
     *
     * @return array{negative: bool, days: int, hours: int, minutes: int, seconds: int, microsecond: int}
     * @throws InvalidArgumentException On malformed or unsupported input.
     * @internal
     */
    public static function parseIsoDuration(string $value): array
    {
        if (
            preg_match(
                '/^-?P(?:(?<days>\d+)D)?(?:T(?:(?<hours>\d+)H)?(?:(?<minutes>\d+)M)?(?:(?<seconds>\d+(?:\.\d{1,6})?)S)?)?$/D',
                $value,
                $matches,
                PREG_UNMATCHED_AS_NULL,
            ) !== 1
        ) {
            throw new InvalidArgumentException("Invalid ISO 8601 duration: {$value}");
        }

        $hasComponent =
            $matches['days'] !== null
            || $matches['hours'] !== null
            || $matches['minutes'] !== null
            || $matches['seconds'] !== null;
        if (!$hasComponent) {
            throw new InvalidArgumentException("Invalid ISO 8601 duration: {$value}");
        }

        $seconds = 0;
        $microsecond = 0;
        if ($matches['seconds'] !== null) {
            [$secondsPart, $fractionPart] = array_pad(explode('.', $matches['seconds']), 2, '');
            $seconds = (int) $secondsPart;
            if ($fractionPart !== '') {
                $microsecond = (int) str_pad($fractionPart, 6, '0');
            }
        }

        $days = $matches['days'] !== null ? (int) $matches['days'] : 0;
        $hours = $matches['hours'] !== null ? (int) $matches['hours'] : 0;
        $minutes = $matches['minutes'] !== null ? (int) $matches['minutes'] : 0;

        // Normalize out-of-range components (external files may carry e.g. PT90M).
        $minutes += intdiv($seconds, 60);
        $seconds %= 60;
        $hours += intdiv($minutes, 60);
        $minutes %= 60;

        $negative = str_starts_with($value, '-');
        if ($negative && $days === 0 && $hours === 0 && $minutes === 0 && $seconds === 0 && $microsecond === 0) {
            $negative = false;
        }

        return [
            'negative' => $negative,
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
            'microsecond' => $microsecond,
        ];
    }

    /**
     * Build map of column names to indices.
     *
     * @param string[] $columns Columns to select
     * @param string[] $headers Available headers (file or explicit)
     * @return array{0: array<string, int>, 1: array<int, true>} [$columnMap, $selectedIndices]
     * @throws MissingColumnException If any column not found in headers
     */
    public static function buildColumnSelection(array $columns, array $headers): array
    {
        $columnMap = [];
        $selectedIndices = [];

        if (!empty($columns)) {
            $missing = [];

            // To preserve array_search behavior of finding the FIRST matching index when there are duplicate headers,
            // we reverse the array before flipping it. Since headers are usually unique, the overhead is minimal,
            // while providing an O(1) lookup map that is perfectly compatible with the previous behavior.
            $headerMap = array_flip(array_reverse($headers, true));

            foreach ($columns as $colName) {
                if (!isset($headerMap[$colName])) {
                    $missing[] = $colName;
                } else {
                    /** @var int $idx */
                    $idx = $headerMap[$colName];
                    $columnMap[$colName] = $idx;
                    $selectedIndices[$idx] = true;
                }
            }

            if (!empty($missing)) {
                throw new MissingColumnException($missing);
            }
        }

        return [$columnMap, $selectedIndices];
    }

    /**
     * Apply column selection to a row of data.
     *
     * @param array<mixed> $row The input row data
     * @param array<string, int> $columnMap Map of column names to indices
     * @param string[] $columns Column names in desired order
     * @param bool $assoc Whether to return associative array
     * @return array<mixed> The filtered/reordered row
     */
    public static function applyColumnSelection(array $row, array $columnMap, array $columns, bool $assoc): array
    {
        if (empty($columnMap)) {
            return $row;
        }

        $selected = [];
        foreach ($columns as $colName) {
            if ($assoc) {
                // In assoc mode, row is keyed by column name (after array_combine)
                $selected[$colName] = $row[$colName] ?? null;
            } else {
                // In non-assoc mode, row is keyed by numeric index
                $idx = $columnMap[$colName] ?? null;
                $selected[] = $idx !== null ? $row[$idx] ?? null : null;
            }
        }

        return $selected;
    }
}
