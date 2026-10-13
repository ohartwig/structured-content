<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Persistence;

use Koh\StructuredContent\Infrastructure\Persistence\DoctrineGlossaryTermRepository;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
}
