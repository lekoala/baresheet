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
        ?Options $options = null,
    ): Generator;

    /**
     * @return Generator<mixed>
     */
    public function readFile(
        string $filename,
        ?Options $options = null,
    ): Generator;
}
