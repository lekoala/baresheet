<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

/**
 * @phpstan-type WritableCell float|int|string|\Stringable|null
 * @phpstan-type WritableRow array<int|string, WritableCell>
 */
interface WriterInterface
{
    /**
     * @param iterable<WritableRow> $data
     * @return resource The opened stream containing the data. It is the caller's responsibility to close it.
     */
    public function writeStream(
        iterable $data,
    );

    /**
     * @param iterable<WritableRow> $data
     */
    public function writeString(
        iterable $data,
    ): string;

    /**
     * @param iterable<WritableRow> $data
     */
    public function writeFile(
        iterable $data,
        string $filename,
    ): bool;

    /**
     * @param iterable<WritableRow> $data
     */
    public function output(
        iterable $data,
        string $filename,
    ): void;

    /**
     * @param iterable<WritableRow> $data
     */
    public function outputStream(
        iterable $data,
        string $filename,
    ): void;
}
