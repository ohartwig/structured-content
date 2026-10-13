<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use Koh\StructuredContent\Domain\Contract\CategoryRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Model\ResolvedContext;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ArticleSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Der Artikel — das Dokument, mit dem ein Blogbeitrag in Suchergebnissen und
 * in Antwortmaschinen auftaucht.
 *
 * Drei Eigenschaften tragen es, und alle drei zeigen sich nur ausserhalb der
 * eigenen Seite:
 *
 * Die `@id` und `mainEntityOfPage` haengen an der Adresse der Seite. Sie sind
 * das, was den Artikel als eine Sache ueber Sprachen, Verweise und
 * Verzeichnisse hinweg zusammenhaelt. Auf einer Seite ohne Adresse — und die
 * gibt es, wenn die Site-Basis fehlt — wird gar keine erfunden, denn eine
 * geratene Identitaet ist schlimmer als keine.
 *
 * Die Wurzelseite ist kein Artikel. Die Startseite traegt die Organisation und
 * die Website, nicht einen Beitrag; ein Artikel dort waere eine zweite
 * Haupt-Entitaet auf der wichtigsten Seite der Site.
 *
 * Und der Typ kommt aus der Aufloesung, nicht aus dem Klassennamen: eine Seite
 * kann als `TechArticle` oder `NewsArticle` gepflegt sein, und was ausgegeben
 * wird, muss das sein, was gepflegt wurde.
 */
