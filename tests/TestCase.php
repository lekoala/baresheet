<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Create a unique temporary file path with the given extension.
     * The file is not created on disk; callers write to it or remove it as needed.
     */
    protected function tempFile(string $ext): string
    {
        return sys_get_temp_dir() . '/baresheet_' . bin2hex(random_bytes(6)) . '.' . $ext;
    }

    /**
     * Find an LC_NUMERIC locale whose decimal separator is a comma, or null
     * when none is installed. The current locale is restored before returning.
     */
    protected function commaDecimalLocale(): ?string
    {
        $original = setlocale(LC_NUMERIC, 0);
        $locales = [
            'de-DE',
            'de_DE.UTF-8',
            'de_DE',
            'fr-FR',
            'fr_FR.UTF-8',
            'nl-NL',
            'nl_NL.UTF-8',
            'es-ES',
            'it-IT',
        ];
        try {
            foreach ($locales as $loc) {
                if (setlocale(LC_NUMERIC, $loc) !== false && (localeconv()['decimal_point'] ?? '.') === ',') {
                    return $loc;
                }
            }
        } finally {
            setlocale(LC_NUMERIC, $original !== false ? $original : null);
        }
        return null;
    }
}
