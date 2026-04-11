<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\Options;

class ColumnsTest extends TestCase
{
    public function testCsvColumnsSelectAssoc(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "email,name,age,city\njohn@example.com,John,25,NYC\n");

        $reader = new CsvReader(new Options(
            assoc: true,
            columns: ['name', 'email']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['name' => 'John', 'email' => 'john@example.com'], $data[0]);

        unlink($tempFile);
    }

    public function testCsvColumnsReorderAssoc(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "email,name,age\njohn@example.com,John,25\n");

        $reader = new CsvReader(new Options(
            assoc: true,
            columns: ['age', 'email', 'name']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['age' => '25', 'email' => 'john@example.com', 'name' => 'John'], $data[0]);

        unlink($tempFile);
    }

    public function testCsvColumnsSelectPlain(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "john@example.com,John,25\n");  // No header row

        $reader = new CsvReader(new Options(
            assoc: false,
            headers: ['email', 'name', 'age'],
            columns: ['name', 'email']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['John', 'john@example.com'], $data[0]);

        unlink($tempFile);
    }

    public function testCsvColumnsMissingThrows(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "email,name\njohn@example.com,John\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: missing_column');

        $reader = new CsvReader(new Options(
            assoc: true,
            columns: ['email', 'missing_column']
        ));
        iterator_to_array($reader->readFile($tempFile));

        unlink($tempFile);
    }

    public function testCsvColumnsEmptyArrayReturnsAll(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "email,name,age\njohn@example.com,John,25\n");

        $reader = new CsvReader(new Options(
            assoc: true,
            columns: []
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['email' => 'john@example.com', 'name' => 'John', 'age' => '25'], $data[0]);

        unlink($tempFile);
    }

    public function testXlsxColumnsSelectAssoc(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.xlsx';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John', 'age' => 25]
        ], $tempFile);

        $reader = new XlsxReader(new Options(
            assoc: true,
            columns: ['name', 'email']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['name' => 'John', 'email' => 'john@example.com'], $data[0]);

        unlink($tempFile);
    }

    public function testXlsxColumnsReorderAssoc(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.xlsx';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John', 'age' => 25]
        ], $tempFile);

        $reader = new XlsxReader(new Options(
            assoc: true,
            columns: ['age', 'name']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['age' => '25', 'name' => 'John'], $data[0]);

        unlink($tempFile);
    }

    public function testOdsColumnsSelectAssoc(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.ods';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John', 'age' => 25]
        ], $tempFile);

        $reader = new OdsReader(new Options(
            assoc: true,
            columns: ['name', 'email']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['name' => 'John', 'email' => 'john@example.com'], $data[0]);

        unlink($tempFile);
    }

    public function testOdsColumnsReorderAssoc(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.ods';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John', 'age' => 25]
        ], $tempFile);

        $reader = new OdsReader(new Options(
            assoc: true,
            columns: ['age', 'email']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['age' => '25', 'email' => 'john@example.com'], $data[0]);

        unlink($tempFile);
    }

    public function testBaresheetFacadeWithColumns(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "email,name,age\njohn@example.com,John,25\n");

        $opts = new Options(assoc: true, columns: ['name', 'email']);
        $data = iterator_to_array(Baresheet::read($tempFile, $opts));

        self::assertCount(1, $data);
        self::assertSame(['name' => 'John', 'email' => 'john@example.com'], $data[0]);

        unlink($tempFile);
    }

    public function testColumnsWithLimit(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "email,name\na@b.com,Alice\nc@d.com,Charlie\ne@f.com,Eve\n");

        $reader = new CsvReader(new Options(
            assoc: true,
            columns: ['name'],
            limit: 2
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(2, $data);
        self::assertSame(['name' => 'Alice'], $data[0]);
        self::assertSame(['name' => 'Charlie'], $data[1]);

        unlink($tempFile);
    }

    public function testColumnsWithOffset(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_columns_' . time() . '.csv';
        file_put_contents($tempFile, "email,name\na@b.com,Alice\nc@d.com,Charlie\ne@f.com,Eve\n");

        $reader = new CsvReader(new Options(
            assoc: true,
            columns: ['name'],
            offset: 1,
            limit: 1
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame(['name' => 'Charlie'], $data[0]);

        unlink($tempFile);
    }
}
