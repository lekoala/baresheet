<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Exception;

/**
 * A document could not be read or interpreted: corrupt ZIP, invalid XML,
 * missing internal structure, or content that doesn't match its format.
 */
class InvalidDocumentException extends BaresheetException {}
