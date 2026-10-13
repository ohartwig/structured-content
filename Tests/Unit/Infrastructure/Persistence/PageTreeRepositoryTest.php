<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Driver\PDO\Exception as DriverPdoException;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Result;
use Koh\StructuredContent\Infrastructure\Persistence\PageTreeRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Prueft, dass die Seitenbaum-Abfragen die Sichtbarkeitsbeschraenkungen tragen.
 *
 * Dieser Test ist mit dem Code umgezogen. Er stand in
 * LlmsTxtGeneratorServiceTest, solange der Dienst selbst abgefragt hat; seit
 * die Abfragen im Repository liegen, gehoert er hierher.
 *
 * Der Umzug ist der eigentliche Punkt: eine Schichtenbereinigung, die dabei
 * eine Sicherheitspruefung verliert, hat mehr kaputt gemacht als aufgeraeumt.
 * Was hier geprueft wird, sichert den Audit-Befund M4 ab — ohne
 * FrontendRestrictionContainer landen verborgene, zeitgesteuerte oder
 * fe_group-geschuetzte Seiten samt Volltext in einer oeffentlichen llms-full.md.
 */
final class PageTreeRepositoryTest extends TestCase
{
    private const NOW = 1785300000;

    /** @var list<QueryRestrictionContainerInterface> */
    private array $restrictionsApplied = [];

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /**
     * Aufeinanderfolgende Ergebnisse, wenn eine Methode mehr als eine Abfrage
     * fuehrt — die Uebersetzungsabfrage und der Rueckfall auf die
     * Standardsprache brauchen je einen zweiten Satz.
     *
     * @var list<list<array<string, mixed>>>
     */
    private array $rowBatches = [];

    /**
     * Thrown by executeQuery() instead of returning a result, to simulate a
     * database that rejects the query (e.g. a column that does not exist yet).
     */
    private ?\Throwable $queryException = null;

    protected function setUp(): void
    {
        parent::setUp();

        // isPubliclyReachable() fragt den Zugriffskontext nach der aktuellen
        // Zeit, und der echte Context liest sie aus dieser Globalen. Ohne sie
        // faellt er beim Bau ueber ein null-Timestamp — und die Pruefung von
        // starttime und endtime waere gar nicht erreichbar.
        $GLOBALS['EXEC_TIME'] = self::NOW;
        $GLOBALS['SIM_EXEC_TIME'] = self::NOW;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['EXEC_TIME'], $GLOBALS['SIM_EXEC_TIME']);
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    #[Test]
    public function theQueryCarriesTheFrontendVisibilityRestrictions(): void
    {
        $this->rows = [['uid' => 1, 'pid' => 0, 'title' => 'Start', 'slug' => '/', 'doktype' => 1]];

        $this->repository()->loadVisiblePages(1);

        self::assertNotSame([], $this->restrictionsApplied, 'the query ran unrestricted');
        foreach ($this->restrictionsApplied as $container) {
            self::assertInstanceOf(FrontendRestrictionContainer::class, $container);
        }
    }

    /**
     * Die pid-Karte laeuft bewusst OHNE Sichtbarkeitsfilter.
     *
     * Sie dient dem Hochlaufen der Rootline, und dabei muessen auch unsichtbare
     * Vorfahren auftauchen — sonst bricht der Weg nach oben ab und eine Seite
     * gilt faelschlich als nicht im Baum. Die Sichtbarkeit entscheidet
     * isPubliclyReachable(), nicht diese Abfrage.
     */
    #[Test]
    public function thePidMapIsDeliberatelyUnfiltered(): void
    {
        $this->rows = [['uid' => 5, 'pid' => 3], ['uid' => 3, 'pid' => 1]];

        $map = $this->repository()->loadPidMap();

        self::assertSame([5 => 3, 3 => 1], $map);
        self::assertSame([], $this->restrictionsApplied, 'the pid map must not be visibility-filtered');
    }

    /**
     * The flagged pages come from a query of their own and are returned as
     * default-language uids.
     */
    #[Test]
    public function theFeaturedFlagIsReadAsAListOfUids(): void
    {
        $this->rows = [['uid' => 7], ['uid' => '9']];

        self::assertSame([7, 9], $this->repository()->loadFeaturedPageUids());
    }

