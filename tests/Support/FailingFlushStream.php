<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests\Support;

/**
 * In-memory user-space stream whose stream_flush() always fails.
 *
 * Used to prove that DirectZipWriter::finish() reports flush failures instead
 * of claiming success. Registered as a stream wrapper by the test.
 *
 * @internal
 */
final class FailingFlushStream
{
    /** @var resource */
    public $context;

    private string $data = '';
    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (str_starts_with($mode, 'w')) {
            $this->data = '';
            $this->position = 0;
        }
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->data .= $data;
        $this->position = strlen($this->data);
        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->data, $this->position, $count);
        $this->position += strlen($chunk);
        return $chunk;
    }

    public function stream_seek(int $offset, int $whence): bool
    {
        $target = match ($whence) {
            SEEK_END => strlen($this->data) + $offset,
            SEEK_CUR => $this->position + $offset,
            default => $offset,
        };
        if ($target < 0) {
            return false;
        }

        $this->position = $target;
        return true;
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->data);
    }

    public function stream_flush(): bool
    {
        return false;
    }

    public function stream_stat(): array
    {
        return ['size' => strlen($this->data)];
    }

    public function stream_close(): void
    {
    }
}
