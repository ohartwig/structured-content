<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\AuthoredPagesRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\ClaimsPageEntityInterface;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ProfilePageSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\SoftwareApplicationSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Software-Seite und die Autorenseite.
 *
 * Bei der Software ist die Lizenz das Interessante. Sie wird als Adresse
 * ausgegeben, nicht als Kuerzel — `license: "MIT"` ist fuer einen Leser eine
 * Zeichenkette, `https://opensource.org/licenses/MIT` ist eine Aussage, die er
 * aufloesen kann. Wer ein Kuerzel pflegt, bekommt trotzdem die Adresse.
 *
 * Bei der Autorenseite ist es die Identitaet: die Seite beschreibt *die
 * Person*, und die Person ist dieselbe wie die, auf die jeder Artikel
 * verweist. `mainEntity` zeigt deshalb als Verweis auf sie, statt sie ein
 * zweites Mal auszuschreiben — sonst haette derselbe Autor zwei Beschreibungen,
 * die auseinanderlaufen koennen.
 */
final class SoftwareAndProfilePageSchemaGeneratorTest extends TestCase
{
    use PodcastFeedDoubles;

    /** @var list<array<string, mixed>> */
    private array $articles = [];

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

    private function software(): SoftwareApplicationSchemaGenerator
    {
        return new SoftwareApplicationSchemaGenerator(new PersonSchemaBuilder($this->connectionPool()));
    }

