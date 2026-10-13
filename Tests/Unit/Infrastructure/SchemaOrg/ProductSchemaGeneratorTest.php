<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\ProductSchemaGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProductSchemaGeneratorTest extends TestCase
{
    private ProductSchemaGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new ProductSchemaGenerator();
    }

    #[Test]
    public function canHandleReturnsTrueForProductKeywordWithOffers(): void
    {
        self::assertTrue($this->generator->canHandle(
            [
                'keywords' => 'product, sale',
                'offers' => ['@type' => 'Offer', 'price' => '99', 'priceCurrency' => 'EUR'],
            ],
            [],
        ));
    }

    #[Test]
    public function canHandleReturnsTrueForGermanKeywordWithAggregateRating(): void
    {
        self::assertTrue($this->generator->canHandle(
            [
                'keywords' => 'Produkt',
                'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '4.5', 'reviewCount' => 10],
            ],
            [],
        ));
    }

    #[Test]
    public function canHandleReturnsFalseWithoutProductKeyword(): void
    {
        self::assertFalse($this->generator->canHandle(['keywords' => 'blog, news'], []));
    }

    #[Test]
    public function canHandleReturnsFalseWhenKeywordIsSubstringOnly(): void
    {
        // "Produktivitaet" / "productive" duerfen NICHT triggern.
        self::assertFalse($this->generator->canHandle(
            [
                'keywords' => 'produktivitaet, productive',
                'offers' => ['price' => '1'],
            ],
            [],
        ));
    }

    #[Test]
    public function canHandleReturnsFalseWhenServiceCTypeIsPresent(): void
    {
        // Service-Pages haben Vorrang fuer ServiceSchemaGenerator.
        self::assertFalse($this->generator->canHandle(
            [
                'keywords' => 'produkt',
                'offers' => ['price' => '99'],
            ],
            [['CType' => 'moselwal_service_showcase']],
        ));
    }

    #[Test]
    public function canHandleReturnsFalseWhenSlugIsServicePath(): void
    {
        self::assertFalse($this->generator->canHandle(
            [
                'keywords' => 'produkt',
                'slug' => '/leistungen/automatisierung',
                'offers' => ['price' => '99'],
            ],
            [],
        ));
    }

    #[Test]
    public function canHandleReturnsFalseWhenNoOffersOrReviewOrAggregateRating(): void
    {
        self::assertFalse($this->generator->canHandle(
            ['keywords' => 'product, sale'],
            [],
        ));
    }

    #[Test]
    public function generateCreatesProductDocumentWithOffers(): void
    {
        $document = $this->generator->generate(
            [
                'title' => 'Widget Pro',
                'description' => 'A great widget',
                'keywords' => 'product',
                'offers' => ['@type' => 'Offer', 'price' => '99', 'priceCurrency' => 'EUR'],
            ],
            [],
        );

        self::assertSame('Product', $document->schemaType);
        $data = json_decode($document->toJsonLd(), true);
        self::assertSame('Widget Pro', $data['name']);
        self::assertSame('A great widget', $data['description']);
        self::assertArrayHasKey('offers', $data);
        self::assertSame('99', $data['offers']['price']);
    }
}
