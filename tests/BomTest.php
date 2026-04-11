<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use PHPUnit\Framework\TestCase;
use LeKoala\Baresheet\Bom;
use ValueError;

class BomTest extends TestCase
{
    public function testFromSequenceReturnsBomForValidSequence(): void
    {
        $bom = Bom::fromSequence("\xEF\xBB\xBF");
        self::assertSame(Bom::Utf8, $bom);

        $bom = Bom::fromSequence("\xFE\xFF");
        self::assertSame(Bom::Utf16Be, $bom);

        $bom = Bom::fromSequence("\xFF\xFE");
        self::assertSame(Bom::Utf16Le, $bom);

        $bom = Bom::fromSequence("\x00\x00\xFE\xFF");
        self::assertSame(Bom::Utf32Be, $bom);

        $bom = Bom::fromSequence("\xFF\xFE\x00\x00");
        self::assertSame(Bom::Utf32Le, $bom);
    }

    public function testFromSequenceThrowsExceptionForInvalidSequence(): void
    {
        $this->expectException(ValueError::class);
        $this->expectExceptionMessage('No recognized BOM sequence found in string.');
        Bom::fromSequence("not a bom");
    }

    public function testLength(): void
    {
        self::assertSame(3, Bom::Utf8->length());
        self::assertSame(2, Bom::Utf16Be->length());
        self::assertSame(2, Bom::Utf16Le->length());
        self::assertSame(4, Bom::Utf32Be->length());
        self::assertSame(4, Bom::Utf32Le->length());
    }

    public function testEncoding(): void
    {
        self::assertSame('UTF-8', Bom::Utf8->encoding());
        self::assertSame('UTF-16BE', Bom::Utf16Be->encoding());
        self::assertSame('UTF-16LE', Bom::Utf16Le->encoding());
        self::assertSame('UTF-32BE', Bom::Utf32Be->encoding());
        self::assertSame('UTF-32LE', Bom::Utf32Le->encoding());
    }

    public function testTypeChecks(): void
    {
        self::assertTrue(Bom::Utf8->isUtf8());
        self::assertFalse(Bom::Utf8->isUtf16());
        self::assertFalse(Bom::Utf8->isUtf32());

        self::assertFalse(Bom::Utf16Be->isUtf8());
        self::assertTrue(Bom::Utf16Be->isUtf16());
        self::assertFalse(Bom::Utf16Be->isUtf32());

        self::assertFalse(Bom::Utf16Le->isUtf8());
        self::assertTrue(Bom::Utf16Le->isUtf16());
        self::assertFalse(Bom::Utf16Le->isUtf32());

        self::assertFalse(Bom::Utf32Be->isUtf8());
        self::assertFalse(Bom::Utf32Be->isUtf16());
        self::assertTrue(Bom::Utf32Be->isUtf32());

        self::assertFalse(Bom::Utf32Le->isUtf8());
        self::assertFalse(Bom::Utf32Le->isUtf16());
        self::assertTrue(Bom::Utf32Le->isUtf32());
    }

    public function testTryFromSequence(): void
    {
        self::assertSame(Bom::Utf8, Bom::tryFromSequence("\xEF\xBB\xBF"));
        self::assertSame(Bom::Utf32Be, Bom::tryFromSequence("\x00\x00\xFE\xFF"));
        self::assertSame(Bom::Utf16Be, Bom::tryFromSequence("\xFE\xFF"));
        self::assertNull(Bom::tryFromSequence("not a bom"));
    }
}
