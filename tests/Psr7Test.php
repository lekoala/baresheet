<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\XlsxWriter;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Spread;

class Psr7Test extends TestCase
{
    private function assertValidStream($stream, string $expectedExtension): void
    {
        $this->assertIsResource($stream);
        $this->assertEquals('stream', get_resource_type($stream));

        $contents = stream_get_contents($stream);
        $this->assertNotEmpty($contents);

        $ext = Spread::getExtensionForContent($contents);
        $this->assertEquals($expectedExtension, $ext);

        fclose($stream);
    }

    public function testCsvWriteStream(): void
    {
        $writer = new CsvWriter();
        $stream = $writer->writeStream([
            ["Hello", "World"]
        ]);

        $this->assertValidStream($stream, 'csv');
    }

    public function testXlsxWriteStream(): void
    {
        $writer = new XlsxWriter();
        $stream = $writer->writeStream([
            ["Hello", "World"]
        ]);

        $this->assertValidStream($stream, 'xlsx');
    }

    public function testOdsWriteStream(): void
    {
        $writer = new OdsWriter();
        $stream = $writer->writeStream([
            ["Hello", "World"]
        ]);

        $this->assertValidStream($stream, 'ods');
    }
    public function testXlsxFallbackWriteStream(): void
    {
        // Force fallback by using a class that returns false for canStream
        $writer = new class extends XlsxWriter {
            protected function canStream(): bool
            {
                return false;
            }
        };
        $stream = $writer->writeStream([
            ["Hello", "World"]
        ]);

        $this->assertValidStream($stream, 'xlsx');
    }

    public function testOdsFallbackWriteStream(): void
    {
        // Force fallback by using a class that returns false for canStream
        $writer = new class extends OdsWriter {
            protected function canStream(): bool
            {
                return false;
            }
        };
        $stream = $writer->writeStream([
            ["Hello", "World"]
        ]);

        $this->assertValidStream($stream, 'ods');
    }
}
