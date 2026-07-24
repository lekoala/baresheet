<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Exception;

/**
 * The requested sheet name/index does not exist in an otherwise valid document.
 */
class SheetNotFoundException extends InvalidDocumentException {}
