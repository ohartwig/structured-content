<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PackagesGridEnterpriseServiceSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PackagesGridProductSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PackagesGridSoftwareApplicationSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Preistabelle einer Leistungsseite — und die Frage, was eine Karte
 * ueberhaupt ist.
 *
 * Drei Generatoren lesen dieselben Karten und teilen sich einen Loader. Was
 * sie unterscheidet, ist eine einzige Entscheidung: eine Karte mit einem
 * Preis-Zusatz wie "ab" ist ein Angebot auf Anfrage, keines mit Preis. Sie
 * gehoert als `Service` ausgegeben, nicht als `Product` mit einer Zahl, die
 * so nicht gilt — ein Preis im JSON-LD, der nicht der Preis ist, ist genau
 * die Sorte Angabe, wegen der eine Rich-Result-Pruefung eine Seite abstraft.
 *
 * Die zweite Eigenschaft steckt im Loader: die Karten werden in der
 * Standardsprache geladen und uebersetzte Felder darueber gelegt. Die
 * Systemfelder bleiben dabei die der Originalzeile — Reihenfolge und
 * Identitaet einer Karte duerfen sich nicht aendern, nur weil jemand die
 * Uebersetzung gepflegt hat.
 *
 * Und die dritte: die Feldnamen verlieren ihr `moselwal_card_`-Praefix.
 * Passiert das nicht, findet keiner der drei Generatoren ein einziges Feld,
 * und alle drei liefern leere Dokumente aus — ohne Fehler.
 */
final class PackagesGridSchemaGeneratorTest extends TestCase
{
    use PodcastFeedDoubles;

    private const GRID_CTYPE = 'moselwal_packagesgrid';

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
     * Ein echter Kontext mit einem echten Sprach-Aspekt: der Loader fragt ihn
     * nach der Sprache, und ein gedoubelter Aspekt hat die Methode nicht, die
     * dort aufgerufen wird.
     */
    /**
     * TYPO3 14 nimmt keine Aspekte mehr im Konstruktor entgegen. `new
     * Context(['language' => …])` wirft keinen Fehler — es verwirft das
     * Argument und liefert stillschweigend Sprache 0. Ein Test, der so gebaut
     * ist, prueft nicht, was in seinem Namen steht.
     */
    private function context(int $languageId = 0): Context
    {
        $context = new Context();
        $context->setAspect('language', new LanguageAspect($languageId));

        return $context;
    }

    private function fileRepository(): FileRepository
    {
        $repository = $this->createStub(FileRepository::class);
        $repository->method('findByRelation')->willReturn([]);

        return $repository;
    }

    private function product(): PackagesGridProductSchemaGenerator
    {
        return new PackagesGridProductSchemaGenerator($this->connectionPool(), $this->context(), $this->fileRepository());
    }

    private function enterprise(): PackagesGridEnterpriseServiceSchemaGenerator
    {
        return new PackagesGridEnterpriseServiceSchemaGenerator($this->connectionPool(), $this->context(), $this->fileRepository());
    }

