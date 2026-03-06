<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\Options;
use PHPUnit\Framework\TestCase;

class CsvResilienceTest extends TestCase
{
    public function testBackslashEnding(): void
    {
        $data = [
            ['field1' => 'foo\\', 'field2' => 'bar']
        ];
        $writer = new CsvWriter();
        $csv = $writer->writeString($data);

        $reader = new CsvReader();
        $readBack = iterator_to_array($reader->readString($csv));

        $this->assertCount(1, $readBack);
        $this->assertEquals('foo\\', $readBack[0][0]);
        $this->assertEquals('bar', $readBack[0][1]);
    }

    public function testBackslashBeforeQuote(): void
    {
        $data = [
            ['field1' => 'foo\\"', 'field2' => 'bar']
        ];
        $writer = new CsvWriter();
        $csv = $writer->writeString($data);

        $reader = new CsvReader();
        $readBack = iterator_to_array($reader->readString($csv));

        $this->assertCount(1, $readBack);
        $this->assertEquals('foo\\"', $readBack[0][0]);
    }

    public function testQuotesAndNewlines(): void
    {
        $data = [
            ['"quoted"', "line\nbreak", "comma,here", "all\"three\n,together"]
        ];
        $writer = new CsvWriter();
        $csv = $writer->writeString($data);

        $reader = new CsvReader();
        $readBack = iterator_to_array($reader->readString($csv));

        $this->assertCount(1, $readBack);
        $this->assertEquals($data[0][0], $readBack[0][0]);
        $this->assertEquals($data[0][1], $readBack[0][1]);
        $this->assertEquals($data[0][2], $readBack[0][2]);
        $this->assertEquals($data[0][3], $readBack[0][3]);
    }

    public function testFormulaEscapingRoundTrip(): void
    {
        $data = [
            ['=SUM(A1:A10)', 'normal text']
        ];
        $writer = new CsvWriter();
        $writer->escapeFormulas = true;
        $csv = $writer->writeString($data);

        $this->assertStringContainsString("'=SUM", $csv);

        $reader = new CsvReader();
        $readBack = iterator_to_array($reader->readString($csv));
        // Note: The ' IS part of the data now. This is intended behavior for formula protection,
        // but it does technically alter the data.
        $this->assertEquals("'=SUM(A1:A10)", $readBack[0][0]);
    }

    public function testMixedEdgeCases(): void
    {
        $data = [
            ['" , \n \ \rb', 'end']
        ];
        $writer = new CsvWriter();
        $csv = $writer->writeString($data);

        $reader = new CsvReader();
        $readBack = iterator_to_array($reader->readString($csv));

        $this->assertCount(1, $readBack);
        $this->assertEquals($data[0][0], $readBack[0][0]);
    }

    public function testFormulaEscapingAltersData(): void
    {
        $writer = new CsvWriter();
        $writer->escapeFormulas = true;
        $row = ['=SUM()'];

        // This is a test that the data IS altered, which is intentional for safety.
        $processed = $writer->writeString([$row]);
        $this->assertStringContainsString("'=SUM", $processed);
    }
}
