<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Model;

use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JsonLdDocumentTest extends TestCase
{
    #[Test]
    public function toJsonLdReturnsValidJsonWithContext(): void
    {
        $document = new JsonLdDocument('Article', [
            'headline' => 'Test Article',
            'description' => 'A test description',
        ]);

        $jsonLd = $document->toJsonLd();
        $decoded = json_decode($jsonLd, true);

        self::assertSame('https://schema.org', $decoded['@context']);
        self::assertSame('Article', $decoded['@type']);
        self::assertSame('Test Article', $decoded['headline']);
        self::assertSame('A test description', $decoded['description']);
    }

    #[Test]
    public function toScriptTagWrapsInScriptElement(): void
    {
        $document = new JsonLdDocument('Product', ['name' => 'Widget']);

        $scriptTag = $document->toScriptTag();

        self::assertStringStartsWith('<script type="application/ld+json">', $scriptTag);
        self::assertStringEndsWith('</script>', $scriptTag);
        self::assertStringContainsString('"@type": "Product"', $scriptTag);
    }
}
