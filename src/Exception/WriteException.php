<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Exception;

/**
 * A write operation failed: unwritable destination, stream/ZIP failure,
 * or a missing capability needed to produce the requested output.
 */
class WriteException extends BaresheetException
{
}
