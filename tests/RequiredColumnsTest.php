<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\CsvReader;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\Options;

class RequiredColumnsTest extends TestCase
{
    public function testCsvRequiredColumnsAllPresent(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.csv';
        file_put_contents($tempFile, "email,name,price\njohn@example.com,John,10.50\n");

        $reader = new CsvReader(new Options(
            assoc: true,
            requiredColumns: ['email', 'name']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame('john@example.com', $data[0]['email']);

        unlink($tempFile);
    }

    public function testCsvRequiredColumnsMissingThrows(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.csv';
        file_put_contents($tempFile, "email,name\njohn@example.com,John\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: price');

        $reader = new CsvReader(new Options(
            assoc: true,
            requiredColumns: ['email', 'price']
        ));
        iterator_to_array($reader->readFile($tempFile));

        unlink($tempFile);
    }

    public function testCsvRequiredColumnsMultipleMissing(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.csv';
        file_put_contents($tempFile, "email\njohn@example.com\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: name, price');

        $reader = new CsvReader(new Options(
            assoc: true,
            requiredColumns: ['email', 'name', 'price']
        ));
        iterator_to_array($reader->readFile($tempFile));

        unlink($tempFile);
    }

    public function testCsvRequiredColumnsEmptyArrayNoValidation(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.csv';
        file_put_contents($tempFile, "email,name\njohn@example.com,John\n");

        $reader = new CsvReader(new Options(
            assoc: true,
            requiredColumns: []
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);

        unlink($tempFile);
    }

    public function testXlsxRequiredColumnsAllPresent(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.xlsx';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John', 'price' => 10.50]
        ], $tempFile);

        $reader = new XlsxReader(new Options(
            assoc: true,
            requiredColumns: ['email', 'name']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame('john@example.com', $data[0]['email']);

        unlink($tempFile);
    }

    public function testXlsxRequiredColumnsMissingThrows(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.xlsx';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John']
        ], $tempFile);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: price');

        $reader = new XlsxReader(new Options(
            assoc: true,
            requiredColumns: ['email', 'price']
        ));
        iterator_to_array($reader->readFile($tempFile));

        unlink($tempFile);
    }

    public function testOdsRequiredColumnsAllPresent(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.ods';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John', 'price' => 10.50]
        ], $tempFile);

        $reader = new OdsReader(new Options(
            assoc: true,
            requiredColumns: ['email', 'name']
        ));
        $data = iterator_to_array($reader->readFile($tempFile));

        self::assertCount(1, $data);
        self::assertSame('john@example.com', $data[0]['email']);

        unlink($tempFile);
    }

    public function testOdsRequiredColumnsMissingThrows(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.ods';
        Baresheet::write([
            ['email' => 'john@example.com', 'name' => 'John']
        ], $tempFile);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required columns: price');

        $reader = new OdsReader(new Options(
            assoc: true,
            requiredColumns: ['email', 'price']
        ));
        iterator_to_array($reader->readFile($tempFile));

        unlink($tempFile);
    }

    public function testBaresheetFacadeWithRequiredColumns(): void
    {
        $tempFile = sys_get_temp_dir() . '/test_required_' . time() . '.csv';
        file_put_contents($tempFile, "email,name,price\njohn@example.com,John,10.50\n");

        $opts = new Options(assoc: true, requiredColumns: ['email', 'name', 'price']);
        $data = iterator_to_array(Baresheet::read($tempFile, $opts));

        self::assertCount(1, $data);
        self::assertSame('john@example.com', $data[0]['email']);

        unlink($tempFile);
    }
}
