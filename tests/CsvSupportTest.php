<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Internal\CsvSupport;

class CsvSupportTest extends TestCase
{
    public function testGetOutputStreamValidFile(): void
    {
        $tempFile = $this->tempFile('txt');
        $stream = CsvSupport::getOutputStream($tempFile);

        $this->assertIsResource($stream);

        fwrite($stream, 'test_output');
        fclose($stream);

        $this->assertStringEqualsFile($tempFile, 'test_output');
        unlink($tempFile);
    }

    public function testGetOutputStreamInvalidFile(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Failed to open stream');

        CsvSupport::getOutputStream('/invalid/path/that/does/not/exist/file.txt');
    }

    public function testGetOutputStreamDefault(): void
    {
        ob_start();
        $stream = CsvSupport::getOutputStream();
        $this->assertIsResource($stream);

        fwrite($stream, 'default_output');
        $output = ob_get_clean();

        // Ensure that something is printed to the output buffer
        // Note: php://output sends to the current output buffer
        $this->assertStringContainsString('default_output', $output);
    }
}
