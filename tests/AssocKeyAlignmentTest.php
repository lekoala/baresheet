<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\OdsReader;
use LeKoala\Baresheet\OdsWriter;
use LeKoala\Baresheet\Options;
use LeKoala\Baresheet\XlsxReader;
use LeKoala\Baresheet\XlsxWriter;

/**
 * Associative rows written without explicit headers must be aligned on the
 * column order defined by the first row, not flattened with array_values()
 * (which silently transposes values when key order differs between rows).
 */
class AssocKeyAlignmentTest extends TestCase
{
    public function testXlsxAlignsReorderedKeys(): void
    {
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->writeFile([
            ['id' => 1, 'name' => 'Alice'],
            ['name' => 'Bob', 'id' => 2],
        ], $file);

        $reader = new XlsxReader(new Options(assoc: true));
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame(
            [
                ['id' => '1', 'name' => 'Alice'],
                ['id' => '2', 'name' => 'Bob'],
            ],
            $data,
        );
    }

    public function testOdsAlignsReorderedKeys(): void
    {
        $file = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([
            ['id' => 1, 'name' => 'Alice'],
            ['name' => 'Bob', 'id' => 2],
        ], $file);

        $reader = new OdsReader(new Options(assoc: true));
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame(
            [
                ['id' => '1', 'name' => 'Alice'],
                ['id' => '2', 'name' => 'Bob'],
            ],
            $data,
        );
    }

    public function testXlsxMissingKeyBecomesNull(): void
    {
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->writeFile([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2],
        ], $file);

        $reader = new XlsxReader(new Options(assoc: true));
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame(
            [
                ['id' => '1', 'name' => 'Alice'],
                ['id' => '2', 'name' => ''],
            ],
            $data,
        );
    }

    public function testOdsMissingKeyBecomesNull(): void
    {
        $file = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2],
        ], $file);

        $reader = new OdsReader(new Options(assoc: true));
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame(
            [
                ['id' => '1', 'name' => 'Alice'],
                ['id' => '2', 'name' => null],
            ],
            $data,
        );
    }

    public function testXlsxRejectsUnknownKey(): void
    {
        $writer = new XlsxWriter();
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage("column key 'email' absent from the header");
        $writer->writeString([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'email' => 'bob@example.com'],
        ]);
    }

    public function testOdsRejectsUnknownKey(): void
    {
        $writer = new OdsWriter();
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage("column key 'email' absent from the header");
        $writer->writeString([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'email' => 'bob@example.com'],
        ]);
    }

    public function testXlsxFirstRowListThenAssocWritesPositional(): void
    {
        $file = $this->tempFile('xlsx');
        $writer = new XlsxWriter();
        $writer->writeFile([
            ['a', 'b'],
            ['x' => 1, 'y' => 2],
        ], $file);

        $reader = new XlsxReader();
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        // No header is invented mid-stream; the associative row keeps array order.
        self::assertSame(
            [
                ['a', 'b'],
                ['1', '2'],
            ],
            $data,
        );
    }

    public function testOdsFirstRowListThenAssocWritesPositional(): void
    {
        $file = $this->tempFile('ods');
        $writer = new OdsWriter();
        $writer->writeFile([
            ['a', 'b'],
            ['x' => 1, 'y' => 2],
        ], $file);

        $reader = new OdsReader();
        $data = iterator_to_array($reader->readFile($file));
        unlink($file);

        self::assertSame(
            [
                ['a', 'b'],
                ['1', '2'],
            ],
            $data,
        );
    }
}
