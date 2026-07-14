<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Bom;
use LeKoala\Baresheet\CsvWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CsvEncodingConflictTest extends TestCase
{
    public function testNonUtf8BomWithOutputEncodingThrows(): void
    {
        $data = [['name' => 'John', 'age' => 30]];
        $writer = new CsvWriter();
        $writer->bom = Bom::Utf16Le;
        $writer->outputEncoding = 'UTF-16LE';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Do not combine a non-UTF-8 BOM with outputEncoding; the BOM already configures stream transcoding.',
        );

        $writer->writeString($data);
    }

    public function testUtf8BomWithNonUtf8OutputEncodingThrows(): void
    {
        $data = [['name' => 'John', 'age' => 30]];
        $writer = new CsvWriter();
        $writer->bom = Bom::Utf8;
        $writer->outputEncoding = 'ISO-8859-1';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Do not combine a UTF-8 BOM with a non-UTF-8 outputEncoding. Disable the BOM or use UTF-8 output.',
        );

        $writer->writeString($data);
    }

    public function testUtf8BomWithUtf8OutputEncodingDoesNotThrow(): void
    {
        $data = [['name' => 'John', 'age' => 30]];
        $writer = new CsvWriter();
        $writer->bom = Bom::Utf8;
        $writer->outputEncoding = 'UTF-8';

        $output = $writer->writeString($data);
        $this->assertNotEmpty($output);
    }

    public function testLegacyOutputEncodingWithoutBomWorks(): void
    {
        $data = [['name' => 'John', 'age' => 30]];
        $writer = new CsvWriter();
        $writer->bom = false;
        $writer->outputEncoding = 'ISO-8859-1';

        $output = $writer->writeString($data);
        $this->assertNotEmpty($output);
        $this->assertStringStartsNotWith(Bom::Utf8->value, $output);
    }
}
