<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Internal;

use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Internal\CsvSupport;
use LeKoala\Baresheet\Tests\TestCase;

class CsvSupportTest extends TestCase
{
    public function testGetInputStreamReturnsStream(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'csvsupport_');
        file_put_contents($tempFile, 'test data');

        try {
            $stream = CsvSupport::getInputStream($tempFile);
            $this->assertEquals('stream', get_resource_type($stream));
            fclose($stream);
        } finally {
            unlink($tempFile);
        }
    }

    public function testGetInputStreamThrowsExceptionForMissingFile(): void
    {
        $this->expectException(InvalidDocumentException::class);
        $this->expectExceptionMessage('Failed to open stream');

        CsvSupport::getInputStream('/path/to/nonexistent/file.csv');
    }
}