    private function software(): PackagesGridSoftwareApplicationSchemaGenerator
    {
        return new PackagesGridSoftwareApplicationSchemaGenerator($this->connectionPool(), $this->context(), $this->fileRepository());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function page(array $overrides = []): array
    {
        return [
            'uid' => 42,
            'title' => 'Pakete',
            'slug' => '/pakete',
            '_siteBase' => 'https://example.com/',
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function gridBlock(): array
    {
        return [[
            'uid' => 1,
            'CType' => self::GRID_CTYPE,
            'header' => 'Unsere Pakete',
            'bodytext' => 'Drei Zuschnitte.',
        ]];
    }

    /**
     * Eine Karte, wie sie aus der Datenbank kommt: mit Praefix, denn genau
     * das Abstreifen ist eine der Eigenschaften unter Test.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function card(array $overrides = []): array
    {
        return [
            'uid' => 10,
            'sorting' => 128,
            'sys_language_uid' => 0,
            'moselwal_card_header' => 'Starter',
            'moselwal_card_bodytext' => 'Fuer den Anfang.',
            'moselwal_card_price' => '990',
            'moselwal_card_price_suffix' => 'einmalig',
            ...$overrides,
        ];
    }

    /**
     * Die erste Eigenschaft: eine Karte mit Preis ist ein Angebot mit Preis.
     */
    #[Test]
    public function aCardWithAPriceIsAProduct(): void
    {
        $this->rows = [$this->card()];

        self::assertTrue($this->product()->canHandle($this->page(), $this->gridBlock()));
    }

    /**
     * Und eine mit "ab" ist es nicht — dort steht ein Richtwert, kein Preis.
     * Als `Product` mit Preisangabe ausgegeben waere er eine Zahl, die so
     * nicht gilt.
     */
    #[Test]
    public function aCardWhosePriceIsOnlyAStartingPointIsNoProduct(): void
    {
        foreach (['ab', 'ab 990', 'AB 990 EUR', 'from', 'from 990'] as $suffix) {
            $this->rows = [$this->card(['moselwal_card_price_suffix' => $suffix])];

            self::assertFalse($this->product()->canHandle($this->page(), $this->gridBlock()), $suffix);
            self::assertTrue($this->enterprise()->canHandle($this->page(), $this->gridBlock()), $suffix);
        }
    }

    /**
     * Ein Zusatz, der nur zufaellig mit denselben Buchstaben beginnt, macht
     * daraus kein Angebot auf Anfrage — "abrechenbar" ist kein "ab".
     */
    #[Test]
    public function aSuffixThatMerelyStartsWithTheSameLettersIsNotOneOnRequest(): void
    {
        foreach (['abrechenbar', 'abo', 'fromage'] as $suffix) {
            $this->rows = [$this->card(['moselwal_card_price_suffix' => $suffix])];

            self::assertTrue($this->product()->canHandle($this->page(), $this->gridBlock()), $suffix);
            self::assertFalse($this->enterprise()->canHandle($this->page(), $this->gridBlock()), $suffix);
        }
    }

    /**
     * Ohne Preis ist eine Karte fuer keinen der beiden ein Angebot — weder
     * mit Zahl noch auf Anfrage.
     */
    #[Test]
    public function aCardWithoutAPriceIsNeither(): void
    {
        $this->rows = [$this->card(['moselwal_card_price' => '0', 'moselwal_card_price_suffix' => ''])];

        self::assertFalse($this->product()->canHandle($this->page(), $this->gridBlock()));
        self::assertFalse($this->enterprise()->canHandle($this->page(), $this->gridBlock()));
    }

    /**
     * Eine Seite ohne Preistabelle geht keinen der drei etwas an.
     */
    #[Test]
    public function aPageWithoutAGridIsHandledByNoneOfThem(): void
    {
        $this->rows = [$this->card()];

        foreach ([[$this->product(), 'product'], [$this->enterprise(), 'enterprise'], [$this->software(), 'software']] as [$generator, $name]) {
            self::assertFalse($generator->canHandle($this->page(), []), $name);
            self::assertFalse($generator->canHandle($this->page(), [['uid' => 1, 'CType' => 'text']]), $name);
        }
    }

    /**
     * Eine Seite, die ausdruecklich als Software gepflegt ist, ueberlaesst das
     * dem Software-Generator — der Preistabellen-Generator draengt sich nicht
     * dazwischen.
     */
    #[Test]
    public function aPageMaintainedAsSoftwareIsLeftToTheSoftwareGenerator(): void
    {
        $this->rows = [$this->card()];

        foreach (['SoftwareApplication', 'SoftwareSourceCode'] as $type) {
            self::assertFalse(
                $this->software()->canHandle($this->page(['tx_structuredcontent_schema_type' => $type]), $this->gridBlock()),
                $type,
            );
        }
    }

    /**
     * Die dritte Eigenschaft: die Feldnamen verlieren ihr Praefix. Passiert
     * das nicht, findet kein Generator ein einziges Feld und alle liefern
     * leere Dokumente — ohne Fehler.
     */
    #[Test]
    public function theCardFieldsLoseTheirPrefixOnTheWayIn(): void
    {
        $this->rows = [$this->card()];

        $document = $this->product()->generate($this->page(), $this->gridBlock());

        self::assertNotSame([], $document->data, 'the prefix was not stripped — nothing was found');
        self::assertStringContainsString('Starter', json_encode($document->data, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function theProductCarriesTheOfferAndItsPrice(): void
    {
        $this->rows = [$this->card()];

        $document = $this->product()->generate($this->page(), $this->gridBlock());
        $encoded = json_encode($document->data, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('990', $encoded);
        self::assertStringContainsString('EUR', $encoded);
    }

    /**
     * Das Angebot auf Anfrage wird als Leistung ausgegeben, nicht als Produkt
     * mit Preis.
     */
    #[Test]
    public function theEnterpriseCardBecomesAService(): void
    {
        $this->rows = [$this->card(['moselwal_card_price_suffix' => 'ab'])];

        $document = $this->enterprise()->generate($this->page(), $this->gridBlock());

        self::assertNotSame([], $document->data);
        self::assertStringContainsString('Starter', json_encode($document->data, JSON_THROW_ON_ERROR));
    }

    /**
     * Eine Preistabelle ohne Karten liefert kein Produkt — es gibt kein
     * Angebot, ueber das etwas zu sagen waere, und die Middleware wirft das
     * leere Dokument weg.
     */
    #[Test]
    public function aGridWithoutCardsProducesNoProduct(): void
    {
        self::assertSame([], array_filter($this->product()->generate($this->page(), $this->gridBlock())->data));
    }

    /**
     * Der Software-Generator dagegen beschreibt die Seite selbst weiter — die
     * Anwendung existiert unabhaengig davon, ob eine Preistabelle gepflegt
     * ist, und ihre Identitaet und Adresse gehoeren in die Ausgabe. Nur die
     * Preise fehlen dann.
     */
    #[Test]
    public function theSoftwareGeneratorStillDescribesThePageWithoutCards(): void
    {
        $document = $this->software()->generate($this->page(), $this->gridBlock());

        self::assertSame('https://example.com/pakete#software', $document->data['@id']);
        self::assertSame('Pakete', $document->data['name']);
        self::assertArrayNotHasKey('price', $document->data['offers']);
    }

    /**
     * Die zweite Eigenschaft: die Karten kommen in ihrer gepflegten
     * Reihenfolge. Die Preistabelle ist eine Rangfolge — "Starter, Pro,
     * Enterprise" ist eine Aussage, "Pro, Enterprise, Starter" eine andere.
     */
    #[Test]
    public function theCardsKeepTheirMaintainedOrder(): void
    {
        $this->rows = [$this->card(), $this->card(['uid' => 11, 'moselwal_card_header' => 'Pro'])];

        $this->product()->generate($this->page(), $this->gridBlock());

        self::assertSame([['sorting', 'ASC']], array_slice($this->ordering, 0, 1));
    }


    /**
     * Die uebersetzten Felder werden ueber die Standardzeilen gelegt, die
     * Systemfelder bleiben die der Originalzeile.
     *
     * Das ist die Eigenschaft, die man erst auf einer zweisprachigen Site
     * sieht: Reihenfolge und Identitaet einer Karte duerfen sich nicht
     * aendern, nur weil jemand die Uebersetzung gepflegt hat. Taeten sie es,
     * stuende die Preistabelle in der einen Sprache in einer anderen
     * Reihenfolge als in der anderen — und eine Preistabelle ist eine
     * Rangfolge.
     *
     * Die Uebersetzungszeile traegt hier eine andere `sorting`; dass sie
     * trotzdem nicht greift, ist die halbe Aussage des Tests.
     */
    #[Test]
    public function aTranslationOverlaysTheContentAndLeavesTheSystemFieldsAlone(): void
    {
        $this->rowSets = [
            [$this->card(['uid' => 10, 'sorting' => 128, 'moselwal_card_header' => 'Starter'])],
            [[
                'uid' => 11,
                'sorting' => 999,
                'sys_language_uid' => 1,
                'l10n_parent' => 10,
                'moselwal_card_header' => 'Starter (EN)',
            ]],
        ];

        $generator = new PackagesGridProductSchemaGenerator(
            $this->connectionPool(),
            $this->context(1),
            $this->fileRepository(),
        );

        $document = $generator->generate($this->page(), $this->gridBlock());
        $encoded = json_encode($document->data, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('Starter (EN)', $encoded, 'the translated text won');
        self::assertStringNotContainsString('"Starter"', $encoded, 'and replaced the default');
    }

    /**
     * In der Standardsprache wird gar nicht erst nach Uebersetzungen gefragt —
     * eine Abfrage, deren Ergebnis nie verwendet wird, ist eine Abfrage pro
     * Seitenaufbau zu viel.
     */
    #[Test]
    public function theDefaultLanguageAsksForNoTranslations(): void
    {
        $this->rows = [$this->card()];

        $generator = new PackagesGridProductSchemaGenerator(
            $this->connectionPool(),
            $this->context(0),
            $this->fileRepository(),
        );

        $generator->generate($this->page(), $this->gridBlock());

        self::assertCount(1, $this->ordering, 'exactly one query was built, not two');
    }

    /**
     * Alle drei ordnen sich ueber die generische Erkennung ein — eine
     * Preistabelle ist etwas Bestimmteres als "irgendeine Seite".
     */
    #[Test]
    public function allThreeOutrankTheGenericDetection(): void
    {
        foreach ([[$this->product(), 'product'], [$this->enterprise(), 'enterprise'], [$this->software(), 'software']] as [$generator, $name]) {
            self::assertGreaterThan(10, $generator->getPriority(), $name);
        }
    }
}
