<?php

declare(strict_types=1);

namespace LeKoala\Baresheet\Tests;

use LeKoala\Baresheet\Meta;
use PHPUnit\Framework\TestCase;

class MetaTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $meta = new Meta();
        self::assertNull($meta->title);
        self::assertNull($meta->subject);
        self::assertSame('Baresheet', $meta->creator);
        self::assertNull($meta->keywords);
        self::assertNull($meta->description);
        self::assertNull($meta->category);
        self::assertSame('en-US', $meta->language);
    }

    public function testCustomValues(): void
    {
        $meta = new Meta(
            title: 'T',
            subject: 'S',
            creator: 'C',
            keywords: 'K',
            description: 'D',
            category: 'Cat',
            language: 'fr-FR',
        );
        self::assertSame('T', $meta->title);
        self::assertSame('S', $meta->subject);
        self::assertSame('C', $meta->creator);
        self::assertSame('K', $meta->keywords);
        self::assertSame('D', $meta->description);
        self::assertSame('Cat', $meta->category);
        self::assertSame('fr-FR', $meta->language);
    }

    public function testFromArrayWithAllKeys(): void
    {
        $meta = Meta::fromArray([
            'title' => 'T',
            'subject' => 'S',
            'creator' => 'C',
            'keywords' => 'K',
            'description' => 'D',
            'category' => 'Cat',
            'language' => 'fr-FR',
        ]);
        self::assertSame('T', $meta->title);
        self::assertSame('S', $meta->subject);
        self::assertSame('C', $meta->creator);
        self::assertSame('K', $meta->keywords);
        self::assertSame('D', $meta->description);
        self::assertSame('Cat', $meta->category);
        self::assertSame('fr-FR', $meta->language);
    }

    public function testFromArrayWithMissingKeys(): void
    {
        $meta = Meta::fromArray(['title' => 'OnlyTitle']);
        self::assertSame('OnlyTitle', $meta->title);
        self::assertNull($meta->subject);
        self::assertSame('Baresheet', $meta->creator);
        self::assertNull($meta->keywords);
        self::assertNull($meta->description);
        self::assertNull($meta->category);
        self::assertSame('en-US', $meta->language);
    }

    public function testFromArrayWithEmptyArray(): void
    {
        $meta = Meta::fromArray([]);
        self::assertNull($meta->title);
        self::assertSame('Baresheet', $meta->creator);
        self::assertSame('en-US', $meta->language);
    }

    public function testFromArrayIgnoresNonScalarValues(): void
    {
        $meta = Meta::fromArray([
            'title' => ['array'],
            'creator' => new \stdClass(),
            'language' => null,
        ]);
        self::assertNull($meta->title);
        self::assertSame('Baresheet', $meta->creator);
        self::assertSame('en-US', $meta->language);
    }

    public function testFromArrayCastsScalarNonStrings(): void
    {
        $meta = Meta::fromArray([
            'creator' => 123,
            'language' => true,
            'title' => 45.67,
        ]);
        self::assertSame('123', $meta->creator);
        self::assertSame('1', $meta->language);
        self::assertSame('45.67', $meta->title);
    }
}
