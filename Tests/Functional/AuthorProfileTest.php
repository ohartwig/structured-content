<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Functional;

use Koh\StructuredContent\Application\Service\SchemaOrgService;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use Koh\StructuredContent\Infrastructure\Persistence\DoctrineAuthoredPagesRepository;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Presentation\Middleware\RouteUrlBuilder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The author page and the Person behind it, against a real database: the
 * translated record, the portrait in the record type's file field, the
 * articles in publication order, the author page URL in each language and
 * the one page entity of that URL.
 */
final class AuthorProfileTest extends FunctionalTestCase
{
    private const ROUTE_ARGUMENTS = [
        'tx_contentblockscollection_person' => [
            'action' => 'show',
            'controller' => 'Person',
            'person' => 'ada-lovelace',
        ],
    ];

    protected array $testExtensionsToLoad = [
        'koh/structured-content',
        'typo3conf/ext/structured_content/Tests/Functional/Fixtures/Extensions/person_fixture',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AuthorProfile.csv');
    }

    private function builder(): PersonSchemaBuilder
    {
        return $this->get(PersonSchemaBuilder::class);
    }

    private function renderIn(int $languageId): void
    {
        $aspect = 0 === $languageId
            ? new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_ON_WITH_FLOATING, [])
            : new LanguageAspect($languageId, $languageId, LanguageAspect::OVERLAYS_MIXED, [0]);
        GeneralUtility::makeInstance(Context::class)->setAspect('language', $aspect);
    }

    #[Test]
    public function anEnglishRequestReadsTheEnglishTranslation(): void
    {
        $this->renderIn(1);

        $row = $this->builder()->findByIdentifier('ada-lovelace');

        self::assertNotNull($row);
        self::assertSame(7, (int) $row['uid'], 'the default-language uid stays the identity');
        self::assertSame('Mathematician', $row['moselwal_person_job_title']);
        self::assertSame('Wrote the first program.', $row['moselwal_person_bio']);
    }

    #[Test]
    public function aLookupByUidIsTranslatedAsWell(): void
    {
        $this->renderIn(1);

        $person = $this->builder()->buildFromPersonUid(7, 'https://example.com/');

        self::assertSame('Mathematician', $person['jobTitle'] ?? null);
        self::assertSame('https://example.com/autor/ada-lovelace#person', $person['@id'] ?? null);
    }

    #[Test]
    public function theDefaultLanguageReadsTheDefaultRecord(): void
    {
        $this->renderIn(0);

        self::assertSame('Mathematikerin', $this->builder()->findByIdentifier('ada-lovelace')['moselwal_person_job_title'] ?? null);
    }

    #[Test]
    public function withoutATranslationTheDefaultLanguageRecordIsShown(): void
    {
        $this->renderIn(1);

        self::assertSame('Informatikerin', $this->builder()->findByIdentifier('grace-hopper')['moselwal_person_job_title'] ?? null);
    }

    /**
     * A translation that carries a slug of its own is found by it, and the
     * Person keeps the default-language slug in its @id: one entity, not
     * one per language.
     */
    #[Test]
    public function aTranslatedSlugFindsThePersonWhoseIdStaysTheSame(): void
    {
        $this->renderIn(1);

        $row = $this->builder()->findByIdentifier('alan-turing-en');
        self::assertNotNull($row);
        $person = $this->builder()->buildFromRow($row, 'https://example.com/');

        self::assertSame('Computer scientist', $person['jobTitle'] ?? null);
        self::assertSame('https://example.com/autor/alan-turing#person', $person['@id'] ?? null);
    }

    #[Test]
    public function thePortraitIsTheFileInTheRecordTypesImageField(): void
    {
        $this->renderIn(0);

        $person = $this->builder()->buildFromPersonUid(7, 'https://example.com/');

        self::assertSame('https://example.com/fileadmin/team/ada.jpg', $person['image'] ?? null);
    }

    #[Test]
    public function theEnglishPersonShowsTheDefaultLanguagePortrait(): void
    {
        $this->renderIn(1);

        $person = $this->builder()->buildFromPersonUid(7, 'https://example.com/');

        self::assertSame('https://example.com/fileadmin/team/ada.jpg', $person['image'] ?? null);
    }

    /**
     * Newest publication first, by the date a post is presented with: the
     * post written first but released last (starttime) leads. Ordered by
     * tstamp, the oldest post came first because it was edited last.
     */
    #[Test]
    public function theArticlesOfAPersonAreOrderedByPublication(): void
    {
        $pages = (new DoctrineAuthoredPagesRepository($this->get(ConnectionPool::class)))->findByAuthorUid(7);

        self::assertSame([24, 21, 22, 20], array_map(static fn(array $page): int => (int) $page['uid'], $pages));
    }

    #[Test]
    public function theAuthorPageUrlFollowsTheTranslatedPageSlug(): void
    {
        $site = $this->site();
        $builder = new RouteUrlBuilder();

        self::assertSame(
            'https://example.com/en/author/ada-lovelace',
            $builder->build($site, $site->getLanguageById(1), 2, self::ROUTE_ARGUMENTS),
        );
        self::assertSame(
            'https://example.com/autor/ada-lovelace',
            $builder->build($site, $site->getLanguageById(0), 2, self::ROUTE_ARGUMENTS),
        );
        self::assertSame('', $builder->build($site, $site->getLanguageById(1), 2, []), 'a page without route arguments has its slug');
    }

    /**
     * The full generator stack on the English author page: one page entity
     * (the ProfilePage) at the English URL, the Person with its
     * language-independent @id, and no WebPage or Article of the container
     * page next to them.
     */
    #[Test]
    public function theEnglishAuthorPageHasOnePageEntity(): void
    {
        $this->renderIn(1);
        $site = $this->site();
        $pageData = [
            'uid' => 2,
            'pid' => 1,
            'doktype' => 1,
            'title' => 'Author',
            'slug' => '/author',
            'sys_language_uid' => 1,
            'crdate' => 1600000000,
            'tstamp' => 1600000000,
            'tx_structuredcontent_schema_type' => 'BlogPosting',
            '_siteSettings' => [],
            '_siteBase' => 'https://example.com/en/',
            '_entityBase' => 'https://example.com/',
            '_siteRootBase' => 'https://example.com/',
            '_rootPageId' => 1,
            '_rootline' => [],
            '_language' => 'en-GB',
            '_languageId' => 1,
            '_routingArguments' => self::ROUTE_ARGUMENTS,
            '_routeUrl' => (new RouteUrlBuilder())->build($site, $site->getLanguageById(1), 2, self::ROUTE_ARGUMENTS),
        ];

        $nodes = [];
        foreach ($this->get(SchemaOrgService::class)->generateAll($pageData, []) as $document) {
            $nodes[$document->schemaType] = $document;
        }

        self::assertArrayHasKey('ProfilePage', $nodes);
        self::assertArrayNotHasKey('WebPage', $nodes);
        self::assertArrayNotHasKey('BlogPosting', $nodes);
        $profile = $nodes['ProfilePage']->data;
        self::assertSame('https://example.com/en/author/ada-lovelace', $profile['url']);
        self::assertSame('https://example.com/en/author/ada-lovelace#webpage', $profile['@id']);
        self::assertSame(['@id' => 'https://example.com/autor/ada-lovelace#person'], $profile['mainEntity']);

        self::assertArrayHasKey('Person', $nodes);
        $person = $nodes['Person']->data;
        self::assertSame('https://example.com/autor/ada-lovelace#person', $person['@id']);
        self::assertSame('Mathematician', $person['jobTitle']);
        self::assertSame('https://example.com/fileadmin/team/ada.jpg', $person['image']);

        $pageEntities = array_filter(
            $nodes,
            static fn(JsonLdDocument $document): bool => 1 === preg_match('/#(webpage|blogposting)$/', (string) ($document->data['@id'] ?? '')),
        );
        self::assertSame(['ProfilePage'], array_keys($pageEntities));
    }

    private function site(): Site
    {
        return new Site('example', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                [
                    'languageId' => 0,
                    'title' => 'Deutsch',
                    'locale' => 'de_DE.UTF-8',
                    'base' => '/',
                    'hreflang' => 'de-DE',
                ],
                [
                    'languageId' => 1,
                    'title' => 'English',
                    'locale' => 'en_GB.UTF-8',
                    'base' => '/en/',
                    'hreflang' => 'en-GB',
                    'fallbackType' => 'fallback',
                    'fallbacks' => '0',
                ],
            ],
            'routeEnhancers' => [
                'Person' => [
                    'type' => 'Extbase',
                    'extension' => 'ContentBlocksCollection',
                    'plugin' => 'Person',
                    'limitToPages' => [2],
                    'routes' => [
                        [
                            'routePath' => '/{person}',
                            '_controller' => 'Person::show',
                            '_arguments' => ['person' => 'person'],
                        ],
                    ],
                    'defaultController' => 'Person::show',
                    'requirements' => ['person' => '[a-z0-9\-]+'],
                    'aspects' => [
                        'person' => [
                            'type' => 'StaticValueMapper',
                            'map' => ['ada-lovelace' => 'ada-lovelace'],
                        ],
                    ],
                ],
            ],
        ]);
    }
}
