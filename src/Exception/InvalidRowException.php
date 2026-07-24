<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Exception;

use Throwable;

/**
 * A single row's data violates a constraint: wrong column count under
 * strict mode, or an invalid value during a strict-mode cast.
 */
class InvalidRowException extends BaresheetException
{
    public function __construct(
        string $message,
        public readonly ?int $row = null,
        public readonly ?string $column = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