final class ArticleSchemaGeneratorTest extends TestCase
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
            // Der Generator holt sich fuer die Bildreferenz einen eigenen
            // Verbindungspool ueber GeneralUtility, nicht den injizierten.
            GeneralUtility::addInstance(ConnectionPool::class, $this->connectionPool());
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /** @var list<array<string, mixed>> */
    private array $categories = [];

    private function generator(): ArticleSchemaGenerator
    {
        $categories = $this->createStub(CategoryRepositoryInterface::class);
        $categories->method('findByPageUid')->willReturnCallback(fn (): array => $this->categories);

        $context = $this->createStub(ContextResolverInterface::class);
        $context->method('resolve')->willReturn(ResolvedContext::withDefaults());
        $context->method('resolveFromPageTree')->willReturn(ResolvedContext::withDefaults());

        $relations = $this->createStub(RelationshipRepositoryInterface::class);
        $relations->method('findBySource')->willReturn([]);
        $relations->method('findBidirectionalByTarget')->willReturn([]);

        return new ArticleSchemaGenerator(
            // Beide sind final und werden echt gebaut — was der Aufloeser aus
            // den Seitendaten macht, ist hier Teil des Verhaltens.
            new SchemaTypeResolverService(),
            new PersonSchemaBuilder($this->connectionPool()),
            $categories,
            $context,
            $relations,
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
            'title' => 'Wie Suchmaschinen Podcasts lesen',
            'description' => 'Eine Einfuehrung in strukturierte Daten.',
            'slug' => '/blog/strukturierte-daten',
            '_siteBase' => 'https://example.com/',
            '_language' => 'de-DE',
            'tx_structuredcontent_schema_type' => 'BlogPosting',
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function content(): array
    {
        return [[
            'uid' => 1,
            'CType' => 'text',
            'header' => 'Warum das zaehlt',
            'bodytext' => '<p>Strukturierte Daten sind das, was eine Maschine liest.</p>',
        ]];
    }

    #[Test]
    public function aPageMarkedAsAnArticleIsHandled(): void
    {
        self::assertTrue($this->generator()->canHandle($this->page(), $this->content()));
    }

    /**
     * Die zweite Eigenschaft: die Wurzelseite ist kein Artikel. Sie traegt
     * Organisation und Website; ein Artikel dort waere eine zweite
     * Haupt-Entitaet auf der wichtigsten Seite der Site.
     */
    #[Test]
    public function theRootPageIsNeverAnArticle(): void
    {
        self::assertFalse(
            $this->generator()->canHandle($this->page(['uid' => 1, '_rootPageId' => 1]), $this->content()),
        );
    }

    /**
     * Eine Seite, die als etwas anderes gepflegt ist, ist kein Artikel — der
     * Generator faengt nicht ab, was ein anderer besser kann.
     */
    #[Test]
    public function aPageMarkedAsSomethingElseIsNotAnArticle(): void
    {
        foreach (['Product', 'Service', 'SoftwareApplication', 'Organization'] as $type)  {
            self::assertFalse(
                $this->generator()->canHandle($this->page(['tx_structuredcontent_schema_type' => $type]), $this->content()),
                $type,
            );
        }
    }

    /**
     * Die dritte: der gepflegte Typ wird ausgegeben, nicht ein fester. Eine
     * Anleitung als `Article` statt `TechArticle` auszuliefern, verschenkt
     * genau die Unterscheidung, die jemand gepflegt hat.
     */
    #[Test]
    public function theMaintainedTypeIsWhatIsEmitted(): void
    {
        foreach (['BlogPosting', 'NewsArticle', 'TechArticle'] as $type) {
            $document = $this->generator()->generate(
                $this->page(['tx_structuredcontent_schema_type' => $type]),
                $this->content(),
            );

            self::assertSame($type, $document->schemaType, $type);
        }
    }

    /**
     * Die erste Eigenschaft: Identitaet und Seitenbezug haengen an der
     * Adresse. Sie halten den Artikel ueber Sprachen und Verweise hinweg
     * zusammen.
     */
    #[Test]
    public function theArticleIsIdentifiedByItsPageUrl(): void
    {
        $document = $this->generator()->generate($this->page(), $this->content());

        self::assertSame('https://example.com/blog/strukturierte-daten#blogposting', $document->data['@id']);
        self::assertSame(
            ['@id' => 'https://example.com/blog/strukturierte-daten#webpage'],
            $document->data['mainEntityOfPage'],
        );
        self::assertSame(['@id' => 'https://example.com#website'], $document->data['isPartOf']);
    }

    /**
     * Ohne Adresse wird keine erfunden. Eine geratene Identitaet ist
     * schlimmer als keine: zwei Artikel ohne Site-Basis bekaemen dieselbe.
     */
    #[Test]
    public function withoutAPageUrlNoIdentityIsInvented(): void
    {
        $document = $this->generator()->generate($this->page(['_siteBase' => '', 'slug' => '']), $this->content());

        self::assertArrayNotHasKey('@id', $document->data);
        self::assertArrayNotHasKey('mainEntityOfPage', $document->data);
        self::assertArrayNotHasKey('isPartOf', $document->data);
    }

    #[Test]
    public function theHeadlineAndDescriptionComeFromThePage(): void
    {
        $document = $this->generator()->generate($this->page(), $this->content());

        self::assertSame('Wie Suchmaschinen Podcasts lesen', $document->data['headline']);
        self::assertSame('Eine Einfuehrung in strukturierte Daten.', $document->data['description']);
    }

    /**
     * Fehlt die Beschreibung, tritt der Anrisstext an ihre Stelle — eine
     * Beschreibung ist das, woran ein Leser entscheidet, ob der Artikel zur
     * Frage passt.
     */
    #[Test]
    public function theAbstractStandsInWhenThereIsNoDescription(): void
    {
        $page = $this->page(['description' => '', 'abstract' => 'Der Anriss.']);

        self::assertSame('Der Anriss.', $this->generator()->generate($page, $this->content())->data['description']);
    }

    /**
     * Der Wortumfang kommt aus dem Inhalt der Seite, nicht aus einer Angabe im
     * Feld — eine gepflegte Zahl waere die Zahl von gestern.
     */
    #[Test]
    public function theWordCountIsCountedFromTheContent(): void
    {
        $document = $this->generator()->generate($this->page(), $this->content());

        self::assertArrayHasKey('wordCount', $document->data);
        self::assertGreaterThan(0, $document->data['wordCount']);
    }

    /**
     * Und er wird auf dasselbe Wort genau gezaehlt wie die Lesezeit im
     * Frontend. Beide Zaehlungen sind absichtlich zwei getrennte Kopien
     * derselben Logik — sitepackage-base/PageReadingTimeProcessor::wordCount()
     * und die private countWords() hier — damit die Pakete voneinander
     * unabhaengig bleiben. Was zwei Kopien brauchen, ist ein Test, der beide
     * an dieselbe Zahl bindet; sonst faellt eine Abweichung erst auf, wenn ein
     * Leser die Lesezeit gegen `timeRequired` haelt.
     *
     * Der Fliesstext hier traegt genau die zwei Faelle, an denen die Zaehlung
     * bis zum 15.08.2026 falsch lag: ein `<br>`, das zwei Woerter
     * zusammenklebte, und ein `<pre>`, dessen schliessendes Tag als `</p`
     * getroffen wurde und `re>` als eigenes Wort hinterliess.
     */
    #[Test]
    public function theWordCountMatchesTheFrontendReadingTimeToTheWord(): void
    {
        $content = [[
            'uid' => 1,
            'CType' => 'text',
            'header' => 'Zwei Woerter',
            'bodytext' => '<p>Erste Zeile.<br>Zweite Zeile.</p><pre>composer install</pre>',
        ]];

        // header: "Zwei" "Woerter"                     = 2
        // bodytext: "Erste" "Zeile" "Zweite" "Zeile"   = 4
        //           "composer" "install"               = 2
        self::assertSame(8, $this->generator()->generate($this->page(), $content)->data['wordCount']);
    }

    /**
     * Und der Fliesstext wird als Text ausgegeben, ohne Auszeichnung. Ein
     * `articleBody` mit HTML darin ist fuer jeden Leser Text mit spitzen
     * Klammern.
     */
    #[Test]
    public function theBodyIsPlainTextRatherThanMarkup(): void
    {
        $document = $this->generator()->generate($this->page(), $this->content());

        if (isset($document->data['articleBody'])) {
            self::assertStringNotContainsString('<p>', $document->data['articleBody']);
            self::assertStringContainsString('Strukturierte Daten', $document->data['articleBody']);
        }
    }

    /**
     * Die Sprache wird als BCP47-Angabe ausgegeben — `de` allein sagt nicht,
     * ob es die deutsche oder die oesterreichische Fassung ist.
     */
    #[Test]
    public function theLanguageIsCarriedAsGiven(): void
    {
        $document = $this->generator()->generate($this->page(), $this->content());

        self::assertSame('de-DE', $document->data['inLanguage'] ?? null);
    }

    /**
     * Der Artikel steht ueber der generischen Erkennung (10) und der WebPage
     * (45), aber unter den spezielleren — FAQ (70) und Podcast-Folge (80)
     * beschreiben eine Seite genauer, wenn sie zutreffen.
     */
    #[Test]
    public function theArticleSitsBetweenTheGenericAndTheSpecificGenerators(): void
    {
        $priority = $this->generator()->getPriority();

        self::assertGreaterThan(45, $priority);
        self::assertLessThan(70, $priority);
    }


    /**
     * @return array<string, mixed>
     */
    private function personRow(array $overrides = []): array
    {
        return [
            'uid' => 7,
            'uid_local' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
            ...$overrides,
        ];
    }

    /**
     * Ein gepflegter Autor erscheint als Verweis auf seine Personen-Identitaet,
     * nicht als ausgeschriebene Person. Derselbe Autor steht auf zwanzig
     * Artikelseiten; ihn zwanzigmal auszuschreiben macht aus einem Verweis
     * zwanzig Beschreibungen, die auseinanderlaufen koennen.
     */
    #[Test]
    public function aMaintainedAuthorAppearsAsAReferenceToTheirIdentity(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->generator()->generate(
            $this->page(['tx_structuredcontent_author_records' => '7']),
            $this->content(),
        );

        self::assertSame(
            ['@type' => 'Person', '@id' => 'https://example.com/autor/ada-lovelace#person', 'name' => 'Ada Lovelace'],
            $document->data['author'],
        );
    }

    /**
     * Mehrere Autoren werden zur Liste, einer bleibt ein Objekt — eine Liste
     * mit einem Element sagt etwas anderes als ein einzelner Autor.
     */
    #[Test]
    public function severalAuthorsBecomeAList(): void
    {
        $this->rows = [$this->personRow(), $this->personRow(['uid' => 9, 'moselwal_person_slug' => 'grace-hopper'])];

        $document = $this->generator()->generate(
            $this->page(['tx_structuredcontent_author_records' => '7,9']),
            $this->content(),
        );

        self::assertCount(2, $document->data['author']);
    }

    /**
     * Ohne gepflegten Datensatz tritt das Freitextfeld ein — ein Artikel ohne
     * Autor ist in jedem Suchergebnis ein Artikel ohne Herkunft.
     */
    #[Test]
    public function aTextAuthorStandsInWhenNoRecordIsMaintained(): void
    {
        $document = $this->generator()->generate(
            $this->page(['author' => 'Ada Lovelace', 'author_email' => 'ada@example.com']),
            $this->content(),
        );

        self::assertSame('Person', $document->data['author']['@type']);
        self::assertSame('Ada Lovelace', $document->data['author']['name']);
        self::assertSame('ada@example.com', $document->data['author']['email']);
    }

    /**
     * Der Herausgeber ist die Organisation der Site — als Verweis. Ein Artikel
     * ohne Herausgeber ist in den Rich Results unvollstaendig.
     */
    #[Test]
    public function thePublisherIsTheSitesOrganisation(): void
    {
        $document = $this->generator()->generate($this->page(), $this->content());

        self::assertSame(['@id' => 'https://example.com#organization'], $document->data['publisher']);
    }

    /**
     * Die Kategorien der Seite werden zur Rubrik. Eine bleibt ein Wert,
     * mehrere werden zur Liste.
     */
    #[Test]
    public function theCategoriesBecomeTheArticleSection(): void
    {
        $this->categories = [['title' => 'Technik']];
        $one = $this->generator()->generate($this->page(), $this->content());
        self::assertSame('Technik', $one->data['articleSection']);

        $this->categories = [['title' => 'Technik'], ['title' => 'Suchmaschinen']];
        $several = $this->generator()->generate($this->page(), $this->content());
        self::assertSame(['Technik', 'Suchmaschinen'], $several->data['articleSection']);
    }

    /**
     * Eine Kategorie ohne Titel faellt heraus, statt als leere Rubrik zu
     * erscheinen.
     */
    #[Test]
    public function aCategoryWithoutATitleIsLeftOut(): void
    {
        $this->categories = [['title' => '  '], ['title' => 'Technik']];

        $document = $this->generator()->generate($this->page(), $this->content());

        self::assertSame('Technik', $document->data['articleSection']);
    }

    /**
     * Erstellungs- und Aenderungszeit werden in die Form gebracht, die
     * Schema.org verlangt — ein Unix-Zeitstempel ist fuer einen Leser keine
     * Zeitangabe.
     */
    #[Test]
    public function theDatesAreEmittedInTheFormTheSpecWants(): void
    {
        $document = $this->generator()->generate(
            $this->page(['crdate' => 1785200000, 'tstamp' => 1785300000]),
            $this->content(),
        );

        self::assertStringStartsWith('2026-', $document->data['datePublished']);
        self::assertStringStartsWith('2026-', $document->data['dateModified']);
        self::assertNotSame($document->data['datePublished'], $document->data['dateModified']);
    }

    /**
     * Der Generator laeuft auch ohne die beiden optionalen Mitspieler — sie
     * sind nicht in jeder Installation verdrahtet, und ein Artikel ohne
     * Kontextangaben ist besser als kein Artikel.
     */
    #[Test]
    public function theGeneratorWorksWithoutItsOptionalCollaborators(): void
    {
        $generator = new ArticleSchemaGenerator(
            new SchemaTypeResolverService(),
            new PersonSchemaBuilder($this->connectionPool()),
            $this->createStub(CategoryRepositoryInterface::class),
        );

        $document = $generator->generate($this->page(), $this->content());

        self::assertSame('BlogPosting', $document->schemaType);
        self::assertSame('Wie Suchmaschinen Podcasts lesen', $document->data['headline']);
    }

    #[Test]
    public function fileUrlIgnoresTheLanguagePathOfTheSiteBase(): void
    {
        // Regression: the EN site base https://example.com/en produced
        // https://example.com/en/fileadmin/..., a 404 that Googlebot hit
        // hundreds of times from the JSON-LD ImageObject.contentUrl.
        self::assertSame(
            'https://example.com/fileadmin/user_upload/hero.jpg',
            ArticleSchemaGenerator::fileUrl('https://example.com/en/', '/user_upload/hero.jpg'),
        );
        self::assertSame(
            'https://example.com/fileadmin/user_upload/hero.jpg',
            ArticleSchemaGenerator::fileUrl('https://example.com/', 'user_upload/hero.jpg'),
        );
        self::assertSame(
            'http://localhost:8080/fileadmin/a.jpg',
            ArticleSchemaGenerator::fileUrl('http://localhost:8080/de', '/a.jpg'),
        );
    }

    #[Test]
    public function fileUrlIsEmptyWithoutHostOrIdentifier(): void
    {
        self::assertSame('', ArticleSchemaGenerator::fileUrl('', '/a.jpg'));
        self::assertSame('', ArticleSchemaGenerator::fileUrl('/en/', '/a.jpg'));
        self::assertSame('', ArticleSchemaGenerator::fileUrl('https://example.com/', ''));
    }
}
