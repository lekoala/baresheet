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
}
