<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

interface WriterInterface
{
    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeString(
        iterable $data,
        ?Options $options = null,
    ): string;

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeFile(
        iterable $data,
        string $filename,
        ?Options $options = null,
    ): bool;

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function output(
        iterable $data,
        string $filename,
        ?Options $options = null,
    ): void;

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function outputStream(
        iterable $data,
        string $filename,
        ?Options $options = null,
    ): void;
}
