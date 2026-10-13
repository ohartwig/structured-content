<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use Koh\StructuredContent\Domain\Contract\AuthoredPagesRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\CategoryRepositoryInterface;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ArticleSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\EntityBase;
use Koh\StructuredContent\Infrastructure\SchemaOrg\OrganizationSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ProfilePageSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\WebPageSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\WebSiteSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Site-wide entities carry the same @id in every language.
 *
 * Organization, WebSite and the Person nodes exist once per site. When an
 * English page built their ids from the language base (`/en#organization`),
 * it declared entities no other page references, while its own references
 * pointed nowhere. Page-scoped ids (WebPage, BlogPosting) keep the language
 * base: they identify the page in that language.
 */
final class LanguageNeutralEntityIdsTest extends TestCase
{
    use PodcastFeedDoubles;

    private const string ROOT = 'https://example.com';

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 0; $i < 64; ++$i) {
            GeneralUtility::addInstance(TcaSchemaFactory::class, $this->createStub(TcaSchemaFactory::class));
            GeneralUtility::addInstance(
                FrontendRestrictionContainer::class,
                $this->createStub(FrontendRestrictionContainer::class),
            );
            GeneralUtility::addInstance(ConnectionPool::class, $this->connectionPool());
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /**
     * An English page as the JSON-LD middleware hands it to the generators.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function englishPage(array $overrides = []): array
    {
        return [
            'uid' => 42,
            '_rootPageId' => 1,
            'title' => 'How search engines read podcasts',
            'description' => 'An introduction to structured data.',
            'slug' => '/blog/structured-data',
            '_siteBase' => 'https://example.com/en/',
            '_entityBase' => 'https://example.com/',
            '_language' => 'en-GB',
            '_siteSettings' => [
                'footerBrandName' => 'Example',
                'structuredContent' => ['schema' => ['founderPersonSlug' => 'ada-lovelace']],
            ],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(): array
    {
        return [
            'uid' => 7,
            'uid_local' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
        ];
    }

    #[Test]
    public function testEntityBaseIsTheDefaultLanguageBaseWithoutTrailingSlash(): void
    {
        self::assertSame(self::ROOT, EntityBase::fromPageData($this->englishPage()));
    }

    #[Test]
    public function testEntityBaseFallsBackToTheSiteBaseWhenNotProvided(): void
    {
        self::assertSame('https://example.com/en', EntityBase::fromPageData(['_siteBase' => 'https://example.com/en/']));
        self::assertSame('', EntityBase::fromPageData([]));
    }

    #[Test]
    public function testOrganizationAndFounderIdsCarryNoLanguagePrefix(): void
    {
        $document = (new OrganizationSchemaGenerator())->generate($this->englishPage(), []);

        self::assertSame(self::ROOT . '#organization', $document->data['@id']);
        self::assertSame(
            ['@type' => 'Person', '@id' => self::ROOT . '/autor/ada-lovelace#person'],
            $document->data['founder'],
        );
    }

    #[Test]
    public function testWebSiteIdIsLanguageNeutralWhileItsUrlStaysTheLanguageHome(): void
    {
        $document = (new WebSiteSchemaGenerator())->generate($this->englishPage(), []);

        self::assertSame(self::ROOT . '#website', $document->data['@id']);
        self::assertSame(['@id' => self::ROOT . '#organization'], $document->data['publisher']);
        self::assertSame('https://example.com/en/', $document->data['url']);
    }

    #[Test]
    public function testWebPageKeepsItsOwnIdButReferencesTheSharedWebSite(): void
    {
        $document = (new WebPageSchemaGenerator(new SchemaTypeResolverService()))->generate($this->englishPage(), []);

        self::assertSame('https://example.com/en/blog/structured-data#webpage', $document->data['@id']);
        self::assertSame(['@id' => self::ROOT . '#website'], $document->data['isPartOf']);
    }

    #[Test]
    public function testArticleReferencesSharedEntitiesButKeepsItsLanguageScopedId(): void
    {
        $this->rows = [$this->personRow()];
        $generator = new ArticleSchemaGenerator(
            new SchemaTypeResolverService(),
            new PersonSchemaBuilder($this->connectionPool()),
            $this->createStub(CategoryRepositoryInterface::class),
        );

        $document = $generator->generate(
            $this->englishPage([
                'tx_structuredcontent_schema_type' => 'BlogPosting',
                'tx_structuredcontent_author_records' => '7',
            ]),
            [],
        );

        self::assertSame('https://example.com/en/blog/structured-data#blogposting', $document->data['@id']);
        self::assertSame(['@id' => self::ROOT . '#organization'], $document->data['publisher']);
        self::assertSame(['@id' => self::ROOT . '#website'], $document->data['isPartOf']);
        self::assertSame(self::ROOT . '/autor/ada-lovelace#person', $document->data['author']['@id']);
    }

    #[Test]
    public function testProfilePageMainEntityIsTheSharedPerson(): void
    {
        $this->rows = [$this->personRow()];
        $authored = $this->createStub(AuthoredPagesRepositoryInterface::class);
        $authored->method('findByAuthorUid')->willReturn([]);
        $generator = new ProfilePageSchemaGenerator(new PersonSchemaBuilder($this->connectionPool()), $authored);

        $document = $generator->generate(
            $this->englishPage([
                '_routingArguments' => ['tx_contentblockscollection_person' => ['person' => 'ada-lovelace']],
            ]),
            [],
        );

        self::assertSame(['@id' => self::ROOT . '/autor/ada-lovelace#person'], $document->data['mainEntity']);
        self::assertSame(['@id' => self::ROOT . '#website'], $document->data['isPartOf']);
    }

    #[Test]
    public function testPersonBuiltFromTheEntityBaseHasNoLanguagePrefix(): void
    {
        $person = (new PersonSchemaBuilder($this->connectionPool()))->buildFromRow(
            $this->personRow(),
            EntityBase::fromPageData($this->englishPage()),
        );

        self::assertNotNull($person);
        self::assertSame(self::ROOT . '/autor/ada-lovelace#person', $person['@id']);
        self::assertSame(self::ROOT . '/autor/ada-lovelace', $person['url']);
        self::assertSame(['@id' => self::ROOT . '#organization'], $person['worksFor']);
    }
}