    /**
     * Deployed before the database schema update, the column
     * `tx_structuredcontent_llms_featured` does not exist and the flag query
     * fails with an unknown-column error. That must cost the featured section
     * only — an empty list, no exception — never the whole /llms.txt.
     */
    #[Test]
    public function aMissingFeaturedColumnYieldsNoFeaturedPagesInsteadOfAnException(): void
    {
        $this->queryException = new InvalidFieldNameException(
            DriverPdoException::new(new \PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'tx_structuredcontent_llms_featured'")),
            null,
        );

        self::assertSame([], $this->repository()->loadFeaturedPageUids());
    }

    /**
     * The sorting map is unfiltered for the same reason: the tree order of
     * the featured pages also runs through nav-hidden folders.
     */
    #[Test]
    public function theSortingMapIsDeliberatelyUnfiltered(): void
    {
        $this->rows = [['uid' => 5, 'sorting' => 512], ['uid' => 3, 'sorting' => '256']];

        $map = $this->repository()->loadSortingMap();

        self::assertSame([5 => 512, 3 => 256], $map);
        self::assertSame([], $this->restrictionsApplied, 'the sorting map must not be visibility-filtered');
    }

    /**
     * Der Einzelabzug ist derselbe oeffentliche Kanal wie die llms-full.md, nur
     * auf Zuruf: `Accept: text/markdown` auf eine beliebige Seiten-uid. Faellt
     * hier der Sichtbarkeitsfilter weg, gibt genau diese Anfrage den Inhalt
     * einer verborgenen Seite heraus — und anders als beim Volltext-Abzug faellt
     * das niemandem auf, weil die Seite in keiner Liste auftaucht.
     */
    #[Test]
    public function theSinglePageQueryCarriesTheSameVisibilityRestrictions(): void
    {
        $this->rows = [['uid' => 10, 'title' => 'Leistungen', 'slug' => '/leistungen', 'doktype' => 1]];

        $page = $this->repository()->loadVisiblePage(10);

        self::assertSame('Leistungen', $page['title'] ?? null);
        self::assertNotSame([], $this->restrictionsApplied, 'the query ran unrestricted');
        foreach ($this->restrictionsApplied as $container) {
            self::assertInstanceOf(FrontendRestrictionContainer::class, $container);
        }
    }

    /**
     * Keine sichtbare Zeile heisst null, nicht `false` und nicht eine leere
     * Zeile. Der Aufrufer unterscheidet daran "gibt es nicht" von "gibt es,
     * hat aber keinen Inhalt", und `false` haette er als Array behandelt.
     */
    #[Test]
    public function aPageWithoutAVisibleRowComesBackAsNull(): void
    {
        $this->rows = [];

        self::assertNull($this->repository()->loadVisiblePage(10));
    }

    /**
     * Der Kern des Audit-Befunds M4.
     *
     * Seite 20 ist selbst unbeschraenkt. Ihr Elternteil 10 ist verborgen UND
     * traegt `extendToSubpages`, gilt also fuer den ganzen Teilbaum darunter.
     * Der FrontendRestrictionContainer sieht das nicht — er prueft nur die
     * Zeile der Blattseite. Ohne diesen Aufstieg landet der Volltext einer
     * verborgenen Rubrik in einer oeffentlich abrufbaren llms-full.md.
     */
    #[Test]
    public function aPageUnderAHiddenSubtreeIsNotPubliclyReachable(): void
    {
        $repository = $this->walkingRepository([
            20 => ['pid' => 10, 'hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 0],
            10 => ['pid' => 1, 'hidden' => 1, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 1],
        ]);

        self::assertFalse($repository->isPubliclyReachable(20, 1));
    }

    /**
     * Die Gegenprobe: dasselbe verborgene Elternteil OHNE `extendToSubpages`
     * beschraenkt nur sich selbst. Die Unterseite bleibt erreichbar.
     *
     * Ohne diesen Fall waere der vorherige Test auch von einer Pruefung
     * erfuellt, die jeden verborgenen Vorfahren sperrt — und die haette
     * saemtliche Seiten unter einem ausgeblendeten Menuepunkt aus der
     * llms.txt geworfen.
     */
    #[Test]
    public function aHiddenAncestorWithoutSubtreeExtensionDoesNotBlockItsChildren(): void
    {
        $repository = $this->walkingRepository([
            20 => ['pid' => 10, 'hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 0],
            10 => ['pid' => 1, 'hidden' => 1, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 0],
        ]);

        self::assertTrue($repository->isPubliclyReachable(20, 1));
    }

    /**
     * Eine Zugriffsbeschraenkung auf dem Teilbaum wirkt genauso.
     *
     * Der Abzug wird anonym ausgeliefert; welche Gruppe ein Leser haette, ist
     * nicht zu ermitteln. Deshalb sperrt jede fe_group ausser "0" — im
     * Zweifel gegen die Veroeffentlichung.
     */
    #[Test]
    public function anAccessRestrictedSubtreeIsNotPubliclyReachable(): void
    {
        $repository = $this->walkingRepository([
            20 => ['pid' => 10, 'hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 0],
            10 => ['pid' => 1, 'hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '2', 'extendToSubpages' => 1],
        ]);

        self::assertFalse($repository->isPubliclyReachable(20, 1));
    }

    /**
     * Und ein abgelaufener Teilbaum ebenfalls. Eine Kampagnenseite, deren
     * Zeitfenster zu ist, gehoert nicht mehr in den Abzug.
     */
    #[Test]
    public function anExpiredSubtreeIsNotPubliclyReachable(): void
    {
        $repository = $this->walkingRepository([
            20 => ['pid' => 10, 'hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 0],
            10 => ['pid' => 1, 'hidden' => 0, 'starttime' => 0, 'endtime' => self::NOW - 3600, 'fe_group' => '', 'extendToSubpages' => 1],
        ]);

        self::assertFalse($repository->isPubliclyReachable(20, 1));
    }

    /**
     * Eine Seite, deren Weg nach oben die Wurzel nie erreicht, gehoert nicht
     * zu diesem Auftritt — in einer Mehr-Site-Installation der Normalfall.
     */
    #[Test]
    public function aPageOutsideTheRootIsNotReachableFromIt(): void
    {
        $repository = $this->walkingRepository([
            20 => ['pid' => 99, 'hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 0],
            99 => ['pid' => 0, 'hidden' => 0, 'starttime' => 0, 'endtime' => 0, 'fe_group' => '', 'extendToSubpages' => 0],
        ]);

        self::assertFalse($repository->isPubliclyReachable(20, 1));
    }

    /**
     * Und eine uid ohne Zeile ebenfalls nicht. Der Abzug laeuft ueber einen
     * Baum, der sich waehrenddessen aendern kann.
     */
    #[Test]
    public function aVanishedPageIsNotReachable(): void
    {
        self::assertFalse($this->walkingRepository([])->isPubliclyReachable(20, 1));
    }

    /**
     * Der Seitentext wird zu Markdown abgeflacht: Ueberschrift als `###`,
     * Fliesstext ohne Auszeichnung.
     *
     * Bliebe das HTML stehen, laege in einer Datei, die ausdruecklich als
     * Markdown ausgeliefert wird, Markup — und ein Modell zitierte spaeter
     * `<p>`-Fragmente als Inhalt der Seite.
     */
    #[Test]
    public function theBodyIsFlattenedToPlainText(): void
    {
        $this->rows = [
            ['header' => 'Leistungen', 'bodytext' => '<p>Wir bauen <strong>TYPO3</strong>.</p>', 'CType' => 'text'],
        ];

        $body = $this->repository()->renderPageBody(10);

        self::assertSame("### Leistungen\n\nWir bauen TYPO3.", $body);
    }

    /**
     * Gibt es in der angefragten Sprache keinen einzigen Inhalt, wird der
     * Text der Standardsprache genommen.
     *
     * Sonst stuende die Seite mit Titel und Adresse, aber ohne Text im
     * Abzug — und das liest sich wie eine leere Seite, nicht wie eine
     * unuebersetzte.
     */
    #[Test]
    public function anUntranslatedPageFallsBackToTheDefaultLanguageBody(): void
    {
        $this->rowBatches = [
            [],
            [['header' => '', 'bodytext' => 'Deutscher Text.', 'CType' => 'text']],
        ];

        $body = $this->repository()->renderPageBody(10, 1);

        self::assertSame('Deutscher Text.', $body);
    }

    /**
     * In einer Fremdsprache ersetzen Titel und Slug der Uebersetzung die der
     * Standardsprache — die uid bleibt aber die der Standardsprache, weil die
     * pid-Kette daran haengt.
     */
    #[Test]
    public function translatedPagesKeepTheDefaultLanguageUid(): void
    {
        $this->rowBatches = [
            [['uid' => 10, 'pid' => 1, 'title' => 'Leistungen', 'slug' => '/leistungen', 'description' => '', 'abstract' => '', 'doktype' => 1]],
            [['uid' => 77, 'l10n_parent' => 10, 'title' => 'Services', 'slug' => '/services', 'description' => '', 'abstract' => '']],
        ];

        // Die Wurzel als Seite selbst: so beantwortet der Aufstieg sich in
        // der ersten Zeile, und der Test haengt nicht an einem zweiten
        // Zeilendoppel fuer den Weg nach oben.
        $pages = $this->repository()->loadVisiblePages(10, 1);

        self::assertCount(1, $pages);
        self::assertSame(10, (int) $pages[0]['uid'], 'the pid chain hangs off the default-language uid');
        self::assertSame(77, $pages[0]['_translatedUid']);
        self::assertSame('Services', $pages[0]['title']);
        self::assertSame('/services', $pages[0]['slug']);
    }

    /**
     * Ein Repository, dessen Einzelzeilen-Abfrage nach der uid antwortet.
     *
     * Der Aufstieg fragt je Vorfahre neu, und die uid ist der einzige benannte
     * Parameter dieser Abfrage. Der Doppel liest sie dort ab, statt Aufrufe zu
     * zaehlen — sonst haengt der Test an der Laenge der Kette.
     *
     * @param array<int, array<string, mixed>> $rowsByUid
     */
    private function walkingRepository(array $rowsByUid): PageTreeRepository
    {
        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturnCallback(
            function () use ($rowsByUid): QueryBuilder {
                $uid = null;

                $result = $this->createStub(Result::class);
                $result->method('fetchAssociative')->willReturnCallback(
                    static function () use (&$uid, $rowsByUid): array|false {
                        return $rowsByUid[$uid] ?? false;
                    },
                );

                $queryBuilder = $this->createStub(QueryBuilder::class);
                foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chained) {
                    $queryBuilder->method($chained)->willReturnSelf();
                }
                $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
                $queryBuilder->method('createNamedParameter')->willReturnCallback(
                    static function (mixed $value) use (&$uid): string {
                        $uid ??= (int) $value;

                        return ':p';
                    },
                );
                $queryBuilder->method('executeQuery')->willReturn($result);

                $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
                $restrictions->method('removeAll')->willReturnSelf();
                $restrictions->method('add')->willReturnSelf();
                $queryBuilder->method('getRestrictions')->willReturn($restrictions);

                return $queryBuilder;
            },
        );

        $this->queueFrameworkInstances();

        return new PageTreeRepository($pool);
    }

    private function repository(): PageTreeRepository
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturnCallback(
            fn (): array => [] === $this->rowBatches ? $this->rows : (array) array_shift($this->rowBatches),
        );
        $result->method('fetchAssociative')->willReturnCallback(fn (): array|false => $this->rows[0] ?? false);
        $result->method('fetchOne')->willReturn(false);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'setMaxResults'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $queryBuilder->method('setRestrictions')->willReturnCallback(
            function (QueryRestrictionContainerInterface $container) use ($queryBuilder): QueryBuilder {
                $this->restrictionsApplied[] = $container;

                return $queryBuilder;
            },
        );
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeQuery')->willReturnCallback(
            function () use ($result): Result {
                if (null !== $this->queryException) {
                    throw $this->queryException;
                }

                return $result;
            },
        );

        // `getRestrictions()->removeAll()->add(...)` — beide Glieder muessen
        // etwas zurueckgeben, sonst bricht die Kette am Doppel auf.
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);

        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $this->queueFrameworkInstances();

        return new PageTreeRepository($pool);
    }

    /**
     * Das Repository holt die Restriction-Container und den Zugriffskontext
     * ueber GeneralUtility::makeInstance(). Ein echter Container zieht die
     * TCA-Schema-Fabrik nach; die Warteschlange gibt je Aufruf eine Instanz
     * heraus, und der Baumdurchlauf fragt pro Seite neu — deshalb grosszuegig.
     */
    private function queueFrameworkInstances(): void
    {
        for ($i = 0; $i < 400; ++$i) {
            GeneralUtility::addInstance(
                FrontendRestrictionContainer::class,
                $this->createStub(FrontendRestrictionContainer::class),
            );
            GeneralUtility::addInstance(
                DeletedRestriction::class,
                $this->createStub(DeletedRestriction::class),
            );
            GeneralUtility::addInstance(
                TcaSchemaFactory::class,
                $this->createStub(TcaSchemaFactory::class),
            );
        }
    }
}
