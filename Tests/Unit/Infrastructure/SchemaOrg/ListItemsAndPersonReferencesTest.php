<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\ItemListSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Was in einer Liste steht, und wie eine Person darin auftaucht.
 *
 * Die Listeneintraege haben eine Eigenschaft, die man nur an der Ausgabe
 * sieht: `position` zaehlt die *ausgegebenen* Eintraege, nicht die gelesenen
 * Zeilen. Eine Karte ohne Ueberschrift faellt heraus, und die Nummerierung
 * muss danach wieder aufschliessen — eine ItemList, die von 1 auf 3 springt,
 * ist fuer jeden Leser eine Liste mit einem fehlenden Eintrag.
 *
 * Bei den Personenverweisen ist es die Sparsamkeit: ein Verweis traegt nur
 * Identitaet und Namen, nicht die ganze Person. Das vollstaendige Objekt steht
 * auf der Autorenseite; es auf jeder Artikelseite zu wiederholen, macht aus
 * einem Verweis eine zweite Quelle der Wahrheit, die auseinanderlaufen kann.
 *
 * Und ohne Identitaet gibt es keinen Verweis: eine Person ohne Slug oder ohne
 * Site-Basis kann nicht adressiert werden, und ein Verweis ohne `@id` ist kein
 * Verweis, sondern eine zweite, namenlose Person.
 */
