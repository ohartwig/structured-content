<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Model\ResolvedContext;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ProfessionalServiceSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ServiceSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Leistungsseite und die Teamseite — zwei Seitenarten, bei denen die
 * Erkennung das Schwierige ist.
 *
 * Beim Service ist es eine Kette von Ausschluessen, und jeder einzelne ist
 * eine Zeile: die Startseite ist keine Leistungsseite (dort steht die
 * Organisation), eine als Software gepflegte Seite ist keine, eine mit einem
 * Code-Repository ist keine, und eine unter `/open-source/` ist keine. Jeder
 * dieser Ausschluesse existiert, weil ohne ihn zwei Generatoren dieselbe Seite
 * beanspruchen und die Seite mit zwei konkurrierenden Haupt-Entitaeten
 * ausgeliefert wird.
 *
 * Beim Team ist es die Entdoppelung: dasselbe Element zweimal in der Liste
 * ergibt sonst jede Person zweimal, und eine Personenliste mit Dubletten liest
 * sich als doppelt so grosses Team.
 *
 * Und `ProfessionalService` beansprucht ausdruecklich nichts — er wird nur
 * gerufen, wenn ein anderer ihn ruft. Ein `canHandle()`, das true zurueckgibt,
 * waere hier ein Generator, der auf jeder Seite mitschreibt.
 */
final class ServiceAndPersonSchemaGeneratorTest extends TestCase
{
    use PodcastFeedDoubles;

    /** @var array<string, mixed>|null */
    private ?array $personTcaBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Der Team-Generator liest `moselwal_person` — die Tabelle liefert
        // EXT:content_blocks_collection. Die Person-Tests hier beschreiben den
        // Normalfall "Collection installiert"; den anderen Fall prueft
        // `withoutThePersonTableTheTeamGeneratorStandsDown`.
        $this->personTcaBefore = $GLOBALS['TCA']['moselwal_person'] ?? null;
        $GLOBALS['TCA']['moselwal_person'] = ['ctrl' => ['transOrigPointerField' => 'l10n_parent']];

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
        if (null === $this->personTcaBefore) {
            unset($GLOBALS['TCA']['moselwal_person']);
        } else {
            $GLOBALS['TCA']['moselwal_person'] = $this->personTcaBefore;
        }

        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    private function service(): ServiceSchemaGenerator
    {
        $context = $this->createStub(ContextResolverInterface::class);
        $context->method('resolve')->willReturn(ResolvedContext::withDefaults());
        $context->method('resolveFromPageTree')->willReturn(ResolvedContext::withDefaults());

        $relations = $this->createStub(RelationshipRepositoryInterface::class);
        $relations->method('findBySource')->willReturn([]);
        $relations->method('findBidirectionalByTarget')->willReturn([]);

        return new ServiceSchemaGenerator(
            $this->connectionPool(),
            $this->defaultLanguageContext(),
            $context,
            $relations,
        );
    }

