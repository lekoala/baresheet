<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

interface WriterInterface
{
    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     * @return resource The opened stream containing the data. It is the caller's responsibility to close it.
     */
    public function writeStream(
        iterable $data,
    );

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeString(
        iterable $data,
    ): string;

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function writeFile(
        iterable $data,
        string $filename,
    ): bool;

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function output(
        iterable $data,
        string $filename,
    ): void;

    /**
     * @param iterable<array<float|int|string|\Stringable|null>> $data
     */
    public function outputStream(
        iterable $data,
        string $filename,
    ): void;
}
