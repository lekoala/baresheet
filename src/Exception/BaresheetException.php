<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Exception;

use RuntimeException;

/**
 * Root exception for errors originating from a document or a Baresheet
 * read/write operation. API misuse (bad arguments, invalid config) stays
 * as native InvalidArgumentException/LogicException instead.
 */
class BaresheetException extends RuntimeException {}
