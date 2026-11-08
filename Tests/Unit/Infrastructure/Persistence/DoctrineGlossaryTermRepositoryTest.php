<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Persistence;

use Koh\StructuredContent\Infrastructure\Persistence\DoctrineGlossaryTermRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Result;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Der Zugriff auf die Glossartabellen einer Extension, die es vielleicht gar
 * nicht gibt.
 *
 * Das ist die Eigenschaft, um die es hier geht: `dpn_glossary` ist optional,
 * und ohne sie existieren die drei Tabellen nicht. Jede Methode fragt deshalb
 * zuerst, ob die Extension geladen ist, und gibt sonst das leere Ergebnis
 * zurueck — *ohne* zu fragen. Faellt eine dieser Pruefungen weg, ist das keine
 * leere Antwort, sondern eine SQL-Abfrage auf eine nicht existierende Tabelle:
 * eine Ausnahme mitten im Seitenaufbau jeder Installation ohne diese
 * Extension.
 *
 * Der Test prueft deshalb nicht nur die Rueckgabe, sondern dass keine Abfrage
 * gestellt wurde.
 */
final class DoctrineGlossaryTermRepositoryTest extends TestCase
{
    use PodcastFeedDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 0; $i < 64; ++$i) {
            GeneralUtility::addInstance(TcaSchemaFactory::class, $this->createStub(TcaSchemaFactory::class));
            GeneralUtility::addInstance(
                FrontendRestrictionContainer::class,
                $this->createStub(FrontendRestrictionContainer::class),
            );
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /**
     * Der Paketverwalter ist der Schalter, an dem `isLoaded()` haengt.
     */
    private function glossaryExtension(bool $installed): void
    {
        $packageManager = $this->createStub(PackageManager::class);
        $packageManager->method('isPackageActive')->willReturnCallback(
            static fn (string $key): bool => $installed && 'dpn_glossary' === $key,
        );

        ExtensionManagementUtility::setPackageManager($packageManager);
    }

    private function repository(): DoctrineGlossaryTermRepository
    {
        return new DoctrineGlossaryTermRepository($this->connectionPool());
    }

    /**
     * Die Eigenschaft, auf die es ankommt: ohne die Extension wird nicht
     * gefragt. Eine Abfrage auf eine nicht existierende Tabelle ist eine
     * Ausnahme mitten im Seitenaufbau.
     */
    #[Test]
    public function withoutTheExtensionNothingIsQueriedAtAll(): void
    {
        $this->glossaryExtension(installed: false);
        $this->rows = [['uid' => 1, 'name' => 'Schema.org', 'url_segment' => 'schema-org']];

        $repository = $this->repository();

        self::assertFalse($repository->isAvailable());
        self::assertNull($repository->findPageSlug(42));
        self::assertNull($repository->findTermBySlug('schema-org', 0));
        self::assertSame([], $repository->findTermsBySlugs(['schema-org'], 0));
        self::assertNull($repository->findTermByUid(1, 0));
        self::assertNull($repository->findFirstDescription(1, 0));
        self::assertSame([], $repository->findSynonymNames(1, 0));
        self::assertSame([], $repository->findAllTerms(0));

        self::assertSame([], $this->ordering, 'not a single query was built');
    }

    #[Test]
    public function withTheExtensionTheRepositoryReportsItself(): void
    {
        $this->glossaryExtension(installed: true);

        self::assertTrue($this->repository()->isAvailable());
    }

    /**
     * Eine uid, die keinen Datensatz meint, wird nicht abgefragt — uid 0 und
     * negative Werte sind in TYPO3 kein Datensatz.
     */
    #[Test]
    public function anImpossibleUidIsNotQueriedEither(): void
    {
        $this->glossaryExtension(installed: true);
        $repository = $this->repository();

        foreach ([0, -1] as $uid) {
            self::assertNull($repository->findPageSlug($uid), (string) $uid);
            self::assertNull($repository->findTermByUid($uid, 0), (string) $uid);
            self::assertNull($repository->findFirstDescription($uid, 0), (string) $uid);
            self::assertSame([], $repository->findSynonymNames($uid, 0), (string) $uid);
        }

        self::assertNull($repository->findTermBySlug('', 0), 'and an empty slug is no slug');
    }

    #[Test]
    public function aTermIsFoundByItsSlug(): void
    {
        $this->glossaryExtension(installed: true);
        $this->rows = [[
            'uid' => 1,
            'name' => 'Schema.org',
            'url_segment' => 'schema-org',
            'tooltiptext' => 'Ein Vokabular fuer strukturierte Daten.',
        ]];

        $term = $this->repository()->findTermBySlug('schema-org', 0);

        self::assertNotNull($term);
        self::assertSame('Schema.org', $term['name']);
        self::assertSame('schema-org', $term['url_segment']);
    }

    #[Test]
    public function aTermIsFoundByTheUidOfItsOriginal(): void
    {
        $this->glossaryExtension(installed: true);
        $this->rows = [['uid' => 13, 'name' => 'Wolfi OS', 'url_segment' => 'wolfi-os']];

        self::assertSame('wolfi-os', $this->repository()->findTermByUid(12, 1)['url_segment'] ?? null);
    }

    #[Test]
    public function aSlugThatMatchesNoTermIsNull(): void
    {
        $this->glossaryExtension(installed: true);

        self::assertNull($this->repository()->findTermBySlug('gibt-es-nicht', 0));
    }

    /**
     * Die Synonyme kommen in ihrer gepflegten Reihenfolge — sie erscheinen als
     * `alternateName` in der Ausgabe, und die Reihenfolge ist die, in der eine
     * Redaktion sie sortiert hat.
     */
    #[Test]
    public function theSynonymsComeInTheirMaintainedOrder(): void
    {
        $this->glossaryExtension(installed: true);
        $this->rows = [['name' => 'Structured Data'], ['name' => 'Strukturierte Daten']];

        $synonyms = $this->repository()->findSynonymNames(1, 0);

        self::assertSame(['Structured Data', 'Strukturierte Daten'], $synonyms);
        self::assertSame([['sorting', 'ASC']], $this->ordering);
    }

    /**
     * Und die Begriffsliste alphabetisch — sie wird als Glossar ausgegeben,
     * und ein Glossar in Datenbankreihenfolge ist kein Glossar.
     */
    #[Test]
    public function theTermListIsAlphabetical(): void
    {
        $this->glossaryExtension(installed: true);
        $this->rows = [['uid' => 1, 'name' => 'Schema.org', 'url_segment' => 'schema-org']];

        $terms = $this->repository()->findAllTerms(0);

        self::assertCount(1, $terms);
        self::assertSame([['name', 'ASC']], $this->ordering);
    }

    #[Test]
    public function aTermWithoutADescriptionHasNone(): void
    {
        $this->glossaryExtension(installed: true);

        self::assertNull($this->repository()->findFirstDescription(1, 0));
    }

    /**
     * Die Seite, unter der das Glossar liegt, wird ueber ihren Slug gefunden —
     * und ein leerer Slug ist keiner. Eine Seite ohne Slug waere ein
     * Glossar-Pfad, der auf die Startseite zeigt.
     */
    #[Test]
    public function aPageWithoutASlugIsNoGlossaryPage(): void
    {
        $this->glossaryExtension(installed: true);
        $this->rows = [['slug' => '']];

        self::assertNull($this->repository()->findPageSlug(42));
    }

    /**
     * In another language the translation's slug is the glossary path: the
     * English glossary page lives at /glossary, not at the German /glossar.
     */
    #[Test]
    public function inAnotherLanguageTheTranslatedSlugIsUsed(): void
    {
        $this->glossaryExtension(installed: true);
        $this->rows = [['slug' => '/glossary']];

        self::assertSame('/glossary', $this->repository()->findPageSlug(42, 1));
    }

    /**
     * What the batch query was built from, one entry per query.
     *
     * @var list<array{parameters: list<array{mixed, ParameterType}>, literals: list<string>, where: list<string>, restrictions: list<object>, select?: list<string>}>
     */
    private array $batchQueries = [];

    /**
     * A query builder that records what the batch lookup builds and answers
     * each query with the next row set. The expressions come back as readable
     * SQL, so the test can see which placeholder stands where.
     */
    private function recordingConnectionPool(): ConnectionPool
    {
        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturnCallback(function (string $table): QueryBuilder {
            self::assertSame('tx_dpnglossary_domain_model_term', $table);
            $query = count($this->batchQueries);
            $this->batchQueries[$query] = ['parameters' => [], 'literals' => [], 'where' => [], 'restrictions' => []];

            $expr = $this->createStub(ExpressionBuilder::class);
            $expr->method('eq')->willReturnCallback(static fn (string $field, mixed $value): string => $field . ' = ' . $value);
            $expr->method('in')->willReturnCallback(
                static fn (string $field, array $values): string => $field . ' IN (' . implode(', ', $values) . ')',
            );

            $result = $this->createStub(Result::class);
            $result->method('fetchAllAssociative')->willReturnCallback(fn (): array => array_shift($this->rowSets) ?? []);

            $queryBuilder = $this->createStub(QueryBuilder::class);
            $queryBuilder->method('select')->willReturnCallback(function (string ...$columns) use ($query, $queryBuilder): QueryBuilder {
                $this->batchQueries[$query]['select'] = $columns;

                return $queryBuilder;
            });
            $queryBuilder->method('from')->willReturnSelf();
            $queryBuilder->method('setRestrictions')->willReturnCallback(function (object $restrictions) use ($query): void {
                $this->batchQueries[$query]['restrictions'][] = $restrictions;
            });
            $queryBuilder->method('expr')->willReturn($expr);
            $queryBuilder->method('quoteIdentifier')->willReturnCallback(static fn (string $identifier): string => '`' . $identifier . '`');
            $queryBuilder->method('createNamedParameter')->willReturnCallback(
                function (mixed $value, ParameterType $type = ParameterType::STRING) use ($query): string {
                    $this->batchQueries[$query]['parameters'][] = [$value, $type];

                    return ':p' . (count($this->batchQueries[$query]['parameters']) - 1);
                },
            );
            $queryBuilder->method('addSelectLiteral')->willReturnCallback(
                function (string ...$literals) use ($query, $queryBuilder): QueryBuilder {
                    array_push($this->batchQueries[$query]['literals'], ...$literals);

                    return $queryBuilder;
                },
            );
            $queryBuilder->method('where')->willReturnCallback(
                function (string ...$predicates) use ($query, $queryBuilder): QueryBuilder {
                    $this->batchQueries[$query]['where'] = $predicates;

                    return $queryBuilder;
                },
            );
            $queryBuilder->method('executeQuery')->willReturn($result);

            return $queryBuilder;
        });

        return $pool;
    }

    /**
     * Many slugs, one query — with the restrictions, the language and the
     * columns of the single-slug lookup. Each slug is a parameter of its own
     * and appears twice: in the IN list and in its match column.
     */
    #[Test]
    public function manySlugsAreLookedUpInOneQuery(): void
    {
        $this->glossaryExtension(installed: true);
        $this->rowSets = [[]];

        $terms = (new DoctrineGlossaryTermRepository($this->recordingConnectionPool()))
            ->findTermsBySlugs(['cve', 'sbom', '', 'cve', 'slsa'], 1);

        self::assertSame([], $terms);
        self::assertCount(1, $this->batchQueries, 'one query');
        $query = $this->batchQueries[0];
        self::assertSame(
            [['cve', ParameterType::STRING], ['sbom', ParameterType::STRING], ['slsa', ParameterType::STRING], [1, ParameterType::INTEGER]],
            $query['parameters'],
            'each slug once, no empty slug, the language as an integer',
        );
        self::assertSame(
            ['(url_segment = :p0) AS `slug_match_0`', '(url_segment = :p1) AS `slug_match_1`', '(url_segment = :p2) AS `slug_match_2`'],
            $query['literals'],
        );
        self::assertSame(['url_segment IN (:p0, :p1, :p2)', 'sys_language_uid = :p3'], $query['where']);
        self::assertSame(['uid', 'name', 'url_segment', 'tooltiptext', 'meta_description', 'seo_title'], $query['select'] ?? null);
        self::assertCount(1, $query['restrictions']);
        self::assertInstanceOf(FrontendRestrictionContainer::class, $query['restrictions'][0], 'the frontend restrictions of the single lookup');
    }

    /**
     * The database says which row belongs to which slug, through the match
     * column — so a link whose case differs from the stored segment finds its
     * term, as it does with the single-slug query under a case-insensitive
     * collation. The first matching row wins, as LIMIT 1 does; the match
     * columns do not leak into the term.
     */
    #[Test]
    public function theMatchColumnsAssignTheRowsToTheSlugs(): void
    {
        $this->glossaryExtension(installed: true);
        $row = static fn (int $uid, string $segment, array $matches): array => [
            'uid' => $uid,
            'name' => 'Term ' . $uid,
            'url_segment' => $segment,
            'tooltiptext' => '',
            'meta_description' => '',
            'seo_title' => '',
            ...$matches,
        ];
        $this->rowSets = [[
            $row(3, 'sbom', ['slug_match_0' => 0, 'slug_match_1' => 1, 'slug_match_2' => 0, 'slug_match_3' => 0]),
            $row(1, 'cve', ['slug_match_0' => '1', 'slug_match_1' => '0', 'slug_match_2' => true, 'slug_match_3' => false]),
            $row(2, 'cve', ['slug_match_0' => 't', 'slug_match_1' => 'f', 'slug_match_2' => 1, 'slug_match_3' => null]),
        ]];

        $terms = (new DoctrineGlossaryTermRepository($this->recordingConnectionPool()))
            ->findTermsBySlugs(['cve', 'sbom', 'CVE', 'unknown'], 0);

        self::assertSame(['sbom', 'cve', 'CVE'], array_keys($terms));
        self::assertSame(3, $terms['sbom']['uid']);
        self::assertSame(1, $terms['cve']['uid'], 'the first matching row');
        self::assertSame(1, $terms['CVE']['uid'], 'another spelling, the same term');
        self::assertSame(
            ['uid' => 1, 'name' => 'Term 1', 'url_segment' => 'cve', 'tooltiptext' => '', 'meta_description' => '', 'seo_title' => ''],
            $terms['cve'],
            'the row as the single lookup returns it',
        );
    }

    /**
     * A page with more term links than one query should carry is split, and
     * the parts are joined again.
     */
    #[Test]
    public function veryManySlugsAreSplitIntoQueriesOfTwoHundred(): void
    {
        $this->glossaryExtension(installed: true);
        $slugs = array_map(static fn (int $i): string => 'term-' . $i, range(1, 401));
        $this->rowSets = [
            [['uid' => 1, 'url_segment' => 'term-1', 'slug_match_0' => 1]],
            [],
            [['uid' => 401, 'url_segment' => 'term-401', 'slug_match_0' => 1]],
        ];

        $terms = (new DoctrineGlossaryTermRepository($this->recordingConnectionPool()))->findTermsBySlugs($slugs, 0);

        self::assertSame([200, 200, 1], array_map(static fn (array $query): int => count($query['literals']), $this->batchQueries));
        self::assertSame(['uid' => 1, 'url_segment' => 'term-1'], $terms['term-1']);
        self::assertSame(['uid' => 401, 'url_segment' => 'term-401'], $terms['term-401']);
        self::assertCount(2, $terms);
    }

    /**
     * Without the extension the batch asks nothing either: its table does
     * not exist.
     */
    #[Test]
    public function withoutTheExtensionTheBatchAsksNothing(): void
    {
        $this->glossaryExtension(installed: false);

        self::assertSame([], (new DoctrineGlossaryTermRepository($this->recordingConnectionPool()))->findTermsBySlugs(['cve'], 0));
        self::assertSame([], $this->batchQueries);
    }

    /**
     * No slug, no query.
     */
    #[Test]
    public function withoutSlugsTheBatchAsksNothing(): void
    {
        $this->glossaryExtension(installed: true);
        $repository = new DoctrineGlossaryTermRepository($this->recordingConnectionPool());

        self::assertSame([], $repository->findTermsBySlugs([], 0));
        self::assertSame([], $repository->findTermsBySlugs(['', ''], 0));
        self::assertSame([], $this->batchQueries);
    }
}
