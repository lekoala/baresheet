<?php

declare(strict_types=1);

namespace LeKoala\Baresheet;

/**
 * Metadata DTO for Baresheet spreadsheets.
 */
class Meta
{
    public function __construct(
        public ?string $title = null,
        public ?string $subject = null,
        public string $creator = 'Baresheet',
        public ?string $keywords = null,
        public ?string $description = null,
        public ?string $category = null,
        public string $language = 'en-US',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $getString = static fn(string $k): ?string => isset($data[$k]) && is_scalar($data[$k]) ? (string)$data[$k] : null;

        return new self(
            title: $getString('title'),
            subject: $getString('subject'),
            creator: $getString('creator') ?? 'Baresheet',
            keywords: $getString('keywords'),
            description: $getString('description'),
            category: $getString('category'),
            language: $getString('language') ?? 'en-US',
        );
    }
}
