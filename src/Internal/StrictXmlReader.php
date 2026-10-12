<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Internal;

use LeKoala\Baresheet\Exception\InvalidDocumentException;
use LeKoala\Baresheet\Spread;
use XMLReader;

/**
 * XMLReader that fails loudly on truncated or corrupt XML.
 *
 * Plain XMLReader::read()/next()/readString() behave the same on parse errors
 * as on clean EOF (false, or partial data from readString), so a truncated
 * worksheet yields silent partial rows. This subclass routes libxml diagnostics
 * to the internal queue — no PHP warnings — and throws
 * InvalidDocumentException whenever the queue is non-empty. The previous flag
 * is restored before returning, so a suspended generator never leaks global
 * libxml state across yields. The queue is drained before each operation so a
 * stale error from other XML processing is not attributed to this document
 * (a compromise: the caller's own queued errors are discarded too, since
 * libxml errors are process-global).
 *
 * Call sites keep plain `$reader->read()` shapes on purpose: only direct
 * method calls invalidate the reader state for static analysis.
 *
 * @internal
 */
final class StrictXmlReader extends XMLReader
{
    public function __construct(
        private readonly string $xmlError = 'Failed to parse XML (truncated or corrupt document)',
    ) {
    }

    /**
     * Open a staged entry or data:// URI without libxml PHP warnings.
     *
     * Declared as XMLReader: callers only drive the base API, and keeping the
     * base type preserves the reader-state invalidation static analysis
     * applies to direct read()/next() calls.
     *
     * @throws InvalidDocumentException When the stream cannot be opened.
     */
    public static function openFile(string $uri, string $xmlError, ?string $openError = null): \XMLReader
    {
        $reader = new self($xmlError);
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$reader->open($uri, null, LIBXML_NONET)) {
                libxml_clear_errors();
                throw new InvalidDocumentException($openError ?? $xmlError);
            }
            libxml_clear_errors();
            return $reader;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function read(): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // Drain the global queue first so a stale error left by other XML
            // processing is not attributed to this document. Trade-off: this
            // also discards the caller's own queued errors — libxml errors are
            // process-global and cannot be fully isolated.
            libxml_clear_errors();
            $moved = parent::read();
            if ($moved === false) {
                Spread::throwOnXmlErrors($this->xmlError);
            } else {
                libxml_clear_errors();
            }
            return $moved;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function next(?string $name = null): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // Same pre-drain as read(): only errors born during this call count.
            libxml_clear_errors();
            $moved = parent::next($name);
            if ($moved === false) {
                Spread::throwOnXmlErrors($this->xmlError);
            } else {
                libxml_clear_errors();
            }
            return $moved;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function readString(): string
    {
        $previous = libxml_use_internal_errors(true);
        try {
            // Unlike read()/next(), a corrupt tail surfaces here while the
            // outer loop would otherwise continue, so the queue is checked on
            // every call rather than only on failure. Pre-drained for the same
            // reason as read().
            libxml_clear_errors();
            $value = parent::readString();
            Spread::throwOnXmlErrors($this->xmlError);
            return $value;
        } finally {
            libxml_use_internal_errors($previous);
        }
    }
}
