<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Doctrine\DBAL\Result;
use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use Koh\StructuredContent\Domain\Contract\CategoryRepositoryInterface;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ArticleSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * BlogPosting without a maintained author record or page media.
 *
 * Google lists `author` and `image` as the properties an article needs for
 * rich results. A post whose editor left the author record and the media
 * field empty used to go out without both. The site's default author and the
 * page's social-sharing image fill those gaps — never overriding what an
 * editor did maintain.
 */
final class ArticleAuthorAndImageFallbackTest extends TestCase
{
    use PodcastFeedDoubles;

    /**
     * File references per `pages` field, as the image lookup sees them.
     *
     * @var array<string, int>
     */
    private array $fileReferences = [];

    /** @var array<int, string> sys_file identifiers by uid */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 0; $i < 64; ++$i) {
            GeneralUtility::addInstance(TcaSchemaFactory::class, $this->createStub(TcaSchemaFactory::class));
            GeneralUtility::addInstance(
                FrontendRestrictionContainer::class,
                $this->createStub(FrontendRestrictionContainer::class),
            );
            GeneralUtility::addInstance(ConnectionPool::class, $this->filePool());
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    private function generator(): ArticleSchemaGenerator
    {
        return new ArticleSchemaGenerator(
            new SchemaTypeResolverService(),
            new PersonSchemaBuilder($this->connectionPool()),
            $this->createStub(CategoryRepositoryInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function page(array $overrides = []): array
    {
        return [
            'uid' => 46,
            '_rootPageId' => 1,
            'title' => 'From container hosting to a hardened cluster',
            'slug' => '/blog/2024/cluster',
            '_siteBase' => 'https://example.com/',
            '_entityBase' => 'https://example.com/',
            '_language' => 'de-DE',
            'tx_structuredcontent_schema_type' => 'BlogPosting',
            '_siteSettings' => [
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
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
        ];
    }

    #[Test]
    public function testPostWithoutAnyAuthorGetsTheSitesDefaultAuthor(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->generator()->generate($this->page(), []);

        self::assertSame(
            ['@type' => 'Person', '@id' => 'https://example.com/autor/ada-lovelace#person', 'name' => 'Ada Lovelace'],
            $document->data['author'],
        );
    }

    #[Test]
    public function testTextAuthorNamingTheDefaultAuthorBecomesAReference(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->generator()->generate($this->page(['author' => '  ada   LOVELACE ']), []);

        self::assertSame('https://example.com/autor/ada-lovelace#person', $document->data['author']['@id']);
    }

    #[Test]
    public function testTextAuthorNamingSomebodyElseIsKept(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->generator()->generate($this->page(['author' => 'Grace Hopper']), []);

        self::assertSame('Grace Hopper', $document->data['author']['name']);
        self::assertArrayNotHasKey('@id', $document->data['author']);
    }

    #[Test]
    public function testExplicitDefaultAuthorSettingIsUsedWithoutFounder(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->generator()->generate($this->page([
            '_siteSettings' => ['structuredContent' => ['schema' => ['defaultAuthorPersonSlug' => 'ada-lovelace']]],
        ]), []);

        self::assertSame('https://example.com/autor/ada-lovelace#person', $document->data['author']['@id']);
    }

    #[Test]
    public function testWithoutDefaultAuthorSettingThereIsNoInventedAuthor(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->generator()->generate($this->page(['_siteSettings' => []]), []);

        self::assertArrayNotHasKey('author', $document->data);
    }

    #[Test]
    public function testUnknownDefaultAuthorSlugYieldsNoAuthor(): void
    {
        $this->rows = [];

        $document = $this->generator()->generate($this->page(), []);

        self::assertArrayNotHasKey('author', $document->data);
    }

    #[Test]
    public function testOgImageStandsInWhenThePageHasNoMedia(): void
    {
        $this->fileReferences = ['og_image' => 5];
        $this->files = [5 => '/user_upload/og.jpg'];

        $document = $this->generator()->generate($this->page(['_siteSettings' => []]), []);

        self::assertSame('https://example.com/fileadmin/user_upload/og.jpg', $document->data['image']['contentUrl']);
    }

    #[Test]
    public function testPageMediaWinOverTheOgImage(): void
    {
        $this->fileReferences = ['media' => 3, 'og_image' => 5];
        $this->files = [3 => '/user_upload/hero.jpg', 5 => '/user_upload/og.jpg'];

        $document = $this->generator()->generate($this->page(['_siteSettings' => []]), []);

        self::assertSame('https://example.com/fileadmin/user_upload/hero.jpg', $document->data['image']['contentUrl']);
    }

    #[Test]
    public function testNoImageWhenNeitherMediaNorOgImageIsSet(): void
    {
        $document = $this->generator()->generate($this->page(['_siteSettings' => []]), []);

        self::assertArrayNotHasKey('image', $document->data);
    }

    /**
     * Connection pool for the image lookup: answers a file reference query by
     * the field name it asks for, and a sys_file query by the file uid.
     */
    private function filePool(): ConnectionPool
    {
        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturnCallback(
            fn (string $table): QueryBuilder => $this->fileQueryBuilder($table),
        );

        return $pool;
    }

    private function fileQueryBuilder(string $table): QueryBuilder
    {
        $parameters = [];
        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'orderBy', 'setMaxResults'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            static function (mixed $value) use (&$parameters): string {
                $parameters[] = $value;

                return ':p';
            },
        );
        $queryBuilder->method('executeQuery')->willReturnCallback(
            function () use (&$parameters, $table): Result {
                $row = false;
                if ('sys_file_reference' === $table) {
                    foreach ($this->fileReferences as $field => $fileUid) {
                        if (in_array($field, $parameters, true)) {
                            $row = ['uid_local' => $fileUid];
                        }
                    }
                } elseif ('sys_file' === $table) {
                    foreach ($this->files as $uid => $identifier) {
                        if (in_array($uid, $parameters, true)) {
                            $row = ['identifier' => $identifier];
                        }
                    }
                }
                $result = $this->createStub(Result::class);
                $result->method('fetchAssociative')->willReturn($row);

                return $result;
            },
        );

        return $queryBuilder;
    }
}
