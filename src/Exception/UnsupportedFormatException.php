<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Exception;

/**
 * The requested format/extension is not one Baresheet knows how to read or write.
 */
class UnsupportedFormatException extends BaresheetException {}
