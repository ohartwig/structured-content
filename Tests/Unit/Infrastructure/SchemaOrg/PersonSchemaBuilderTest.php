<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Doctrine\DBAL\Result;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Person hinter einem Artikel — und damit das, was Suchmaschinen als
 * Autorenschaft lesen.
 *
 * Sechs Generatoren bauen ihre Autoren ueber diese Klasse, also gilt jeder
 * Fehler hier auf einen Schlag fuer alle. Drei Eigenschaften tragen das:
 *
 * Die `@id` ist die Identitaet der Person ueber die ganze Site. Sie ist der
 * Grund, warum ein Autor auf zwanzig Artikelseiten als *dieselbe* Person
 * erkannt wird und nicht als zwanzig gleichnamige. Faellt sie weg oder haengt
 * sie an etwas Wechselndem, zerfaellt genau diese Verbindung — und zwar
 * unsichtbar, weil jede einzelne Seite fuer sich gueltig bleibt.
 *
 * Kontaktangaben werden nur uebernommen, wenn sie plausibel sind. Eine
 * kaputte E-Mail-Adresse im JSON-LD ist eine veroeffentlichte kaputte
 * Adresse; eine externe URL ohne Schema ist ein Link, der nirgends hinfuehrt.
 *
 * Und eine Person ohne Namen ergibt kein Objekt. `{"@type":"Person"}` ohne
 * `name` ist fuer jeden Leser eine Person, ueber die nichts bekannt ist —
 * schlechter als gar keine Angabe.
 */