    private function person(): PersonSchemaGenerator
    {
        return new PersonSchemaGenerator(
            $this->connectionPool(),
            new PersonSchemaBuilder($this->connectionPool()),
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
            'uid' => 42,
            '_rootPageId' => 1,
            'title' => 'Beratung',
            'description' => 'Was wir tun.',
            'slug' => '/leistungen/beratung',
            '_siteBase' => 'https://example.com/',
            'sys_language_uid' => 0,
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function serviceBlock(): array
    {
        return [['uid' => 1, 'CType' => 'moselwal_servicecards', 'header' => 'Was wir anbieten']];
    }

    #[Test]
    public function aPageWithServiceContentIsAServicePage(): void
    {
        self::assertTrue($this->service()->canHandle($this->page(), $this->serviceBlock()));
    }

    /**
     * Die Kette von Ausschluessen. Jeder einzelne verhindert, dass zwei
     * Generatoren dieselbe Seite beanspruchen.
     */
    #[Test]
    public function everyExclusionKeepsTheServiceGeneratorOffAPageThatIsNotOne(): void
    {
        foreach ([
            'the root page carries the organisation' => ['uid' => 1, '_rootPageId' => 1],
            'a page maintained as software' => ['tx_structuredcontent_schema_type' => 'SoftwareApplication'],
            'a page maintained as source code' => ['tx_structuredcontent_schema_type' => 'SoftwareSourceCode'],
            'a page with a code repository' => ['tx_structuredcontent_code_repository' => 'https://git.example.com/tool'],
            'a page below /open-source/' => ['slug' => '/open-source/ein-werkzeug'],
            'a page below /opensource/' => ['slug' => '/opensource/ein-werkzeug'],
        ] as $why => $override) {
            self::assertFalse(
                $this->service()->canHandle($this->page($override), $this->serviceBlock()),
                $why,
            );
        }
    }

    #[Test]
    public function anOrdinaryPageWithoutServiceSignalsIsNotAServicePage(): void
    {
        $page = $this->page(['slug' => '/ueber-uns', 'keywords' => '']);

        self::assertFalse($this->service()->canHandle($page, [['uid' => 1, 'CType' => 'text', 'header' => 'Text']]));
    }

    /**
     * Der Slug allein reicht: eine Seite unter /leistungen ist eine
     * Leistungsseite, auch ohne eigenen Inhaltsblock dafuer.
     */
    #[Test]
    public function theSlugAloneIsEnoughToRecogniseAServicePage(): void
    {
        foreach (['/leistungen/beratung', '/services/consulting', '/dienstleistungen/beratung'] as $slug) {
            self::assertTrue(
                $this->service()->canHandle($this->page(['slug' => $slug]), [['uid' => 1, 'CType' => 'text']]),
                $slug,
            );
        }
    }

    #[Test]
    public function theServiceCarriesTheNameAndDescriptionOfThePage(): void
    {
        $document = $this->service()->generate($this->page(), $this->serviceBlock());

        self::assertSame('Service', $document->schemaType);
        self::assertSame('Beratung', $document->data['name']);
        self::assertSame('Was wir tun.', $document->data['description']);
    }

    /**
     * Der Anbieter ist die Organisation der Site — als Verweis, nicht als
     * zweite Kopie. Eine Leistung ohne Anbieter ist eine Leistung, die
     * niemand erbringt.
     */
    #[Test]
    public function theServiceNamesItsProviderAsAReference(): void
    {
        $document = $this->service()->generate($this->page(), $this->serviceBlock());

        self::assertSame(
            ['@type' => 'Organization', '@id' => 'https://example.com#organization'],
            $document->data['provider'] ?? null,
        );
    }


    /**
     * Die Identitaet der Leistung haengt an der Seitenadresse — sie ist das,
     * worauf ein Angebot, eine Bewertung oder eine andere Seite verweisen
     * kann. Ohne sie ist jede Erwaehnung dieser Leistung eine neue.
     */
    #[Test]
    public function theServiceIsIdentifiedByItsPageUrl(): void
    {
        $document = $this->service()->generate(
            $this->page(['canonical_link' => 'https://example.com/leistungen/beratung']),
            $this->serviceBlock(),
        );

        self::assertSame('https://example.com/leistungen/beratung#service', $document->data['@id']);
        self::assertSame('https://example.com/leistungen/beratung', $document->data['url']);
        self::assertSame(
            ['@id' => 'https://example.com/leistungen/beratung#webpage'],
            $document->data['mainEntityOfPage'],
        );
    }

    /**
     * Ohne kanonische Adresse wird sie aus Basis und Slug gebaut.
     */
    #[Test]
    public function withoutACanonicalLinkTheAddressIsBuiltFromBaseAndSlug(): void
    {
        $document = $this->service()->generate($this->page(), $this->serviceBlock());

        self::assertSame('https://example.com/leistungen/beratung', $document->data['url']);
    }

    /**
     * Fehlt die Beschreibung auf der Seite, tritt der Text des
     * Leistungsblocks ein — und wird gekuerzt. Eine Beschreibung ist das, was
     * in einem Suchergebnis unter dem Titel steht; ein ganzer Artikel dort
     * wird ohnehin abgeschnitten, nur eben nicht von uns.
     */
    #[Test]
    public function theBlockTextStandsInForAMissingDescriptionAndIsShortened(): void
    {
        $long = str_repeat('Ein sehr ausfuehrlicher Satz ueber die Leistung. ', 30);

        $document = $this->service()->generate(
            $this->page(['description' => '', 'abstract' => '']),
            [['uid' => 1, 'CType' => 'moselwal_servicecards', 'header' => 'Was wir anbieten', 'bodytext' => $long]],
        );

        self::assertNotSame('', $document->data['description']);
        self::assertLessThanOrEqual(500, mb_strlen($document->data['description']));
    }

    /**
     * Leistungsart und Kategorie werden uebernommen, wenn sie gepflegt sind —
     * und weggelassen, wenn nicht. Ein leeres `serviceType` ist eine Angabe
     * ueber eine Leistungsart, die es nicht gibt.
     */
    #[Test]
    public function theMaintainedTypeAndCategoryAreCarried(): void
    {
        $document = $this->service()->generate(
            $this->page([
                'tx_structuredcontent_service_type' => 'Beratung',
                'tx_structuredcontent_service_category' => 'IT-Dienstleistung',
            ]),
            $this->serviceBlock(),
        );

        self::assertSame('Beratung', $document->data['serviceType']);
        self::assertSame('IT-Dienstleistung', $document->data['category']);

        $without = $this->service()->generate($this->page(), $this->serviceBlock());
        self::assertArrayNotHasKey('serviceType', $without->data);
        self::assertArrayNotHasKey('category', $without->data);
    }

    /**
     * Das Einzugsgebiet kommt aus den Site-Einstellungen: eines bleibt ein
     * Wert, mehrere werden zur Liste, und ein leerer Eintrag aus einer
     * handgepflegten Aufzaehlung faellt heraus.
     */
    #[Test]
    public function theAreaServedIsReadFromTheSiteSettings(): void
    {
        $settings = static fn (string $value): array => [
            'structuredContent' => ['schema' => ['areaServed' => $value]],
        ];

        $one = $this->service()->generate(
            $this->page(['_siteSettings' => $settings('Deutschland')]),
            $this->serviceBlock(),
        );
        self::assertSame('Deutschland', $one->data['areaServed']);

        $several = $this->service()->generate(
            $this->page(['_siteSettings' => $settings('Deutschland, Oesterreich, , Schweiz')]),
            $this->serviceBlock(),
        );
        self::assertSame(['Deutschland', 'Oesterreich', 'Schweiz'], $several->data['areaServed']);
    }

    /**
     * Und was leer bleibt, steht nicht im Dokument — ein Schluessel ohne Wert
     * behauptet eine Angabe, die niemand gemacht hat.
     */
    #[Test]
    public function emptyValuesDoNotReachTheDocument(): void
    {
        $document = $this->service()->generate(
            $this->page(['description' => '', 'abstract' => '']),
            [['uid' => 1, 'CType' => 'moselwal_servicecards', 'header' => 'Was wir anbieten']],
        );

        foreach ($document->data as $key => $value) {
            self::assertNotSame('', $value, $key);
            self::assertNotSame([], $value, $key);
        }
    }

    /**
     * `ProfessionalService` beansprucht ausdruecklich nichts: er wird von
     * einem anderen Generator gerufen, nicht von der Erkennung. Ein `true`
     * hier waere ein Generator, der auf jeder Seite mitschreibt.
     */
    #[Test]
    public function theProfessionalServiceClaimsNoPageOfItsOwn(): void
    {
        $generator = new ProfessionalServiceSchemaGenerator($this->connectionPool());

        self::assertFalse($generator->canHandle($this->page(), $this->serviceBlock()));
        self::assertFalse($generator->canHandle([], []));
    }

    /**
     * Gerufen liefert er trotzdem ein Dokument — mit der Identitaet der
     * Organisation, nicht der Seite.
     */
    #[Test]
    public function theProfessionalServiceDescribesTheOrganisationNotThePage(): void
    {
        $generator = new ProfessionalServiceSchemaGenerator($this->connectionPool());

        $document = $generator->generate($this->page(), $this->serviceBlock());

        self::assertSame('https://example.com#professional-service', $document->data['@id']);
        self::assertSame('https://example.com', $document->data['url']);
    }


    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function siteSettings(array $schema = [], array $footer = []): array
    {
        return [
            'structuredContent' => ['schema' => $schema],
            ...$footer,
        ];
    }

    /**
     * Die Oeffnungszeiten sind das, was in einem lokalen Suchergebnis als
     * "jetzt geoeffnet" erscheint. Sie werden in die Form gebracht, die
     * Schema.org dafuer verlangt — eine Zeichenkette wie "Mo-Fr 09:00-17:00"
     * ist fuer einen Leser keine Zeitangabe.
     */
    #[Test]
    public function theOpeningHoursBecomeASpecification(): void
    {
        $generator = new ProfessionalServiceSchemaGenerator($this->connectionPool());

        $document = $generator->generate(
            $this->page(['_siteSettings' => $this->siteSettings(['openingHours' => 'Mo-Fr 09:00-17:00'])]),
            $this->serviceBlock(),
        );

        $spec = $document->data['openingHoursSpecification'];

        self::assertSame('OpeningHoursSpecification', $spec['@type']);
        self::assertNotSame([], $spec['dayOfWeek']);
        self::assertSame('09:00', $spec['opens']);
        self::assertSame('17:00', $spec['closes']);
    }

    /**
     * Die Leistungsarten werden zum Angebotskatalog — und ein leerer Eintrag
     * aus einer handgepflegten Aufzaehlung faellt heraus, statt als Angebot
     * ohne Namen zu erscheinen.
     */
    #[Test]
    public function theServiceTypesBecomeAnOfferCatalogWithoutBlanks(): void
    {
        $generator = new ProfessionalServiceSchemaGenerator($this->connectionPool());

        $document = $generator->generate(
            $this->page(['_siteSettings' => $this->siteSettings(['serviceTypes' => 'Beratung, Umsetzung, , Betrieb'])]),
            $this->serviceBlock(),
        );

        $catalog = $document->data['hasOfferCatalog'];

        self::assertSame('OfferCatalog', $catalog['@type']);
        self::assertCount(3, $catalog['itemListElement']);
        self::assertSame('Beratung', $catalog['itemListElement'][0]['itemOffered']['name']);
    }

    /**
     * Kontaktangaben erscheinen als ein Kontaktpunkt, nicht als zwei — und nur
     * die, die gepflegt sind. Ein Kontaktpunkt ohne beides waere eine
     * Einladung, die nirgends hinfuehrt.
     */
    #[Test]
    public function theContactPointCarriesOnlyWhatIsMaintained(): void
    {
        $generator = new ProfessionalServiceSchemaGenerator($this->connectionPool());

        $both = $generator->generate(
            $this->page(['_siteSettings' => $this->siteSettings([], ['footerEmail' => 'hallo@example.com', 'footerPhone' => '+49 2173 000'])]),
            $this->serviceBlock(),
        );
        self::assertSame('hallo@example.com', $both->data['contactPoint']['email']);
        self::assertSame('+49 2173 000', $both->data['contactPoint']['telephone']);

        $onlyMail = $generator->generate(
            $this->page(['_siteSettings' => $this->siteSettings([], ['footerEmail' => 'hallo@example.com'])]),
            $this->serviceBlock(),
        );
        self::assertArrayNotHasKey('telephone', $onlyMail->data['contactPoint']);

        $neither = $generator->generate($this->page(['_siteSettings' => $this->siteSettings()]), $this->serviceBlock());
        self::assertArrayNotHasKey('contactPoint', $neither->data);
    }

    /**
     * Die Preisspanne ist die Angabe, die in lokalen Ergebnissen als
     * Euro-Zeichen erscheint — sie wird uebernommen, wenn sie gepflegt ist.
     */
    #[Test]
    public function thePriceRangeIsCarriedWhenMaintained(): void
    {
        $generator = new ProfessionalServiceSchemaGenerator($this->connectionPool());

        $document = $generator->generate(
            $this->page(['_siteSettings' => $this->siteSettings(['priceRange' => '$$'])]),
            $this->serviceBlock(),
        );

        self::assertSame('$$', $document->data['priceRange']);
    }

    #[Test]
    public function aPageWithATeamBlockIsAPersonPage(): void
    {
        foreach (['moselwal_team', 'moselwal_teamgrid', 'TEAM_CARDS'] as $cType) {
            self::assertTrue(
                $this->person()->canHandle($this->page(), [['uid' => 1, 'CType' => $cType]]),
                $cType,
            );
        }
    }

    #[Test]
    public function aPageWithoutOneIsNot(): void
    {
        self::assertFalse($this->person()->canHandle($this->page(), []));
        self::assertFalse($this->person()->canHandle($this->page(), [['uid' => 1, 'CType' => 'text']]));
    }

    /**
     * Der CType-Match ist ein Teilstring auf `team`, und `moselwal_person`
     * liefert EXT:content_blocks_collection. Ein Sitepackage mit eigenem
     * Team-Element auf einer Installation ohne die Collection wuerde diesen
     * Generator sonst greifen lassen — die Abfrage laeuft dann gegen eine
     * Tabelle, die es nicht gibt, und der SQL-Fehler nimmt die
     * Schema-Erzeugung der ganzen Seite mit, nicht nur diesen Generator.
     */
    #[Test]
    public function withoutThePersonTableTheTeamGeneratorStandsDown(): void
    {
        unset($GLOBALS['TCA']['moselwal_person']);

        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $generator = new PersonSchemaGenerator($connectionPool, new PersonSchemaBuilder($connectionPool));
        $team = [['uid' => 1, 'CType' => 'moselwal_team']];

        self::assertFalse($generator->canHandle($this->page(), $team));
        self::assertSame([], $generator->generate($this->page(), $team)->data);
    }

    /**
     * Die Entdoppelung: dasselbe Element zweimal in der Liste ergibt die
     * Personen nicht zweimal. Eine Personenliste mit Dubletten liest sich als
     * doppelt so grosses Team.
     */
    #[Test]
    public function thesameTeamBlockListedTwiceDoesNotDoubleTheTeam(): void
    {
        $this->rows = [[
            'uid' => 7,
            'uid_local' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
        ]];

        $block = ['uid' => 1, 'CType' => 'moselwal_team', 'header' => 'Team'];
        $document = $this->person()->generate($this->page(), [$block, $block]);

        $encoded = json_encode($document->data, JSON_THROW_ON_ERROR);

        self::assertSame(1, substr_count($encoded, 'Ada Lovelace'));
    }

    /**
     * Eine Teamseite ohne Personen liefert ein leeres Dokument — die
     * Middleware wirft es weg, statt eine leere Personenliste auszuliefern.
     */
    #[Test]
    public function aTeamBlockWithoutPeopleProducesAnEmptyDocument(): void
    {
        $document = $this->person()->generate($this->page(), [['uid' => 1, 'CType' => 'moselwal_team']]);

        self::assertSame([], array_filter($document->data));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function personRow(array $overrides = []): array
    {
        return [
            'uid' => 7,
            'uid_local' => 0,
            'sys_language_uid' => 0,
            'l10n_parent' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
            ...$overrides,
        ];
    }

    /**
     * Ein Mensch ist ein `Person`, mehrere sind eine `ItemList` — und die
     * Position in dieser Liste ist die Eigenschaft, die zaehlt.
     *
     * Eine Personenliste ohne Rangfolge waere fuer eine Teamseite noch
     * hinnehmbar; dieselbe Ausgabe traegt aber auch Autorenlisten, und dort ist
     * die Reihenfolge eine Aussage darueber, wer den Text geschrieben hat. Sie
     * muss der Sortierung aus dem Backend folgen und bei 1 beginnen, nicht bei
     * dem Feldindex 0, den PHP liefert.
     */
    #[Test]
    public function aTeamOfSeveralBecomesAnOrderedList(): void
    {
        $this->rows = [
            $this->personRow(),
            $this->personRow([
                'uid' => 8,
                'moselwal_person_firstname' => 'Grace',
                'moselwal_person_lastname' => 'Hopper',
                'moselwal_person_slug' => 'grace-hopper',
            ]),
        ];

        $document = $this->person()->generate(
            $this->page(['title' => 'Unser Team']),
            [['uid' => 1, 'CType' => 'moselwal_team']],
        );

        self::assertSame('ItemList', $document->schemaType);
        self::assertSame('Unser Team', $document->data['name']);

        $items = $document->data['itemListElement'];
        self::assertCount(2, $items);
        self::assertSame([1, 2], array_column($items, 'position'), 'positions count from one');
        self::assertSame(['ListItem', 'ListItem'], array_column($items, '@type'));
        self::assertSame('Person', $items[0]['item']['@type']);
        self::assertSame('Ada Lovelace', $items[0]['item']['name']);
        self::assertSame('Grace Hopper', $items[1]['item']['name']);
    }

    /**
     * Der einzelne Mensch bleibt ein einzelner Mensch — keine Liste mit einem
     * Eintrag. Eine `ItemList` mit einem Element ist fuer einen Leser dasselbe
     * wie eine Person, fuer einen Auswerter aber nicht.
     */
    #[Test]
    public function aTeamOfOneStaysAPerson(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->person()->generate($this->page(), [['uid' => 1, 'CType' => 'moselwal_team']]);

        self::assertSame('Person', $document->schemaType);
        self::assertSame('Person', $document->data['@type']);
        self::assertArrayNotHasKey('itemListElement', $document->data);
    }

    /**
     * Ein Element ohne uid wird uebersprungen, statt die Personen des
     * Elternelements 0 zu holen — eine Abfrage, die jede Person ohne Zuordnung
     * traefe.
     */
    #[Test]
    public function anElementWithoutAUidIsSkipped(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->person()->generate($this->page(), [['CType' => 'moselwal_team']]);

        self::assertSame([], $document->data, 'nothing was looked up at all');
    }

    /**
     * Die Uebersetzung legt sich ueber die Standardzeile, die Systemfelder
     * bleiben die der Standardzeile.
     *
     * Das ist die Eigenschaft, die eine Seite mit `fallbackType: fallback`
     * traegt: die Reihenfolge und die Identitaet der Personen kommen aus der
     * Standardsprache, der Text aus der Uebersetzung — und wo keine
     * Uebersetzung gepflegt ist, bleibt der deutsche Text stehen, statt dass
     * die Person aus dem Schema verschwindet.
     *
     * Die Uebersetzungszeile hier fuehrt bewusst keinen eigenen Slug: die
     * Adresse der Person bleibt dadurch die der Standardzeile, waehrend der
     * Name der uebersetzte ist — genau die Trennung, um die es geht.
     */
    #[Test]
    public function aTranslationOverlaysTheTextAndKeepsTheIdentity(): void
    {
        $this->rowSets = [
            [$this->personRow(['uid' => 7])],
            [[
                'uid' => 8,
                'sys_language_uid' => 1,
                'l10n_parent' => 7,
                'moselwal_person_firstname' => 'Grace',
                'moselwal_person_lastname' => 'Hopper',
            ]],
        ];

        $document = $this->person()->generate(
            $this->page(['sys_language_uid' => 1]),
            [['uid' => 1, 'CType' => 'moselwal_team']],
        );

        self::assertSame('Person', $document->schemaType, 'still one person, not two');
        self::assertSame('Grace Hopper', $document->data['name'], 'the translated text won');
        self::assertSame(
            'https://example.com/autor/ada-lovelace#person',
            $document->data['@id'],
            "and the identity stayed the default row's",
        );
    }

    /**
     * In der Standardsprache wird nach Uebersetzungen gar nicht erst gefragt.
     */
    #[Test]
    public function theDefaultLanguageAsksForNoTranslations(): void
    {
        $this->rows = [$this->personRow()];

        $this->person()->generate($this->page(), [['uid' => 1, 'CType' => 'moselwal_team']]);

        self::assertLessThan(3, count($this->ordering), 'no second query for translations');
    }

    /**
     * TYPO3 14 verwirft Aspekte, die dem Konstruktor uebergeben werden — die
     * Sprache muss gesetzt werden, sonst prueft der Test eine andere.
     */
    private function defaultLanguageContext(): Context
    {
        $context = new Context();
        $context->setAspect('language', new LanguageAspect(0));

        return $context;
    }
}
