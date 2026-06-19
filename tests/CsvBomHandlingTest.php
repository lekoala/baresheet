<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\CsvReader;
use PHPUnit\Framework\TestCase;

class CsvBomHandlingTest extends TestCase
{
    public function testUtf8BomWithQuotes(): void
    {
        $data = "\xEF\xBB\xBF\"name\",\"age\"\n\"John\",30";
        $reader = new CsvReader();
        $reader->assoc = true;

        $rows = iterator_to_array($reader->readString($data));

        $this->assertCount(1, $rows);
        $this->assertEquals(['name' => 'John', 'age' => '30'], $rows[0]);
    }

    public function testUtf16LeBomWithQuotes(): void
    {
        $csv = "\"name\",\"age\"\n\"John\",30";
        $data = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', $csv);
        $reader = new CsvReader();
        $reader->assoc = true;

        $rows = iterator_to_array($reader->readString($data));

        $this->assertCount(1, $rows);
        $this->assertEquals(['name' => 'John', 'age' => '30'], $rows[0]);
    }
}
