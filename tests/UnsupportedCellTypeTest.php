<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Baresheet;
use LeKoala\Baresheet\Exception\WriteException;
use LeKoala\Baresheet\Options;
use PHPUnit\Framework\Attributes\DataProvider;

class UnsupportedCellTypeTest extends TestCase
{
    /** @return iterable<string, array{string, bool, mixed, string}> */
    public static function unsupportedCells(): iterable
    {
        foreach (['csv', 'xlsx', 'ods'] as $ext) {
            foreach ([false, true] as $forceText) {
                $suffix = $ext . ($forceText ? ' forceText' : '');
                yield "array {$suffix}" => [$ext, $forceText, ['a', 'b'], 'array'];
                yield "object {$suffix}" => [$ext, $forceText, new \stdClass(), 'stdClass'];
            }
        }
    }

    #[DataProvider('unsupportedCells')]
    public function testWritersRejectUnsupportedCellTypes(string $ext, bool $forceText, mixed $cell, string $type): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Unsupported ' . strtoupper($ext) . ' cell type: ' . $type);
        Baresheet::writeString([['foo', $cell]], $ext, new Options(forceText: $forceText));
    }

    public function testXlsxAndOdsErrorsReportCellPosition(): void
    {
        foreach (['xlsx', 'ods'] as $ext) {
            try {
                Baresheet::writeString([['foo', 'bar'], ['baz', ['oops']]], $ext);
                self::fail('Expected WriteException for ' . $ext);
            } catch (WriteException $e) {
                self::assertStringContainsString("sheet 'Sheet1', cell B2", $e->getMessage());
            }
        }
    }

    public function testXlsxColumnTextFormatAlsoRejectsArrays(): void
    {
        $this->expectException(WriteException::class);
        $this->expectExceptionMessage('Unsupported XLSX cell type: array');
        Baresheet::writeString([['foo', ['oops']]], 'xlsx', new Options(columnFormats: ['B' => '@']));
    }

    public function testStringableAndNullRemainWritable(): void
    {
        $stringable = new class implements \Stringable {
            public function __toString(): string
            {
                return 'hello';
            }
        };
        foreach (['csv', 'xlsx', 'ods'] as $ext) {
            foreach ([false, true] as $forceText) {
                $options = new Options(forceText: $forceText);
                $contents = Baresheet::writeString([['a', 'b'], [$stringable, null]], $ext, $options);
                $rows = iterator_to_array(Baresheet::readString($contents, $ext, new Options(assoc: false)));
                self::assertSame('hello', $rows[1][0], "{$ext} forceText=" . var_export($forceText, true));
            }
        }
    }
}
