<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\CsvWriter;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxWriter;
use PHPUnit\Framework\TestCase;

class BaresheetTest extends TestCase
{
    public function testReadCsvByExtension(): void
    {
        $data = iterator_to_array(Baresheet::read(__DIR__ . '/data/basic.csv'));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testReadWithoutExtension(): void
    {
        $data = iterator_to_array(Baresheet::read(__DIR__ . '/data/basic'));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testReadXlsxByExtension(): void
    {
        $data = iterator_to_array(Baresheet::read(__DIR__ . '/data/basic.xlsx'));
        self::assertCount(1, $data);
        self::assertCount(3, $data[0]);
    }

    public function testReadUnsupportedExtensionXls(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported format: xls');
        iterator_to_array(Baresheet::read(__DIR__ . '/data/basic.xls'));
    }

    public function testReadUnsupportedExtensionZip(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported format: zip');
        iterator_to_array(Baresheet::read(__DIR__ . '/data/test_write.zip'));
    }

    public function testReadWithOptions(): void
    {
        $opts = new Options(assoc: true);
        $data = iterator_to_array(Baresheet::read(__DIR__ . '/data/headers.csv', $opts));
        self::assertCount(1, $data);
        self::assertArrayHasKey('email', $data[0]);
    }

    public function testReadStringAutoDetect(): void
    {
        $csvData = iterator_to_array(Baresheet::readString('john,doe,john.doe@example.com'));
        self::assertCount(1, $csvData);
        self::assertCount(3, $csvData[0]);

        $xlsxContent = file_get_contents(__DIR__ . '/data/basic.xlsx');
        $xlsxData = iterator_to_array(Baresheet::readString($xlsxContent));
        self::assertCount(1, $xlsxData);
        self::assertCount(3, $xlsxData[0]);
    }

    public function testWriteCsv(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_auto_' . time() . '.csv';
        $opts = new Options(bom: false);
        $result = Baresheet::write(
            [
                ['john', 'doe', 'john.doe@example.com'],
            ],
            $tempFile,
            $opts,
        );

        self::assertTrue($result);
        self::assertTrue(is_file($tempFile));

        $readBack = iterator_to_array(Baresheet::read($tempFile));
        self::assertCount(1, $readBack);

        unlink($tempFile);
    }

    public function testWriteXlsx(): void
    {
        $tempFile = sys_get_temp_dir() . '/baresheet_auto_' . time() . '.xlsx';
        $result = Baresheet::write([
            ['john', 'doe', 'john.doe@example.com'],
        ], $tempFile);

        self::assertTrue($result);
        self::assertTrue(is_file($tempFile));

        $readBack = iterator_to_array(Baresheet::read($tempFile));
        self::assertCount(1, $readBack);

        unlink($tempFile);
    }

    public function testWriteStringCsv(): void
    {
        $opts = new Options(bom: false);
        $output = Baresheet::writeString(
            [
                ['john', 'doe', 'john.doe@example.com'],
            ],
            'csv',
            $opts,
        );

        self::assertStringContainsString('john.doe@example.com', $output);
    }

    public function testWriteStringXlsx(): void
    {
        $output = Baresheet::writeString([
            ['john', 'doe', 'john.doe@example.com'],
        ], 'xlsx');

        self::assertStringContainsString('[Content_Types].xml', $output);
    }

    public function testGetWriterCsv(): void
    {
        $writer = Baresheet::getWriter('csv');
        self::assertInstanceOf(CsvWriter::class, $writer);
    }

    public function testGetWriterXlsx(): void
    {
        $writer = Baresheet::getWriter('xlsx');
        self::assertInstanceOf(XlsxWriter::class, $writer);
    }

    public function testGetWriterOds(): void
    {
        $writer = Baresheet::getWriter('ods');
        self::assertInstanceOf(OdsWriter::class, $writer);
    }

    public function testGetWriterCaseInsensitive(): void
    {
        $writer = Baresheet::getWriter('CSV');
        self::assertInstanceOf(CsvWriter::class, $writer);
    }

    public function testGetWriterUnsupported(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported format: pdf');
        Baresheet::getWriter('pdf');
    }

    public function testGetReaderCsv(): void
    {
        $reader = Baresheet::getReader('csv');
        self::assertInstanceOf(\LeKoala\Baresheet\CsvReader::class, $reader);
    }

    public function testGetReaderXlsx(): void
    {
        $reader = Baresheet::getReader('xlsx');
        self::assertInstanceOf(\LeKoala\Baresheet\XlsxReader::class, $reader);
    }

    public function testGetReaderOds(): void
    {
        $reader = Baresheet::getReader('ods');
        self::assertInstanceOf(\LeKoala\Baresheet\OdsReader::class, $reader);
    }

    public function testGetReaderCaseInsensitive(): void
    {
        $reader = Baresheet::getReader('CSV');
        self::assertInstanceOf(\LeKoala\Baresheet\CsvReader::class, $reader);
    }

    public function testGetReaderUnsupported(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported format: pdf');
        Baresheet::getReader('pdf');
    }

    public function testWriteStreamCsv(): void
    {
        $stream = Baresheet::writeStream([['A', 'B'], ['1', '2']], 'csv');
        self::assertIsResource($stream);
        $contents = stream_get_contents($stream);
        self::assertNotEmpty($contents);
        self::assertStringContainsString('A', $contents);
        fclose($stream);
    }

    public function testWriteStreamXlsx(): void
    {
        $stream = Baresheet::writeStream([['A', 'B'], ['1', '2']], 'xlsx');
        self::assertIsResource($stream);
        $contents = stream_get_contents($stream);
        self::assertNotEmpty($contents);
        self::assertStringStartsWith('PK', $contents); // ZIP magic
        fclose($stream);
    }
}
