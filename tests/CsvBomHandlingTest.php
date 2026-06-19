<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\CsvReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testUtf8BomWithExplicitSeparator(): void
    {
        $data = "\xEF\xBB\xBFname;age\nJohn;30";
        $reader = new CsvReader();
        $reader->separator = ';';
        $reader->assoc = true;

        $rows = iterator_to_array($reader->readString($data));

        $this->assertCount(1, $rows);
        $this->assertEquals(['name' => 'John', 'age' => '30'], $rows[0]);
    }

    public function testUtf8BomWithRequiredColumns(): void
    {
        $data = "\xEF\xBB\xBFname,age\nJohn,30";
        $reader = new CsvReader();
        $reader->requiredColumns = ['name'];
        $reader->assoc = true;

        $rows = iterator_to_array($reader->readString($data));

        $this->assertCount(1, $rows);
        $this->assertEquals(['name' => 'John', 'age' => '30'], $rows[0]);
    }

    public function testUtf16BomWithoutTranscodingThrows(): void
    {
        $csv = "name,age\nJohn,30";
        $data = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', $csv);
        $reader = new CsvReader();
        $reader->transcodeBomInput = false;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot parse UTF-16LE CSV without transcoding to UTF-8.');

        iterator_to_array($reader->readString($data));
    }

    public function testNonSeekableStreamThrowsWhenSampleIsNeeded(): void
    {
        $descriptorspec = [
            1 => ['pipe', 'w'],
        ];
        $process = proc_open('printf "\xEF\xBB\xBFcol1\nval1"', $descriptorspec, $pipes);
        $fp = $pipes[1];

        $reader = new CsvReader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'CsvReader requires a seekable stream when BOM detection, transcoding, encoding detection, or separator auto-detection is enabled.',
        );

        try {
            iterator_to_array($reader->readStream($fp));
        } finally {
            fclose($fp);
            proc_close($process);
        }
    }

    public function testNonSeekableStreamDoesNotLoseDataWhenNoSampleIsNeeded(): void
    {
        $descriptorspec = [
            1 => ['pipe', 'w'],
        ];
        $process = proc_open('printf "col1,col2\nval1,val2"', $descriptorspec, $pipes);
        $fp = $pipes[1];

        $reader = new CsvReader();
        $reader->separator = ',';
        $reader->skipInputBOM = false;
        $reader->transcodeBomInput = false;

        try {
            $rows = iterator_to_array($reader->readStream($fp));
            $this->assertNotEmpty($rows);
            $this->assertEquals('col1', $rows[0][0]);
            $this->assertEquals('val1', $rows[1][0]);
        } finally {
            fclose($fp);
            proc_close($process);
        }
    }
}