    private function profile(): ProfilePageSchemaGenerator
    {
        $authored = $this->createStub(AuthoredPagesRepositoryInterface::class);
        $authored->method('findByAuthorUid')->willReturnCallback(fn (): array => $this->articles);

        return new ProfilePageSchemaGenerator(new PersonSchemaBuilder($this->connectionPool()), $authored);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function softwarePage(array $overrides = []): array
    {
        return [
            'uid' => 42,
            '_rootPageId' => 1,
            'title' => 'Ein Werkzeug',
            'description' => 'Was es tut.',
            'slug' => '/open-source/ein-werkzeug',
            'canonical_link' => 'https://example.com/open-source/ein-werkzeug',
            '_siteBase' => 'https://example.com/',
            'tx_structuredcontent_code_repository' => 'https://git.example.com/werkzeug',
            ...$overrides,
        ];
    }

    #[Test]
    public function theSoftwareCarriesTheNameDescriptionAndAddressOfThePage(): void
    {
        $document = $this->software()->generate($this->softwarePage(), []);

        self::assertSame('SoftwareApplication', $document->schemaType);
        self::assertSame('Ein Werkzeug', $document->data['name']);
        self::assertSame('Was es tut.', $document->data['description']);
        self::assertSame('https://example.com/open-source/ein-werkzeug', $document->data['url']);
        self::assertSame('https://git.example.com/werkzeug', $document->data['codeRepository']);
    }

    /**
     * Als Quelltext gepflegt wird sie als Quelltext ausgegeben — das ist der
     * Unterschied zwischen einer Anwendung, die man benutzt, und Code, den man
     * liest.
     */
    #[Test]
    public function aPageMaintainedAsSourceCodeIsEmittedAsSourceCode(): void
    {
        $document = $this->software()->generate(
            $this->softwarePage(['tx_structuredcontent_schema_type' => 'SoftwareSourceCode']),
            [],
        );

        self::assertSame('SoftwareSourceCode', $document->schemaType);
    }

    /**
     * Ohne kanonische Adresse wird sie aus Basis und Slug gebaut — eine
     * Software-Angabe ohne Adresse ist eine Anwendung, die man nicht findet.
     */
    #[Test]
    public function withoutACanonicalLinkTheAddressIsBuiltFromBaseAndSlug(): void
    {
        $document = $this->software()->generate($this->softwarePage(['canonical_link' => '']), []);

        self::assertSame('https://example.com/open-source/ein-werkzeug', $document->data['url']);
    }

    /**
     * Die Eigenschaft, um die es geht: die Lizenz wird zur Adresse. Ein
     * Kuerzel ist fuer einen Leser eine Zeichenkette, eine Adresse ist eine
     * Aussage, die er aufloesen kann.
     */
    #[Test]
    public function aLicenceShorthandBecomesAnAddress(): void
    {
        $document = $this->software()->generate(
            $this->softwarePage(['tx_structuredcontent_software_license' => 'MIT']),
            [],
        );

        self::assertStringStartsWith('http', $document->data['license']);
        self::assertStringContainsString('MIT', $document->data['license']);
    }

    /**
     * Eine bereits als Adresse gepflegte Lizenz bleibt, wie sie ist — eine
     * eigene Lizenzseite ist eine bewusste Angabe.
     */
    #[Test]
    public function aLicenceThatIsAlreadyAnAddressIsLeftAlone(): void
    {
        $document = $this->software()->generate(
            $this->softwarePage(['tx_structuredcontent_software_license' => 'https://example.com/lizenz']),
            [],
        );

        self::assertSame('https://example.com/lizenz', $document->data['license']);
    }

    /**
     * Mehrere Programmiersprachen werden zur Liste, eine einzelne bleibt ein
     * Wert — eine Liste mit einem Element sagt etwas anderes als ein Wert.
     */
    #[Test]
    public function theProgrammingLanguagesKeepTheirShape(): void
    {
        $one = $this->software()->generate(
            $this->softwarePage(['tx_structuredcontent_programming_language' => 'PHP']),
            [],
        );
        self::assertSame('PHP', $one->data['programmingLanguage']);

        $several = $this->software()->generate(
            $this->softwarePage(['tx_structuredcontent_programming_language' => 'PHP, TypeScript , ,']),
            [],
        );
        self::assertSame(['PHP', 'TypeScript'], $several->data['programmingLanguage']);
    }

    #[Test]
    public function aVersionIsCarriedWhenThereIsOne(): void
    {
        $document = $this->software()->generate(
            $this->softwarePage(['tx_structuredcontent_software_version' => '2.4.1']),
            [],
        );

        self::assertSame('2.4.1', $document->data['softwareVersion']);
    }

    /**
     * Und was nicht gepflegt ist, steht nicht in der Ausgabe — eine leere
     * Versionsangabe ist eine Version, die es nicht gibt.
     */
    #[Test]
    public function whatIsNotMaintainedIsNotClaimed(): void
    {
        $document = $this->software()->generate(
            $this->softwarePage([
                'tx_structuredcontent_software_version' => '',
                'tx_structuredcontent_software_license' => '',
                'tx_structuredcontent_programming_language' => '',
            ]),
            [],
        );

        self::assertArrayNotHasKey('softwareVersion', $document->data);
        self::assertArrayNotHasKey('license', $document->data);
        self::assertArrayNotHasKey('programmingLanguage', $document->data);
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
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function authorPage(): array
    {
        return [
            'uid' => 50,
            '_rootPageId' => 1,
            'title' => 'Ada Lovelace',
            'slug' => '/autor',
            '_siteBase' => 'https://example.com/',
            'sys_language_uid' => 0,
            '_routingArguments' => ['tx_contentblockscollection_person' => ['person' => 'ada-lovelace']],
        ];
    }

    #[Test]
    public function anAuthorPageWithAPersonIsHandled(): void
    {
        $this->rows = [$this->personRow()];

        self::assertTrue($this->profile()->canHandle($this->authorPage(), []));
    }

    /**
     * Die Eigenschaft, um die es geht: die Seite verweist auf die Person,
     * statt sie ein zweites Mal auszuschreiben. Sonst haette derselbe Autor
     * zwei Beschreibungen, die auseinanderlaufen koennen.
     */
    #[Test]
    public function theProfilePagePointsAtThePersonRatherThanRepeatingHer(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->profile()->generate($this->authorPage(), []);

        self::assertSame('ProfilePage', $document->schemaType);
        self::assertSame(
            ['@id' => 'https://example.com/autor/ada-lovelace#person'],
            $document->data['mainEntity'] ?? null,
        );
    }

    /**
     * Die Artikel der Person erscheinen als Teile der Seite — das ist, was
     * eine Autorenseite von einer Visitenkarte unterscheidet.
     */
    #[Test]
    public function theArticlesOfThePersonAppearAsPartsOfThePage(): void
    {
        $this->rows = [$this->personRow()];
        $this->articles = [
            ['uid' => 100, 'title' => 'Erster Beitrag', 'slug' => '/blog/erster'],
            ['uid' => 101, 'title' => 'Zweiter Beitrag', 'slug' => '/blog/zweiter'],
        ];

        $document = $this->profile()->generate($this->authorPage(), []);

        self::assertCount(2, $document->data['hasPart']);
        self::assertSame('BlogPosting', $document->data['hasPart'][0]['@type']);
        self::assertSame('Erster Beitrag', $document->data['hasPart'][0]['headline']);
        self::assertSame('https://example.com/blog/erster', $document->data['hasPart'][0]['url']);
    }

    /**
     * Ein Beitrag ohne Titel wird ausgelassen — ein `hasPart` ohne Namen ist
     * ein Teil, ueber den nichts bekannt ist.
     */
    #[Test]
    public function anArticleWithoutATitleIsLeftOut(): void
    {
        $this->rows = [$this->personRow()];
        $this->articles = [
            ['uid' => 100, 'title' => '   ', 'slug' => '/blog/erster'],
            ['uid' => 101, 'title' => 'Zweiter Beitrag', 'slug' => '/blog/zweiter'],
        ];

        $document = $this->profile()->generate($this->authorPage(), []);

        self::assertCount(1, $document->data['hasPart']);
        self::assertSame('Zweiter Beitrag', $document->data['hasPart'][0]['headline']);
    }

    /**
     * Eine Autorenseite ohne Person ergibt ein leeres Dokument — die
     * Middleware wirft es weg, statt eine ProfilePage ueber niemanden
     * auszuliefern.
     */
    #[Test]
    public function anAuthorPageWithoutAPersonProducesAnEmptyDocument(): void
    {
        $document = $this->profile()->generate($this->authorPage(), []);

        self::assertSame([], $document->data);
    }

    /**
     * The English author page as the middleware hands it over: the language
     * base, the site's entity base and the URL the router built.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function englishAuthorPage(array $overrides = []): array
    {
        return [
            ...$this->authorPage(),
            'slug' => '/author',
            'sys_language_uid' => 1,
            '_siteBase' => 'https://example.com/en/',
            '_entityBase' => 'https://example.com/',
            '_language' => 'en-GB',
            '_routeUrl' => 'https://example.com/en/author/ada-lovelace',
            ...$overrides,
        ];
    }

    /**
     * The ProfilePage is the page being rendered: on the English page its
     * URL is the English author URL the router built. Appending the
     * identifier path to the language base gave `/en/autor/<slug>`, a 404.
     */
    #[Test]
    public function theProfilePageUrlIsTheRoutedUrlOfTheRenderedLanguage(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->profile()->generate($this->englishAuthorPage(), []);

        self::assertSame('https://example.com/en/author/ada-lovelace', $document->data['url']);
        self::assertSame('https://example.com/en/author/ada-lovelace#webpage', $document->data['@id']);
    }

    /**
     * The Person stays one entity per site: its @id is the same on the
     * English profile page as everywhere else, only the page's own URL
     * follows the language.
     */
    #[Test]
    public function thePersonIdStaysLanguageIndependentOnATranslatedProfilePage(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->profile()->generate($this->englishAuthorPage(), []);

        self::assertSame(['@id' => 'https://example.com/autor/ada-lovelace#person'], $document->data['mainEntity']);
    }

    /**
     * Without a routed URL (a caller outside the middleware) the page's own
     * slug in its language stands in, followed by the person's slug.
     */
    #[Test]
    public function withoutARoutedUrlThePageSlugOfTheLanguageStandsIn(): void
    {
        $this->rows = [$this->personRow()];

        $document = $this->profile()->generate($this->englishAuthorPage(['_routeUrl' => '']), []);

        self::assertSame('https://example.com/en/author/ada-lovelace', $document->data['url']);
    }

    /**
     * An article in hasPart carries the date it was published (crdate, as
     * its own BlogPosting node) and the date it was last updated — not the
     * date of its last edit as its publication date.
     */
    #[Test]
    public function anArticleCarriesItsPublicationDateAndItsLastUpdate(): void
    {
        $this->rows = [$this->personRow()];
        $published = 1_700_000_000;
        $edited = 1_760_000_000;
        $updated = 1_750_000_000;
        $this->articles = [
            ['uid' => 100, 'title' => 'Updated post', 'slug' => '/blog/a', 'crdate' => $published, 'tstamp' => $edited, 'lastUpdated' => $updated],
            ['uid' => 101, 'title' => 'Edited post', 'slug' => '/blog/b', 'crdate' => $published, 'tstamp' => $edited, 'lastUpdated' => 0],
        ];

        $hasPart = $this->profile()->generate($this->authorPage(), [])->data['hasPart'];

        self::assertSame(date('c', $published), $hasPart[0]['datePublished']);
        self::assertSame(date('c', $updated), $hasPart[0]['dateModified']);
        self::assertSame(date('c', $published), $hasPart[1]['datePublished']);
        self::assertSame(date('c', $edited), $hasPart[1]['dateModified'], 'without a last-updated date the tstamp stands in');
    }

    /**
     * The ProfilePage claims the page entity of its URL, so the service
     * drops the container page's WebPage and Article next to it.
     */
    #[Test]
    public function theProfilePageClaimsThePageEntity(): void
    {
        self::assertInstanceOf(ClaimsPageEntityInterface::class, $this->profile());
    }
}
