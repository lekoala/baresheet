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
}
