<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Exception;

/**
 * One or more expected columns (required, or explicitly selected) are
 * absent from the document's headers.
 */
class MissingColumnException extends BaresheetException
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly array $columns,
    ) {
        parent::__construct('Missing required columns: ' . implode(', ', $columns));
    }
}
