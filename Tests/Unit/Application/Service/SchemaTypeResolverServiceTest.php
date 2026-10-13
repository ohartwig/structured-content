<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaTypeResolverServiceTest extends TestCase
{
    private SchemaTypeResolverService $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SchemaTypeResolverService();
    }

    #[Test]
    #[DataProvider('familyProvider')]
    public function getFamilyClassifiesAllSupportedTypes(string $schemaType, string $expectedFamily): void
    {
        self::assertSame($expectedFamily, $this->resolver->getFamily($schemaType));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function familyProvider(): iterable
    {
        yield 'Article → article' => ['Article', 'article'];
        yield 'BlogPosting → article' => ['BlogPosting', 'article'];
        yield 'NewsArticle → article' => ['NewsArticle', 'article'];
        yield 'TechArticle → article' => ['TechArticle', 'article'];
        yield 'ScholarlyArticle → article' => ['ScholarlyArticle', 'article'];
        yield 'Report → article' => ['Report', 'article'];
        yield 'SocialMediaPosting → article' => ['SocialMediaPosting', 'article'];
        yield 'WebPage → webpage' => ['WebPage', 'webpage'];
        yield 'AboutPage → webpage' => ['AboutPage', 'webpage'];
        yield 'ContactPage → webpage' => ['ContactPage', 'webpage'];
        yield 'CollectionPage → webpage' => ['CollectionPage', 'webpage'];
        yield 'ItemPage → webpage' => ['ItemPage', 'webpage'];
        yield 'ProfilePage → webpage' => ['ProfilePage', 'webpage'];
        yield 'QAPage → webpage' => ['QAPage', 'webpage'];
        yield 'Product → other' => ['Product', 'other'];
        yield 'FAQPage → other' => ['FAQPage', 'other'];
        yield 'Service → other' => ['Service', 'other'];
        yield 'unknown → other' => ['SomethingNew', 'other'];
    }

    #[Test]
    public function rootPageReturnsNull(): void
    {
        $page = $this->page(['uid' => 1, '_rootPageId' => 1, 'slug' => '/']);
        self::assertNull($this->resolver->resolve($page, []));
    }

    #[Test]
    public function manualOverrideForArticleFamilyTakesPrecedence(): void
    {
        $page = $this->page([
            'tx_structuredcontent_schema_type' => 'TechArticle',
            'slug' => '/blog/foo',
            'doktype' => 1,
        ]);
        self::assertSame('TechArticle', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function manualOverrideForWebPageFamilyTakesPrecedence(): void
    {
        $page = $this->page([
            'tx_structuredcontent_schema_type' => 'AboutPage',
            'slug' => '/blog/foo',
            'doktype' => 1,
        ]);
        self::assertSame('AboutPage', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function manualOverrideForForeignTypeReturnsNull(): void
    {
        $page = $this->page(['tx_structuredcontent_schema_type' => 'Product']);
        self::assertNull($this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function legacyOverrideFieldFallsBackWhenNewFieldEmpty(): void
    {
        $page = $this->page([
            'tx_structuredcontent_schema_type' => '',
            'tx_semanticdelivery_schema_type' => 'BlogPosting',
            'slug' => '/whatever',
            'doktype' => 1,
        ]);
        self::assertSame('BlogPosting', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function newFieldWinsOverLegacyField(): void
    {
        $page = $this->page([
            'tx_structuredcontent_schema_type' => 'NewsArticle',
            'tx_semanticdelivery_schema_type' => 'BlogPosting',
            'slug' => '/whatever',
            'doktype' => 1,
        ]);
        self::assertSame('NewsArticle', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function ctypeHintBlogHeroReturnsBlogPosting(): void
    {
        $page = $this->page(['slug' => '/anything', 'doktype' => 1]);
        self::assertSame(
            'BlogPosting',
            $this->resolver->resolve($page, $this->ce(['CType' => 'moselwal_bloghero'])),
        );
    }

    #[Test]
    public function ctypeHintOleHeroReturnsAboutPage(): void
    {
        $page = $this->page(['slug' => '/start', 'doktype' => 1]);
        self::assertSame(
            'AboutPage',
            $this->resolver->resolve($page, $this->ce(['CType' => 'moselwal_olehero'])),
        );
    }

    #[Test]
    public function ctypeHintTeaserReturnsNewsArticle(): void
    {
        $page = $this->page(['slug' => '/x', 'doktype' => 1]);
        self::assertSame(
            'NewsArticle',
            $this->resolver->resolve($page, $this->ce(['CType' => 'moselwal_teaser'])),
        );
    }

    #[Test]
    public function ctypeHintFirstHeroWins(): void
    {
        $page = $this->page(['slug' => '/x', 'doktype' => 1]);
        $elements = [
            ['CType' => 'moselwal_pagehero', 'uid' => 1, 'bodytext' => ''],
            ['CType' => 'moselwal_bloghero', 'uid' => 2, 'bodytext' => ''],
        ];
        self::assertSame('Article', $this->resolver->resolve($page, $elements));
    }

    #[Test]
    public function slugBlogIndexBeatsHeroCType(): void
    {
        $page = $this->page(['slug' => '/blog', 'doktype' => 1]);
        self::assertSame(
            'CollectionPage',
            $this->resolver->resolve($page, $this->ce(['CType' => 'moselwal_pagehero'])),
        );
    }

    #[Test]
    public function slugBlogIndexReturnsCollectionPage(): void
    {
        $page = $this->page(['slug' => '/blog', 'doktype' => 1]);
        self::assertSame('CollectionPage', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function slugBlogDetailReturnsBlogPosting(): void
    {
        $page = $this->page(['slug' => '/blog/some-post', 'doktype' => 1]);
        self::assertSame('BlogPosting', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function slugNewsDetailReturnsNewsArticle(): void
    {
        $page = $this->page(['slug' => '/news/some-headline', 'doktype' => 1]);
        self::assertSame('NewsArticle', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function slugPresseIndexReturnsCollectionPage(): void
    {
        $page = $this->page(['slug' => '/presse', 'doktype' => 1]);
        self::assertSame('CollectionPage', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function slugReferenzenIndexReturnsCollectionPage(): void
    {
        $page = $this->page(['slug' => '/referenzen', 'doktype' => 1]);
        self::assertSame('CollectionPage', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function slugReferenzenDetailReturnsItemPage(): void
    {
        $page = $this->page(['slug' => '/referenzen/case-study-x', 'doktype' => 1]);
        self::assertSame('ItemPage', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function slugKontaktNoLongerMatched(): void
    {
        $page = $this->page(['slug' => '/kontakt', 'doktype' => 1]);
        self::assertSame('Article', $this->resolver->resolve($page, $this->ce(['CType' => 'text'])));
    }

    #[Test]
    public function keywordsTechReturnsTechArticle(): void
    {
        $page = $this->page(['slug' => '/articles/foo', 'keywords' => 'technical guide', 'doktype' => 1]);
        self::assertSame('TechArticle', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function keywordsScholarlyReturnsScholarlyArticle(): void
    {
        $page = $this->page(['slug' => '/papers/foo', 'keywords' => 'whitepaper study', 'doktype' => 1]);
        self::assertSame('ScholarlyArticle', $this->resolver->resolve($page, $this->ce()));
    }

    #[Test]
    public function defaultArticleForStandardPageWithContent(): void
    {
        $page = $this->page(['slug' => '/whatever', 'doktype' => 1]);
        self::assertSame('Article', $this->resolver->resolve($page, $this->ce(['CType' => 'text'])));
    }

    #[Test]
    public function defaultWebPageForNonArticleDoktype(): void
    {
        $page = $this->page(['slug' => '/whatever', 'doktype' => 4]);
        self::assertSame('WebPage', $this->resolver->resolve($page, $this->ce(['CType' => 'text'])));
    }

    #[Test]
    public function emptyContentElementsAndNoSlugMatchReturnsNull(): void
    {
        $page = $this->page(['slug' => '/whatever', 'doktype' => 1]);
        self::assertNull($this->resolver->resolve($page, []));
    }

    #[Test]
    public function blogDetailWithFaqSectionStillReturnsBlogPosting(): void
    {
        // FAQ-Sektion auf Blog-Page darf BlogPosting NICHT blockieren —
        // beide Schemas (BlogPosting + FAQPage) sind parallel gewollt.
        $page = $this->page(['slug' => '/blog/some-post', 'doktype' => 1]);
        $elements = [
            ['CType' => 'text', 'uid' => 1, 'bodytext' => 'Article body'],
            ['CType' => 'moselwal_faq', 'uid' => 2, 'bodytext' => ''],
        ];
        self::assertSame('BlogPosting', $this->resolver->resolve($page, $elements));
    }

    #[Test]
    public function pageWithServiceContentReturnsNull(): void
    {
        $page = $this->page(['slug' => '/something', 'doktype' => 1]);
        self::assertNull(
            $this->resolver->resolve($page, $this->ce(['CType' => 'moselwal_servicecard'])),
        );
    }

    #[Test]
    public function pageWithLeistungenSlugReturnsNull(): void
    {
        $page = $this->page(['slug' => '/leistungen/ai-ready', 'doktype' => 1]);
        self::assertNull($this->resolver->resolve($page, $this->ce(['CType' => 'text'])));
    }

    #[Test]
    public function pageWithPodcastFeedReturnsNull(): void
    {
        $page = $this->page(['slug' => '/podcast', 'doktype' => 1]);
        self::assertNull(
            $this->resolver->resolve($page, $this->ce(['CType' => 'moselwal_videopodcastfeed'])),
        );
    }

    #[Test]
    public function pageWithPackagesGridReturnsNull(): void
    {
        $page = $this->page(['slug' => '/produkte/cms', 'doktype' => 1]);
        self::assertNull(
            $this->resolver->resolve($page, $this->ce(['CType' => 'moselwal_packagesgrid'])),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function page(array $overrides): array
    {
        return array_merge([
            'uid' => 42,
            '_rootPageId' => 1,
            'doktype' => 1,
            'slug' => '/',
            'tx_structuredcontent_schema_type' => '',
            'tx_semanticdelivery_schema_type' => '',
            'keywords' => '',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return list<array<string, mixed>>
     */
    private function ce(array $element = ['CType' => 'text']): array
    {
        return [array_merge(['uid' => 1, 'header' => '', 'bodytext' => ''], $element)];
    }
}