final class PersonSchemaBuilderTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 0; $i < 64; ++$i) {
            GeneralUtility::addInstance(TcaSchemaFactory::class, $this->createStub(TcaSchemaFactory::class));
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    private function builder(): PersonSchemaBuilder
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturnCallback(fn (): array|false => $this->rows[0] ?? false);
        $result->method('fetchAllAssociative')->willReturnCallback(fn (): array => $this->rows);
        $result->method('fetchOne')->willReturn(false);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return new PersonSchemaBuilder($pool);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return [
            'uid' => 7,
            // Dieselbe Zeilenquelle beantwortet auch die Bildreferenz-Abfrage;
            // 0 heisst dort: keine Datei verknuepft.
            'uid_local' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
            'moselwal_person_job_title' => 'Mathematikerin',
            'moselwal_person_bio' => 'Schrieb das erste Programm.',
            'moselwal_person_email' => 'ada@example.com',
            ...$overrides,
        ];
    }

    #[Test]
    public function aPersonIsBuiltFromTheirRecord(): void
    {
        $person = $this->builder()->buildFromRow($this->row(), 'https://example.com');

        self::assertNotNull($person);
        self::assertSame('Person', $person['@type']);
        self::assertSame('Ada Lovelace', $person['name']);
        self::assertSame('Ada', $person['givenName']);
        self::assertSame('Lovelace', $person['familyName']);
        self::assertSame('Mathematikerin', $person['jobTitle']);
        self::assertSame('Schrieb das erste Programm.', $person['description']);
    }

    /**
     * Die Regression, auf die es ankommt: die Identitaet haengt am Slug und
     * an der Site-Basis. Sie ist der Grund, warum derselbe Autor auf zwanzig
     * Seiten als eine Person erkannt wird.
     */
    #[Test]
    public function theIdentityIsTheAuthorPageUnderTheSiteBase(): void
    {
        $person = $this->builder()->buildFromRow($this->row(), 'https://example.com/');

        self::assertNotNull($person);
        self::assertSame('https://example.com/autor/ada-lovelace#person', $person['@id']);
        self::assertSame('https://example.com/autor/ada-lovelace', $person['url']);
    }

    /**
     * Ohne Slug gibt es keine Autorenseite und damit keine Identitaet. Eine
     * `@id` zu erfinden waere schlimmer: zwei Personen ohne Slug bekaemen
     * dieselbe.
     */
    #[Test]
    public function aPersonWithoutASlugGetsNoIdentity(): void
    {
        $person = $this->builder()->buildFromRow($this->row(['moselwal_person_slug' => '']), 'https://example.com');

        self::assertNotNull($person);
        self::assertArrayNotHasKey('@id', $person);
    }

    /**
     * Ohne Site-Basis ebenso — eine relative `@id` ist keine Identitaet,
     * sondern ein Fragment, das auf jeder Domain etwas anderes bedeutet.
     */
    #[Test]
    public function aPersonBuiltWithoutASiteBaseGetsNoIdentity(): void
    {
        $person = $this->builder()->buildFromRow($this->row());

        self::assertNotNull($person);
        self::assertArrayNotHasKey('@id', $person);
    }

    /**
     * Die zweite Regression: Kontaktangaben werden geprueft. Eine kaputte
     * Adresse im JSON-LD ist eine veroeffentlichte kaputte Adresse.
     */
    #[Test]
    public function anImplausibleEmailIsNotPublished(): void
    {
        foreach (['not-an-email', 'ada@', '@example.com', 'ada example.com'] as $email) {
            $person = $this->builder()->buildFromRow($this->row(['moselwal_person_email' => $email]));

            self::assertNotNull($person, $email);
            self::assertArrayNotHasKey('email', $person, $email);
        }
    }

    #[Test]
    public function aPlausibleEmailIsPublished(): void
    {
        $person = $this->builder()->buildFromRow($this->row());

        self::assertNotNull($person);
        self::assertSame('ada@example.com', $person['email']);
    }

    /**
     * Eine externe Adresse ohne Schema fuehrt nirgends hin und wird nicht
     * uebernommen — aber nur, wenn es keine Autorenseite gibt: die gewinnt,
     * weil sie auf dieser Site liegt.
     */
    #[Test]
    public function anExternalUrlIsOnlyUsedWhenItLooksLikeOneAndThereIsNoAuthorPage(): void
    {
        $withoutSlug = ['moselwal_person_slug' => ''];

        $valid = $this->builder()->buildFromRow(
            $this->row([...$withoutSlug, 'moselwal_person_url' => 'https://ada.example.org']),
        );
        self::assertSame('https://ada.example.org', $valid['url'] ?? null);

        foreach (['ada.example.org', 'javascript:alert(1)', 'ftp://ada.example.org'] as $url) {
            $person = $this->builder()->buildFromRow($this->row([...$withoutSlug, 'moselwal_person_url' => $url]));

            self::assertArrayNotHasKey('url', $person ?? [], $url);
        }

        $withAuthorPage = $this->builder()->buildFromRow(
            $this->row(['moselwal_person_url' => 'https://ada.example.org']),
            'https://example.com',
        );
        self::assertSame('https://example.com/autor/ada-lovelace', $withAuthorPage['url'] ?? null);
    }

    /**
     * Die dritte: ohne Namen gibt es kein Objekt. `{"@type":"Person"}` ohne
     * `name` ist eine Person, ueber die nichts bekannt ist.
     */
    #[Test]
    public function aPersonWithoutANameIsNotBuiltAtAll(): void
    {
        foreach ([
            'nothing at all' => ['moselwal_person_firstname' => '', 'moselwal_person_lastname' => ''],
            'only whitespace' => ['moselwal_person_firstname' => '  ', 'moselwal_person_lastname' => "\t"],
        ] as $why => $override) {
            self::assertNull($this->builder()->buildFromRow($this->row($override)), $why);
        }
    }

    /**
     * Ein halber Name reicht — viele Personen fuehren nur einen, und ihn
     * wegzulassen waere schlechter als ein unvollstaendiger Eintrag.
     */
    #[Test]
    public function halfANameIsStillAName(): void
    {
        $onlyFirst = $this->builder()->buildFromRow($this->row(['moselwal_person_lastname' => '']));
        $onlyLast = $this->builder()->buildFromRow($this->row(['moselwal_person_firstname' => '']));

        self::assertSame('Ada', $onlyFirst['name'] ?? null);
        self::assertSame('Lovelace', $onlyLast['name'] ?? null);
    }

    /**
     * Titel gehoeren in ihre eigenen Felder, nicht in den Namen. `name` ist
     * das, was als Autorenzeile erscheint.
     */
    #[Test]
    public function honorificsGoIntoTheirOwnFields(): void
    {
        $person = $this->builder()->buildFromRow($this->row([
            'moselwal_person_honorific_prefix' => 'Dr.',
            'moselwal_person_honorific_suffix' => 'M.Sc.',
        ]));

        self::assertNotNull($person);
        self::assertSame('Dr.', $person['honorificPrefix']);
        self::assertSame('M.Sc.', $person['honorificSuffix']);
    }

    /**
     * Ohne eigene Arbeitgeberangabe gehoert die Person zur Organisation
     * dieser Site — als Verweis, nicht als zweite Kopie der Organisation.
     */
    #[Test]
    public function aPersonWithoutAnEmployerBelongsToTheSitesOrganisation(): void
    {
        $person = $this->builder()->buildFromRow($this->row(), 'https://example.com');

        self::assertNotNull($person);
        self::assertSame(['@id' => 'https://example.com#organization'], $person['worksFor']);
    }

    /**
     * Mit Angabe gewinnt sie — und mehrere Zeilen werden zu mehreren
     * Organisationen, eine einzelne bleibt ein Objekt statt einer Liste mit
     * einem Element.
     */
    #[Test]
    public function anEmployerFieldWinsAndKeepsItsShape(): void
    {
        $one = $this->builder()->buildFromRow(
            $this->row(['moselwal_person_works_for' => 'Analytical Engines Ltd']),
            'https://example.com',
        );
        self::assertSame(['@type' => 'Organization', 'name' => 'Analytical Engines Ltd'], $one['worksFor'] ?? null);

        $several = $this->builder()->buildFromRow(
            $this->row(['moselwal_person_works_for' => "Analytical Engines Ltd\nRoyal Society"]),
            'https://example.com',
        );
        self::assertCount(2, $several['worksFor'] ?? []);
    }

    /**
     * Eine uid, die es nicht gibt, ergibt keine Person — und keine Abfrage
     * fuer uid 0, die in TYPO3 nie einen Datensatz meint.
     */
    #[Test]
    public function anUnknownOrImpossibleUidYieldsNothing(): void
    {
        self::assertNull($this->builder()->buildFromPersonUid(0));
        self::assertNull($this->builder()->buildFromPersonUid(-1));
        self::assertNull($this->builder()->buildFromPersonUid(9999));
    }

    #[Test]
    public function aKnownUidYieldsThePerson(): void
    {
        $this->rows = [$this->row()];

        $person = $this->builder()->buildFromPersonUid(7, 'https://example.com');

        self::assertSame('Ada Lovelace', $person['name'] ?? null);
    }

    /**
     * Die Liste kommt aus einem Gruppenfeld und damit als CSV, als Array
     * oder als einzelne Zahl. Alle drei muessen dieselbe Liste ergeben —
     * sonst haengt es vom Feldtyp ab, ob die Autoren einer Seite erscheinen.
     */
    #[Test]
    public function aUidListIsReadInEveryShapeAGroupFieldProduces(): void
    {
        $builder = $this->builder();

        self::assertSame([7, 9], $builder->parseUidList('7,9'));
        self::assertSame([7, 9], $builder->parseUidList([7, 9]));
        self::assertSame([7, 9], $builder->parseUidList(['7', '9']));
        self::assertSame([7], $builder->parseUidList(7));
        self::assertSame([7], $builder->parseUidList('7'));
    }

    /**
     * Und was keine uid ist, faellt heraus statt als 0 durchzurutschen —
     * uid 0 ist in TYPO3 kein Datensatz, und eine Abfrage darauf ist eine
     * Abfrage ins Leere.
     */
    #[Test]
    public function anythingThatIsNotAUidIsDropped(): void
    {
        $builder = $this->builder();

        self::assertSame([], $builder->parseUidList(''));
        self::assertSame([], $builder->parseUidList(null));
        self::assertSame([], $builder->parseUidList('0'));
        self::assertSame([], $builder->parseUidList([0, -1]));
        self::assertSame([7], $builder->parseUidList('7, , 0'));
    }

    /**
     * Ein Autor, der nur als Freitext existiert, bekommt trotzdem ein
     * Person-Objekt — die Alternative waere ein Artikel ohne Autor.
     */
    #[Test]
    public function anAuthorThatExistsOnlyAsTextStillBecomesAPerson(): void
    {
        $person = $this->builder()->buildFromAuthorTextField('Ada Lovelace', 'ada@example.com');

        self::assertSame('Person', $person['@type']);
        self::assertSame('Ada Lovelace', $person['name']);
        self::assertSame('ada@example.com', $person['email']);
    }

    #[Test]
    public function aTextAuthorWithAnImplausibleEmailKeepsTheNameAndDropsTheAddress(): void
    {
        $person = $this->builder()->buildFromAuthorTextField('Ada Lovelace', 'not-an-email');

        self::assertSame('Ada Lovelace', $person['name']);
        self::assertArrayNotHasKey('email', $person);
    }
}
