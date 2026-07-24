<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Generator;

/**
 * @phpstan-type Row array<int|string, mixed>
 */
interface ReaderInterface
{
    /**
     * @return Generator<int, Row>
     */
    public function readString(
        string $contents,
    ): Generator;

    /**
     * @return Generator<int, Row>
     */
    public function readFile(
        string $filename,
    ): Generator;
}
