<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use DateTimeInterface;
use Generator;
use LeKoala\Baresheet\Exception\InvalidRowException;
use LeKoala\Baresheet\Transform;
use PHPUnit\Framework\TestCase;

class TransformTest extends TestCase
{
    public function testTrimValues(): void
    {
        $data = [
            [' john ',    ' doe '],
            ['  hello  ', " world\t"],
        ];

        $result = iterator_to_array(Transform::trim($data));
        self::assertEquals(['john', 'doe'], $result[0]);
        self::assertEquals(['hello', 'world'], $result[1]);
    }

    public function testTrimPreservesNonStrings(): void
    {
        $data = [
            [123, true, null, 45.6],
        ];

        $result = iterator_to_array(Transform::trim($data));
        self::assertEquals([123, true, null, 45.6], $result[0]);
    }

    public function testTrimKeys(): void
    {
        $data = [
            [' name ' => 'john', ' email ' => 'john@example.com'],
        ];

        $result = iterator_to_array(Transform::trim($data, trimKeys: true));
        self::assertArrayHasKey('name', $result[0]);
        self::assertArrayHasKey('email', $result[0]);
        self::assertArrayNotHasKey(' name ', $result[0]);
    }

    public function testTrimKeysDoesNotAffectNumericKeys(): void
    {
        $data = [
            [' john ', ' doe '],
        ];

        $result = iterator_to_array(Transform::trim($data, trimKeys: true));
        self::assertEquals(['john', 'doe'], $result[0]);
        self::assertArrayHasKey(0, $result[0]);
    }

    public function testNullAs(): void
    {
        $data = [
            ['name' => 'john', 'email' => null],
            ['name' => null, 'email' => 'test@example.com'],
        ];

        $result = iterator_to_array(Transform::nullAs($data, 'N/A'));
        self::assertEquals(['name' => 'john', 'email' => 'N/A'], $result[0]);
        self::assertEquals(['name' => 'N/A', 'email' => 'test@example.com'], $result[1]);
    }

    public function testNullAsPreservesNonNull(): void
    {
        $data = [
            [0, '', false, 123],
        ];

        $result = iterator_to_array(Transform::nullAs($data, 'N/A'));
        self::assertEquals([0, '', false, 123], $result[0]);
    }

    public function testBoolAs(): void
    {
        $data = [
            ['name' => 'john', 'active' => true, 'deleted' => false],
        ];

        $result = iterator_to_array(Transform::boolAs($data, 'Yes', 'No'));
        self::assertEquals(['name' => 'john', 'active' => 'Yes', 'deleted' => 'No'], $result[0]);
    }

    public function testBoolAsPreservesNonBool(): void
    {
        $data = [
            [1, 0, 'true', 'false', null],
        ];

        $result = iterator_to_array(Transform::boolAs($data, 'Y', 'N'));
        self::assertEquals([1, 0, 'true', 'false', null], $result[0]);
    }

    public function testMap(): void
    {
        $data = [
            ['name' => 'john', 'price' => '10.5'],
        ];

        $result = iterator_to_array(Transform::map($data, static function ($v, $k) {
            if ($k === 'price') {
                return (float) $v;
            }
            return $v;
        }));

        self::assertEquals(['name' => 'john', 'price' => 10.5], $result[0]);
    }

    public function testMapReceivesColumnKey(): void
    {
        $data = [
            ['a', 'b'],
        ];

        $keys = [];
        iterator_to_array(Transform::map($data, static function ($v, $k) use (&$keys) {
            $keys[] = $k;
            return $v;
        }));

        self::assertEquals([0, 1], $keys);
    }

    public function testFilter(): void
    {
        $data = [
            ['name' => 'john', 'active' => true],
            ['name' => 'jane', 'active' => false],
            ['name' => 'bob', 'active' => true],
        ];

        $result = iterator_to_array(Transform::filter($data, static fn($row) => $row['active'] === true));

        self::assertCount(2, $result);
        self::assertEquals('john', $result[0]['name']);
        self::assertEquals('bob', $result[1]['name']);
    }

