<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\ItemListSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Listen auf einer Seite: Karten, Teaser, Menues.
 *
 * Zwei Entscheidungen tragen die Klasse, und beide sind Auslassungen.
 *
 * Auf einer Uebersichtsseite wird kein Menue zur Liste. Eine Blog-Uebersicht
 * ist bereits eine `CollectionPage`, und ihr Menue der Unterseiten noch einmal
 * als `ItemList` auszugeben, heisst dieselbe Aufzaehlung zweimal zu behaupten
 * — einmal als Sammlung, einmal als Liste. Karten sind davon ausgenommen: sie
 * sind redaktionell gesetzt und meinen etwas anderes als die Navigation.
 *
 * Und eine Liste ohne Ueberschrift wird nicht ausgegeben. `ItemList` ohne
 * `name` ist eine Liste, von der niemand weiss, wovon sie eine ist.
 *
 * Der dritte Punkt betrifft die Form: mehrere Listen auf einer Seite kommen
 * als `@graph` nebeneinander, nicht als eine Liste, die andere Listen
 * enthaelt. Verschachtelt waere die aeussere eine Liste von Listen — eine
 * Aussage, die die Seite nicht macht.
 */
final class ItemListSchemaGeneratorTest extends TestCase
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

    private function generator(): ItemListSchemaGenerator
    {
        return new ItemListSchemaGenerator($this->connectionPool());
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
            'title' => 'Leistungen',
            'slug' => '/leistungen/beratung',
            '_siteBase' => 'https://example.com/',
            '_languageId' => 0,
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function cardBlock(array $overrides = []): array
    {
        return [
            'uid' => 1,
            'CType' => 'moselwal_cards',
            'header' => 'Was wir anbieten',
            'subheader' => 'Drei Wege, mit uns zu arbeiten.',
            ...$overrides,
        ];
    }

    #[Test]
    public function aPageWithACardBlockIsHandled(): void
    {
        foreach (['moselwal_cards', 'moselwal_smallcards', 'moselwal_smallcardscarousel', 'moselwal_teaser'] as $cType) {
            self::assertTrue(
                $this->generator()->canHandle($this->page(), [$this->cardBlock(['CType' => $cType])]),
                $cType,
            );
        }
    }

    /**
     * Ein Block ohne Ueberschrift zaehlt nicht. `ItemList` ohne `name` ist
     * eine Liste, von der niemand weiss, wovon sie eine ist.
     */
    #[Test]
    public function aBlockWithoutAHeadingDoesNotCount(): void
    {
        self::assertFalse(
            $this->generator()->canHandle($this->page(), [$this->cardBlock(['header' => '   '])]),
        );
    }

    #[Test]
    public function aPageWithoutAnyListBlockIsNotHandled(): void
    {
        self::assertFalse($this->generator()->canHandle($this->page(), []));
        self::assertFalse($this->generator()->canHandle($this->page(), [['CType' => 'text', 'header' => 'Text']]));
    }

    /**
     * Die erste Entscheidung: auf einer Uebersichtsseite wird kein Menue zur
     * Liste. Die Seite ist bereits eine Sammlung; dasselbe noch einmal als
     * Liste auszugeben, behauptet die Aufzaehlung zweimal.
     */
    #[Test]
    public function aMenuOnAnOverviewPageDoesNotBecomeASecondList(): void
    {
        $menu = ['uid' => 2, 'CType' => 'menu_subpages', 'header' => 'Alle Beitraege'];

        self::assertTrue(
            $this->generator()->canHandle($this->page(['slug' => '/blog/ein-beitrag']), [$menu]),
            'on an ordinary page a menu is a list',
        );

        foreach ([
            'a root-level listing slug' => ['slug' => '/blog'],
            'a page marked as a collection' => ['tx_structuredcontent_schema_type' => 'CollectionPage'],
        ] as $why => $override) {
            self::assertFalse(
                $this->generator()->canHandle($this->page($override), [$menu]),
                $why,
            );
        }
    }

    /**
     * Karten sind davon ausgenommen: sie sind redaktionell gesetzt und meinen
     * etwas anderes als die Navigation.
     */
    #[Test]
    public function cardsAreListedEvenOnAnOverviewPage(): void
    {
        self::assertTrue(
            $this->generator()->canHandle($this->page(['slug' => '/blog']), [$this->cardBlock()]),
        );
    }

    #[Test]
    public function theListCarriesTheHeadingAndSubheadingOfItsBlock(): void
    {
        $document = $this->generator()->generate($this->page(), [$this->cardBlock()]);

        self::assertSame('ItemList', $document->schemaType);
        self::assertSame('Was wir anbieten', $document->data['name']);
        self::assertSame('Drei Wege, mit uns zu arbeiten.', $document->data['description']);
    }

    /**
     * Ohne Unterzeile bleibt die Beschreibung weg, statt als leere Angabe
     * dazustehen.
     */
    #[Test]
    public function aBlockWithoutASubheadingHasNoDescription(): void
    {
        $document = $this->generator()->generate($this->page(), [$this->cardBlock(['subheader' => ''])]);

        self::assertArrayNotHasKey('description', $document->data);
    }

    /**
     * Eine Liste ohne Eintraege nennt keine Anzahl. `numberOfItems: 0` ist
     * eine Liste, die ausdruecklich leer ist — und das ist etwas anderes als
     * eine Liste, deren Eintraege hier nicht ausgegeben werden.
     */
    #[Test]
    public function anEmptyListDoesNotClaimACount(): void
    {
        $document = $this->generator()->generate($this->page(), [$this->cardBlock()]);

        self::assertArrayNotHasKey('numberOfItems', $document->data);
        self::assertArrayNotHasKey('itemListElement', $document->data);
    }

    /**
     * Die dritte Eigenschaft: mehrere Listen stehen nebeneinander im
     * `@graph`, nicht ineinander. Verschachtelt waere die aeussere eine Liste
     * von Listen — eine Aussage, die die Seite nicht macht.
     */
    #[Test]
    public function severalListsStandSideBySideRatherThanNested(): void
    {
        $document = $this->generator()->generate($this->page(), [
            $this->cardBlock(),
            $this->cardBlock(['uid' => 2, 'header' => 'Und ausserdem', 'subheader' => '']),
        ]);

        self::assertArrayHasKey('@graph', $document->data);
        self::assertCount(2, $document->data['@graph']);
        self::assertSame('Was wir anbieten', $document->data['@graph'][0]['name']);
        self::assertSame('Und ausserdem', $document->data['@graph'][1]['name']);
    }

    /**
     * Eine einzelne Liste bleibt eine Liste — sie in einen Graph mit einem
     * Element zu packen, waere eine Umhuellung ohne Aussage.
     */
    #[Test]
    public function aSingleListIsNotWrappedInAGraph(): void
    {
        $document = $this->generator()->generate($this->page(), [$this->cardBlock()]);

        self::assertArrayNotHasKey('@graph', $document->data);
        self::assertSame('ItemList', $document->data['@type']);
    }

    /**
     * Und wenn nichts zusammenkommt, ist das Dokument leer — die Middleware
     * wirft leere Dokumente weg, statt einen typisierten Knoten ohne Inhalt
     * auszuliefern.
     */
    #[Test]
    public function aPageWithoutUsableBlocksProducesAnEmptyDocument(): void
    {
        $document = $this->generator()->generate($this->page(), [$this->cardBlock(['header' => ''])]);

        self::assertSame([], $document->data);
    }

    #[Test]
    public function theListOutranksTheGenericDetection(): void
    {
        self::assertGreaterThan(10, $this->generator()->getPriority());
    }

    /**
     * Die Sprache der Seite entscheidet ueber die Namen in der Liste.
     *
     * Gemessen am 23.09.2026: jede englische Seite mit einem Kartenblock trug
     * die DEUTSCHEN Kartennamen im JSON-LD — "Zurück zu pinup" unter
     * /en/open-source/pinup/platforms. Der Generator fragte
     * `sys_language_uid = 0` und reichte die Sprache zwar durch, benutzte sie
     * aber in keiner Abfrage. Sichtbar war das nur im Quelltext: die Seite
     * selbst war uebersetzt, und strukturierte Daten liest ausser einem
     * Crawler niemand.
     */
    #[Test]
    public function theCardsSpeakTheLanguageOfThePage(): void
    {
        $this->rowSets = [
            [
                ['uid' => 6240, 'moselwal_card_header' => 'Einstieg', 'moselwal_card_cta_link' => ''],
                ['uid' => 6241, 'moselwal_card_header' => 'Zurück zu pinup', 'moselwal_card_cta_link' => ''],
            ],
            [
                ['l10n_parent' => 6240, 'moselwal_card_header' => 'Getting started', 'moselwal_card_cta_link' => ''],
                ['l10n_parent' => 6241, 'moselwal_card_header' => 'Back to pinup', 'moselwal_card_cta_link' => ''],
            ],
        ];

        $document = $this->generator()->generate(
            $this->page(['_languageId' => 1]),
            [$this->cardBlock(['header' => 'Next'])],
        );

        self::assertSame(
            ['Getting started', 'Back to pinup'],
            array_column($document->data['itemListElement'] ?? [], 'name'),
        );
    }

    /**
     * Eine Karte ohne Uebersetzung faellt auf die Standardsprache zurueck
     * statt aus der Liste zu verschwinden. Ein fehlendes Overlay ist ein
     * Uebersetzungsrueckstand, kein Grund, den Eintrag zu unterschlagen.
     */
    #[Test]
    public function anUntranslatedCardKeepsItsDefaultName(): void
    {
        $this->rowSets = [
            [
                ['uid' => 1, 'moselwal_card_header' => 'Einstieg', 'moselwal_card_cta_link' => ''],
                ['uid' => 2, 'moselwal_card_header' => 'Noch nicht uebersetzt', 'moselwal_card_cta_link' => ''],
            ],
            [
                ['l10n_parent' => 1, 'moselwal_card_header' => 'Getting started', 'moselwal_card_cta_link' => ''],
            ],
        ];

        $document = $this->generator()->generate(
            $this->page(['_languageId' => 1]),
            [$this->cardBlock(['header' => 'Next'])],
        );

        self::assertSame(
            ['Getting started', 'Noch nicht uebersetzt'],
            array_column($document->data['itemListElement'] ?? [], 'name'),
        );
    }

    /**
     * In der Standardsprache wird nach keiner Uebersetzung gefragt. Die
     * zweite Abfrage waere nicht bloss ueberfluessig — sie liefe auf jeder
     * deutschen Seite mit, und das sind fast alle.
     */
    #[Test]
    public function theDefaultLanguageAsksForNoTranslation(): void
    {
        $this->rowSets = [
            [['uid' => 1, 'moselwal_card_header' => 'Einstieg', 'moselwal_card_cta_link' => '']],
            [['l10n_parent' => 1, 'moselwal_card_header' => 'Getting started', 'moselwal_card_cta_link' => '']],
        ];

        $document = $this->generator()->generate($this->page(), [$this->cardBlock(['header' => 'Weiter'])]);

        self::assertSame(
            ['Einstieg'],
            array_column($document->data['itemListElement'] ?? [], 'name'),
        );
    }

    /**
     * Die Reihenfolge steht im `sorting` der Standardsprache. Eine
     * Uebersetzung ist ein Overlay und sortiert nichts um — auch dann nicht,
     * wenn die Datenbank sie in anderer Reihenfolge liefert.
     */
    #[Test]
    public function theOrderOfTheDefaultLanguageDecides(): void
    {
        $this->rowSets = [
            [
                ['uid' => 10, 'moselwal_card_header' => 'Eins', 'moselwal_card_cta_link' => ''],
                ['uid' => 20, 'moselwal_card_header' => 'Zwei', 'moselwal_card_cta_link' => ''],
                ['uid' => 30, 'moselwal_card_header' => 'Drei', 'moselwal_card_cta_link' => ''],
            ],
            [
                ['l10n_parent' => 30, 'moselwal_card_header' => 'Three', 'moselwal_card_cta_link' => ''],
                ['l10n_parent' => 10, 'moselwal_card_header' => 'One', 'moselwal_card_cta_link' => ''],
            ],
        ];

        $document = $this->generator()->generate(
            $this->page(['_languageId' => 1]),
            [$this->cardBlock(['header' => 'Next'])],
        );

        self::assertSame(
            ['One', 'Zwei', 'Three'],
            array_column($document->data['itemListElement'] ?? [], 'name'),
        );
    }

    /**
     * Ein leeres Feld der Uebersetzung heisst "nicht uebersetzt", nicht
     * "absichtlich leer". Ohne diese Regel verschwaende eine leer angelegte
     * Uebersetzung den Namen — und eine ListItem ohne `name` wirft der
     * Generator weg, der Eintrag fehlte also ganz.
     */
    #[Test]
    public function anEmptyTranslationFallsBackInsteadOfDroppingTheEntry(): void
    {
        $this->rowSets = [
            [['uid' => 1, 'moselwal_card_header' => 'Einstieg', 'moselwal_card_cta_link' => '']],
            [['l10n_parent' => 1, 'moselwal_card_header' => '   ', 'moselwal_card_cta_link' => '']],
        ];

        $document = $this->generator()->generate(
            $this->page(['_languageId' => 1]),
            [$this->cardBlock(['header' => 'Next'])],
        );

        self::assertSame(
            ['Einstieg'],
            array_column($document->data['itemListElement'] ?? [], 'name'),
        );
    }

    /**
     * `l10n_parent = 0` heisst "Uebersetzung von nichts". Ohne die Pruefung
     * landet so eine Zeile unter dem Schluessel 0 und trifft dort jede
     * Standardzeile ohne uid.
     *
     * Beides zusammen liefert die Abfrage nicht — sie waehlt `uid` immer mit,
     * und der Zeiger einer echten Uebersetzung zeigt irgendwohin. Der Test
     * haelt deshalb eine Verteidigungslinie fest, keinen Zustand aus dem
     * Betrieb: mit uid in der Standardzeile liefe er auch ohne die Pruefung
     * gruen und bewiese nichts.
     */
    #[Test]
    public function aTranslationPointingNowhereIsIgnored(): void
    {
        $this->rowSets = [
            [['moselwal_card_header' => 'Einstieg', 'moselwal_card_cta_link' => '']],
            [['l10n_parent' => 0, 'moselwal_card_header' => 'Verirrt', 'moselwal_card_cta_link' => '']],
        ];

        $document = $this->generator()->generate(
            $this->page(['_languageId' => 1]),
            [$this->cardBlock(['header' => 'Next'])],
        );

        self::assertSame(
            ['Einstieg'],
            array_column($document->data['itemListElement'] ?? [], 'name'),
        );
    }
}