final class ListItemsAndPersonReferencesTest extends TestCase
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
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function card(array $overrides = []): array
    {
        return [
            'uid' => 10,
            'uid_local' => 0,
            'moselwal_card_header' => 'Beratung',
            'moselwal_card_cta_link' => 't3://page?uid=42',
            'moselwal_card_cta_label' => 'Mehr erfahren',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        return [
            'uid' => 42,
            'title' => 'Leistungen',
            'slug' => '/leistungen/beratung',
            '_siteBase' => 'https://example.com/',
            '_languageId' => 0,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cardBlock(): array
    {
        return [['uid' => 1, 'CType' => 'moselwal_cards', 'header' => 'Was wir anbieten']];
    }

    #[Test]
    public function theCardsBecomeListItemsWithTheirNames(): void
    {
        $this->rows = [
            $this->card(),
            $this->card(['uid' => 11, 'moselwal_card_header' => 'Umsetzung']),
        ];

        $document = (new ItemListSchemaGenerator($this->connectionPool()))->generate($this->page(), $this->cardBlock());

        self::assertSame(2, $document->data['numberOfItems']);
        self::assertSame(['Beratung', 'Umsetzung'], array_column($document->data['itemListElement'], 'name'));
        self::assertSame('ListItem', $document->data['itemListElement'][0]['@type']);
    }

    /**
     * Die Eigenschaft, die man nur an der Ausgabe sieht: `position` zaehlt die
     * ausgegebenen Eintraege. Eine Liste, die von 1 auf 3 springt, ist fuer
     * jeden Leser eine Liste mit einem fehlenden Eintrag.
     */
    #[Test]
    public function theNumberingCountsWhatIsEmittedNotWhatWasRead(): void
    {
        $this->rows = [
            $this->card(),
            $this->card(['uid' => 11, 'moselwal_card_header' => '   ']),
            $this->card(['uid' => 12, 'moselwal_card_header' => 'Umsetzung']),
        ];

        $document = (new ItemListSchemaGenerator($this->connectionPool()))->generate($this->page(), $this->cardBlock());

        self::assertSame([1, 2], array_column($document->data['itemListElement'], 'position'));
        self::assertSame(2, $document->data['numberOfItems']);
    }

    /**
     * Die Karten kommen in ihrer gepflegten Reihenfolge — eine Liste ist eine
     * Reihenfolge, und die Datenbank hat von sich aus keine.
     */
    #[Test]
    public function theCardsKeepTheirMaintainedOrder(): void
    {
        $this->rows = [$this->card()];

        (new ItemListSchemaGenerator($this->connectionPool()))->generate($this->page(), $this->cardBlock());

        self::assertSame([['sorting', 'ASC']], $this->ordering);
    }

    /**
     * Ein interner Verweis wird zu einer Adresse aufgeloest; was sich nicht
     * aufloesen laesst, bleibt weg. Ein ListItem mit einer `t3://`-Adresse ist
     * ein Link, den ausserhalb von TYPO3 niemand folgen kann.
     */
    #[Test]
    public function anUnresolvableLinkIsLeftOutRatherThanEmittedRaw(): void
    {
        $this->rows = [$this->card(['moselwal_card_cta_link' => 't3://page?uid=999'])];

        $document = (new ItemListSchemaGenerator($this->connectionPool()))->generate($this->page(), $this->cardBlock());

        $item = $document->data['itemListElement'][0];

        if (isset($item['url'])) {
            self::assertStringStartsNotWith('t3://', $item['url']);
        } else {
            self::assertArrayNotHasKey('url', $item);
        }
    }

    /**
     * Eine externe Adresse bleibt, wie sie ist — sie zeigt bewusst weg von
     * dieser Site.
     */
    #[Test]
    public function anExternalLinkIsCarriedAsItIs(): void
    {
        $this->rows = [$this->card(['moselwal_card_cta_link' => 'https://partner.example.org/angebot'])];

        $document = (new ItemListSchemaGenerator($this->connectionPool()))->generate($this->page(), $this->cardBlock());

        self::assertSame(
            'https://partner.example.org/angebot',
            $document->data['itemListElement'][0]['url'] ?? null,
        );
    }

    /**
     * Die Sparsamkeit des Verweises: Identitaet und Name, mehr nicht. Das
     * vollstaendige Objekt steht auf der Autorenseite; es hier zu wiederholen
     * macht aus einem Verweis eine zweite Quelle der Wahrheit.
     */
    #[Test]
    public function aPersonReferenceCarriesIdentityAndNameAndNothingElse(): void
    {
        $this->rows = [[
            'uid' => 7,
            'uid_local' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
            'moselwal_person_bio' => 'Eine lange Biografie, die hier nichts zu suchen hat.',
            'moselwal_person_email' => 'ada@example.com',
        ]];

        $reference = (new PersonSchemaBuilder($this->connectionPool()))
            ->buildPersonReference(7, 'https://example.com/');

        self::assertSame(
            [
                '@type' => 'Person',
                '@id' => 'https://example.com/autor/ada-lovelace#person',
                'name' => 'Ada Lovelace',
            ],
            $reference,
        );
    }

    /**
     * Und ohne Adressierbarkeit gibt es keinen Verweis — ein Verweis ohne
     * `@id` ist keiner, sondern eine zweite, namenlose Person.
     */
    #[Test]
    public function withoutSomethingToPointAtThereIsNoReference(): void
    {
        $builder = new PersonSchemaBuilder($this->connectionPool());

        self::assertNull($builder->buildPersonReference(0, 'https://example.com'), 'no uid');
        self::assertNull($builder->buildPersonReference(7, ''), 'no site base');

        $this->rows = [['uid' => 7, 'uid_local' => 0, 'moselwal_person_firstname' => 'Ada', 'moselwal_person_slug' => '']];
        self::assertNull($builder->buildPersonReference(7, 'https://example.com'), 'no slug');
    }

    #[Test]
    public function anUnknownPersonHasNoReference(): void
    {
        self::assertNull(
            (new PersonSchemaBuilder($this->connectionPool()))->buildPersonReference(9999, 'https://example.com'),
        );
    }

    /**
     * Der Nachschlag ueber den Slug ist, was eine Autorenseite mit ihrer
     * Person verbindet. Er findet dieselbe Person wie der Nachschlag ueber
     * die uid — sonst zeigt die Seite eine andere Person als der Artikel,
     * der auf sie verweist.
     */
    #[Test]
    public function aPersonIsFoundByTheSlugInTheirPageAddress(): void
    {
        $this->rows = [[
            'uid' => 7,
            'uid_local' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
        ]];

        $builder = new PersonSchemaBuilder($this->connectionPool());

        $bySlug = $builder->findBySlug('ada-lovelace');
        self::assertNotNull($bySlug);
        self::assertSame(7, (int) $bySlug['uid']);

        $byIdentifier = $builder->findByIdentifier('ada-lovelace');
        self::assertNotNull($byIdentifier);
        self::assertSame(7, (int) $byIdentifier['uid']);
    }

    /**
     * Ein leerer Slug findet niemanden — sonst waere die Autorenseite ohne
     * Argument die Seite irgendeiner Person.
     */
    #[Test]
    public function anEmptySlugFindsNobody(): void
    {
        $this->rows = [['uid' => 7, 'uid_local' => 0, 'moselwal_person_slug' => 'ada-lovelace']];

        $builder = new PersonSchemaBuilder($this->connectionPool());

        self::assertNull($builder->findBySlug(''));
        self::assertNull($builder->findByIdentifier(''));
    }
}