    public function testFilterReceivesIndex(): void
    {
        $data = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ];

        $result = iterator_to_array(Transform::filter($data, static fn($row, $index) => $index === 1));

        self::assertCount(1, $result);
        self::assertEquals(2, $result[0]['id']);
    }

    public function testCastInt(): void
    {
        $data = [
            ['qty' => '42', 'name' => 'item'],
        ];

        $result = iterator_to_array(Transform::cast($data, ['qty' => 'int']));
        self::assertSame(42, $result[0]['qty']);
        self::assertSame('item', $result[0]['name']);
    }

    public function testCastFloat(): void
    {
        $data = [
            ['price' => '19.99'],
        ];

        $result = iterator_to_array(Transform::cast($data, ['price' => 'float']));
        self::assertSame(19.99, $result[0]['price']);
    }

    public function testCastBool(): void
    {
        $data = [
            ['active' => '1', 'deleted' => '0', 'flag' => 'yes'],
        ];

        $result = iterator_to_array(Transform::cast($data, [
            'active' => 'bool',
            'deleted' => 'bool',
            'flag' => 'bool',
        ]));
        self::assertTrue($result[0]['active']);
        self::assertFalse($result[0]['deleted']);
        self::assertTrue($result[0]['flag']);
    }

    public function testCastString(): void
    {
        $data = [
            ['id' => 123],
        ];

        $result = iterator_to_array(Transform::cast($data, ['id' => 'string']));
        self::assertSame('123', $result[0]['id']);
    }

    public function testCastDate(): void
    {
        $data = [
            ['created' => '2024-01-15'],
        ];

        $result = iterator_to_array(Transform::cast($data, ['created' => 'date']));
        self::assertInstanceOf(DateTimeInterface::class, $result[0]['created']);
        self::assertEquals('2024-01-15', $result[0]['created']->format('Y-m-d'));
    }

    public function testCastEmptyReturnsDefaults(): void
    {
        $data = [
            ['qty' => null, 'price' => '', 'active' => null, 'note' => ''],
        ];

        $result = iterator_to_array(Transform::cast($data, [
            'qty' => 'int',
            'price' => 'float',
            'active' => 'bool',
            'note' => 'string',
        ]));
        self::assertSame(0, $result[0]['qty']);
        self::assertSame(0.0, $result[0]['price']);
        self::assertFalse($result[0]['active']);
        self::assertSame('', $result[0]['note']);
    }

    public function testCastNullableEmptyReturnsNull(): void
    {
        $data = [
            ['qty' => null, 'price' => '', 'active' => null, 'note' => '', 'created' => null],
        ];

        $result = iterator_to_array(Transform::cast($data, [
            'qty' => '?int',
            'price' => '?float',
            'active' => '?bool',
            'note' => '?string',
            'created' => '?date',
        ]));
        self::assertNull($result[0]['qty']);
        self::assertNull($result[0]['price']);
        self::assertNull($result[0]['active']);
        self::assertNull($result[0]['note']);
        self::assertNull($result[0]['created']);
    }

    public function testCastNullable(): void
    {
        $data = [
            ['qty' => '42', 'price' => '19.99', 'active' => '1', 'note' => 'hello', 'created' => '2024-01-15'],
        ];

        $result = iterator_to_array(Transform::cast($data, [
            'qty' => '?int',
            'price' => '?float',
            'active' => '?bool',
            'note' => '?string',
            'created' => '?date',
        ]));
        self::assertSame(42, $result[0]['qty']);
        self::assertSame(19.99, $result[0]['price']);
        self::assertTrue($result[0]['active']);
        self::assertSame('hello', $result[0]['note']);
        self::assertInstanceOf(DateTimeInterface::class, $result[0]['created']);
    }

    public function testCastByIndex(): void
    {
        $data = [
            ['42', 'item'],
        ];

        $result = iterator_to_array(Transform::cast($data, [0 => 'int']));
        self::assertSame(42, $result[0][0]);
        self::assertSame('item', $result[0][1]);
    }

    public function testChunk(): void
    {
        $data = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
            ['id' => 5],
        ];

        $result = iterator_to_array(Transform::chunk($data, 2));
        self::assertCount(3, $result);
        self::assertCount(2, $result[0]);
        self::assertCount(2, $result[1]);
        self::assertCount(1, $result[2]);
        self::assertEquals(5, $result[2][0]['id']);
    }

    public function testChunkExactSize(): void
    {
        $data = [
            ['a'],
            ['b'],
            ['c'],
        ];

        $result = iterator_to_array(Transform::chunk($data, 3));
        self::assertCount(1, $result);
        self::assertCount(3, $result[0]);
    }

    public function testChunkInvalidSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be a positive integer');

        iterator_to_array(Transform::chunk([['a']], 0));
    }

    public function testChunkNegativeSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Chunk size must be a positive integer');

        iterator_to_array(Transform::chunk([['a']], -1));
    }

    public function testSlice(): void
    {
        $data = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
            ['id' => 4],
            ['id' => 5],
        ];

        $result = iterator_to_array(Transform::slice($data, 1, 2));
        self::assertSame([['id' => 2], ['id' => 3]], $result);
    }

    public function testSliceReindexesKeys(): void
    {
        $data = [
            'a' => ['id' => 1],
            'b' => ['id' => 2],
            'c' => ['id' => 3],
        ];

        $result = iterator_to_array(Transform::slice($data, 1));
        self::assertSame([0 => ['id' => 2], 1 => ['id' => 3]], $result);
    }

    public function testSliceWithoutLimitYieldsRemainder(): void
    {
        $data = [['id' => 1], ['id' => 2], ['id' => 3]];

        $result = iterator_to_array(Transform::slice($data, 1));
        self::assertSame([['id' => 2], ['id' => 3]], $result);
    }

    public function testSliceZeroLimitYieldsNothing(): void
    {
        $data = [['id' => 1], ['id' => 2]];

        $result = iterator_to_array(Transform::slice($data, 0, 0));
        self::assertSame([], $result);
    }

    public function testSliceStopsEarly(): void
    {
        $calls = 0;
        $data = (function () use (&$calls) {
            for ($i = 0; $i < 1000; $i++) {
                $calls++;
                yield ['id' => $i];
            }
        })();

        $result = iterator_to_array(Transform::slice($data, 10, 5));
        self::assertCount(5, $result);
        self::assertSame(15, $calls);
    }

    public function testSliceNegativeOffsetThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Offset must be greater than or equal to 0.');

        iterator_to_array(Transform::slice([['a']], -1));
    }

    public function testSliceNegativeLimitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Limit must be greater than or equal to 0.');

        iterator_to_array(Transform::slice([['a']], 0, -1));
    }

    public function testChaining(): void
    {
        $data = [
            ['name' => ' john ', 'active' => true, 'score' => null],
            ['name' => ' jane ', 'active' => false, 'score' => '85'],
        ];

        $trimmed = Transform::trim($data);
        $withBools = Transform::boolAs($trimmed, 'Yes', 'No');
        $withNulls = Transform::nullAs($withBools, 'N/A');
        $result = iterator_to_array($withNulls);

        self::assertEquals(['name' => 'john', 'active' => 'Yes', 'score' => 'N/A'], $result[0]);
        self::assertEquals(['name' => 'jane', 'active' => 'No', 'score' => '85'], $result[1]);
    }

    public function testLazyGenerator(): void
    {
        $data = [
            ['name' => ' john '],
            ['name' => ' jane '],
        ];

        $gen = Transform::trim($data);
        self::assertInstanceOf(Generator::class, $gen);

        $first = $gen->current();
        self::assertEquals(['name' => 'john'], $first);

        $gen->next();
        $second = $gen->current();
        self::assertEquals(['name' => 'jane'], $second);
    }

    public function testCastStrictInt(): void
    {
        $data = [
            ['qty' => '42', 'name' => 'item'],
        ];

        $result = iterator_to_array(Transform::castStrict($data, ['qty' => 'int']));
        self::assertSame(42, $result[0]['qty']);
    }

    public function testCastStrictInvalidIntThrows(): void
    {
        $data = [
            ['qty' => 'abc'],
        ];

        $this->expectException(InvalidRowException::class);
        $this->expectExceptionMessage('Row 0: Column \'qty\' value \'abc\' is not a valid integer');

        iterator_to_array(Transform::castStrict($data, ['qty' => 'int']));
    }

    public function testCastStrictInvalidFloatThrows(): void
    {
        $data = [
            ['price' => 'not-a-number'],
        ];

        $this->expectException(InvalidRowException::class);
        $this->expectExceptionMessage('Row 0: Column \'price\' value \'not-a-number\' is not a valid float');

        iterator_to_array(Transform::castStrict($data, ['price' => 'float']));
    }

    public function testCastStrictInvalidBoolThrows(): void
    {
        $data = [
            ['active' => 'perhaps'],
        ];

        $this->expectException(InvalidRowException::class);
        $this->expectExceptionMessage('Row 0: Column \'active\' value \'perhaps\' is not a valid boolean');

        iterator_to_array(Transform::castStrict($data, ['active' => 'bool']));
    }

    public function testCastStrictValidBool(): void
    {
        $data = [
            ['active' => 'false', 'flag' => '1'],
        ];

        $result = iterator_to_array(Transform::castStrict($data, ['active' => 'bool', 'flag' => 'bool']));
        self::assertFalse($result[0]['active']);
        self::assertTrue($result[0]['flag']);
    }

    public function testCastStrictInvalidDateThrows(): void
    {
        $data = [
            ['created' => 'clearly-not-a-date'],
        ];

        $this->expectException(InvalidRowException::class);
        $this->expectExceptionMessage('Row 0: Column \'created\' value \'clearly-not-a-date\' is not a valid date');

        iterator_to_array(Transform::castStrict($data, ['created' => 'date']));
    }

    public function testCastStrictNullableAllowsNull(): void
    {
        $data = [
            ['qty' => null, 'price' => ''],
        ];

        $result = iterator_to_array(Transform::castStrict($data, ['qty' => '?int', 'price' => '?float']));
        self::assertNull($result[0]['qty']);
        self::assertNull($result[0]['price']);
    }

    public function testCastStrictNullableInvalidThrows(): void
    {
        $data = [
            ['qty' => 'abc'],
        ];

        $this->expectException(InvalidRowException::class);
        $this->expectExceptionMessage('is not a valid integer');

        iterator_to_array(Transform::castStrict($data, ['qty' => '?int']));
    }

    public function testCastStrictUnknownTypeThrows(): void
    {
        $data = [
            ['col' => 'value'],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown type');

        iterator_to_array(Transform::castStrict($data, ['col' => 'unknown']));
    }

    public function testCastStrictByIndex(): void
    {
        $data = [
            ['42', 'item'],
        ];

        $result = iterator_to_array(Transform::castStrict($data, [0 => 'int']));
        self::assertSame(42, $result[0][0]);
        self::assertSame('item', $result[0][1]);
    }

    public function testCastStrictEmptyStringNonNullableThrows(): void
    {
        $data = [
            ['qty' => ''],
        ];

        $this->expectException(InvalidRowException::class);
        $this->expectExceptionMessage('cannot be null/empty');

        iterator_to_array(Transform::castStrict($data, ['qty' => 'int']));
    }

    public function testCastInvalidDateReturnsNull(): void
    {
        $data = [
            ['created' => 'not-a-valid-date'],
        ];

        $result = iterator_to_array(Transform::cast($data, ['created' => '?date']));
        self::assertNull($result[0]['created']);
    }

    public function testCastDateErrorPathReturnsNull(): void
    {
        $data = [
            ['created' => 'not-a-valid-date'],
        ];

        $result = iterator_to_array(Transform::cast($data, ['created' => 'date']));
        self::assertNull($result[0]['created']);
    }
}
