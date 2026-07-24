<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

use Generator;

interface ReaderInterface
{
    /**
     * @return Generator<mixed>
     */
    public function readString(
        string $contents,
    ): Generator;

    /**
     * @return Generator<mixed>
     */
    public function readFile(
        string $filename,
    ): Generator;
}
