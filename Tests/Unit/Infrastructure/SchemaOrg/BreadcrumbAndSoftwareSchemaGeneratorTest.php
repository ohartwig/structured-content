<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\AuthoredPagesRepositoryInterface;
use Koh\StructuredContent\Infrastructure\SchemaOrg\BreadcrumbListSchemaGenerator;
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
 * Der Brotkrumenpfad, die Autorenseite und die Software-Seite.
 *
 * Beim Brotkrumenpfad ist die Reihenfolge alles. TYPO3 liefert die Wurzellinie
 * von der aktuellen Seite nach oben; Schema.org erwartet sie von der Wurzel
 * nach unten, durchnummeriert ab 1. Wird sie nicht gedreht, beschreibt der
 * Pfad die Seitenstruktur genau verkehrt herum — und weil er fuer sich
 * gueltiges JSON-LD bleibt, meldet kein Validator etwas.
 *
 * Ausgelassen werden dabei die Seiten, die kein Ziel sind: Ordner,
 * Verweisseiten, aus der Navigation genommene. Ein Brotkrumen-Eintrag, der
 * auf einen Systemordner zeigt, ist ein Link ins Backend.
 *
 * Bei der Software-Seite geht es wieder um Abgrenzung: sie und die
 * Leistungsseite duerfen nicht dieselbe Seite beanspruchen. Ein gepflegter
 * Typ entscheidet, sonst der Slug unter `/open-source/`, sonst ein
 * hinterlegtes Code-Repository.
 */
final class BreadcrumbAndSoftwareSchemaGeneratorTest extends TestCase
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

    private function breadcrumb(): BreadcrumbListSchemaGenerator
    {
        return new BreadcrumbListSchemaGenerator($this->cacheManager());
    }

    private function software(): SoftwareApplicationSchemaGenerator
    {
        return new SoftwareApplicationSchemaGenerator(new PersonSchemaBuilder($this->connectionPool()));
    }

    private function profile(): ProfilePageSchemaGenerator
    {
        $authored = $this->createStub(AuthoredPagesRepositoryInterface::class);
        $authored->method('findByAuthorUid')->willReturn([]);

        return new ProfilePageSchemaGenerator(new PersonSchemaBuilder($this->connectionPool()), $authored);
    }

    /**
     * Die Wurzellinie, wie TYPO3 sie liefert: von der aktuellen Seite nach
     * oben.
     *
     * @param list<array<string, mixed>> $rootline
     *
     * @return array<string, mixed>
     */
    private function page(array $rootline = [], array $overrides = []): array
    {
        return [
            'uid' => 43,
            '_rootPageId' => 1,
            'title' => 'Beratung',
            'slug' => '/leistungen/beratung',
            '_siteBase' => 'https://example.com/',
            '_rootline' => $rootline === [] ? [
                ['uid' => 43, 'title' => 'Beratung', 'slug' => '/leistungen/beratung', 'doktype' => 1],
                ['uid' => 42, 'title' => 'Leistungen', 'slug' => '/leistungen', 'doktype' => 1],
                ['uid' => 1, 'title' => 'Start', 'slug' => '/', 'doktype' => 1],
            ] : $rootline,
            ...$overrides,
        ];
    }

    #[Test]
    public function aPageWithARootlineGetsABreadcrumb(): void
    {
        self::assertTrue($this->breadcrumb()->canHandle($this->page(), []));
    }

    /**
     * Die Startseite bekommt keinen — ein Pfad mit einem Eintrag, der auf
     * die Seite selbst zeigt, sagt nichts.
     */
    #[Test]
    public function theRootPageGetsNoBreadcrumb(): void
    {
        self::assertFalse($this->breadcrumb()->canHandle($this->page(overrides: ['uid' => 1]), []));
    }

    #[Test]
    public function aPageWithoutARootlineGetsNoBreadcrumb(): void
    {
        $page = $this->page();
        unset($page['_rootline']);

        self::assertFalse($this->breadcrumb()->canHandle($page, []));
    }

    /**
     * Die Eigenschaft, auf die es ankommt: der Pfad laeuft von der Wurzel nach
     * unten und ist ab 1 durchnummeriert. Ungedreht beschriebe er die
     * Seitenstruktur verkehrt herum — und bliebe dabei gueltiges JSON-LD.
     */
    #[Test]
    public function theTrailRunsFromTheRootDownwards(): void
    {
        $document = $this->breadcrumb()->generate($this->page(), []);

        $items = $document->data['itemListElement'];

        self::assertCount(3, $items);
        self::assertSame(['Start', 'Leistungen', 'Beratung'], array_column($items, 'name'));
        self::assertSame([1, 2, 3], array_column($items, 'position'));
    }

    #[Test]
    public function everyStepCarriesItsAbsoluteAddress(): void
    {
        $items = $this->breadcrumb()->generate($this->page(), [])->data['itemListElement'];

        self::assertSame('https://example.com', $items[0]['item'], 'the root is the site base itself');
        self::assertSame('https://example.com/leistungen', $items[1]['item']);
        self::assertSame('https://example.com/leistungen/beratung', $items[2]['item']);
    }

    /**
     * Seiten, die kein Ziel sind, kommen nicht in den Pfad: ein
     * Brotkrumen-Eintrag, der auf einen Systemordner zeigt, ist ein Link ins
     * Backend.
     */
    #[Test]
    public function pagesThatAreNoDestinationAreLeftOut(): void
    {
        $document = $this->breadcrumb()->generate($this->page([
            ['uid' => 43, 'title' => 'Beratung', 'slug' => '/leistungen/beratung', 'doktype' => 1],
            ['uid' => 90, 'title' => 'Systemordner', 'slug' => '/ordner', 'doktype' => 254],
            ['uid' => 91, 'title' => 'Aus der Navigation', 'slug' => '/versteckt', 'doktype' => 1, 'nav_hide' => 1],
            ['uid' => 92, 'title' => '', 'slug' => '/ohne-titel', 'doktype' => 1],
            ['uid' => 1, 'title' => 'Start', 'slug' => '/', 'doktype' => 1],
        ]), []);

        self::assertSame(['Start', 'Beratung'], array_column($document->data['itemListElement'], 'name'));
        self::assertSame([1, 2], array_column($document->data['itemListElement'], 'position'), 'and the numbering closes up');
    }

    /**
     * Der Navigationstitel gewinnt: er ist die kuerzere, fuer die Navigation
     * gewaehlte Bezeichnung, und ein Brotkrumenpfad ist Navigation.
     */
    #[Test]
    public function theNavigationTitleWinsInTheTrail(): void
    {
        $document = $this->breadcrumb()->generate($this->page([
            ['uid' => 43, 'title' => 'Beratung und Begleitung', 'nav_title' => 'Beratung', 'slug' => '/beratung', 'doktype' => 1],
        ]), []);

        self::assertSame('Beratung', $document->data['itemListElement'][0]['name']);
    }

    #[Test]
    public function aPageMaintainedAsSoftwareIsHandled(): void
    {
        foreach (['SoftwareApplication', 'SoftwareSourceCode'] as $type) {
            self::assertTrue(
                $this->software()->canHandle($this->page(overrides: ['tx_structuredcontent_schema_type' => $type]), []),
                $type,
            );
        }
    }

    /**
     * Und ohne gepflegten Typ entscheiden Slug oder Repository — das ist die
     * Gegenseite der Ausschluesse in der Leistungsseiten-Erkennung.
     */
    #[Test]
    public function theSlugOrARepositoryIsEnough(): void
    {
        foreach ([
            'a page below /open-source/' => ['slug' => '/open-source/ein-werkzeug'],
            'a page below /opensource/' => ['slug' => '/opensource/ein-werkzeug'],
            'a page with a repository' => ['tx_structuredcontent_code_repository' => 'https://git.example.com/tool'],
        ] as $why => $override) {
            self::assertTrue($this->software()->canHandle($this->page(overrides: $override), []), $why);
        }
    }

    /**
     * Ein anderer gepflegter Typ schliesst die Software-Seite aus — sonst
     * beanspruchen zwei Generatoren dieselbe Seite.
     */
    #[Test]
    public function anotherMaintainedTypeExcludesIt(): void
    {
        foreach (['Article', 'Service', 'Product'] as $type) {
            self::assertFalse(
                $this->software()->canHandle(
                    $this->page(overrides: ['tx_structuredcontent_schema_type' => $type, 'slug' => '/open-source/x']),
                    [],
                ),
                $type,
            );
        }
    }

    #[Test]
    public function theRootPageIsNeverASoftwarePage(): void
    {
        self::assertFalse(
            $this->software()->canHandle(
                $this->page(overrides: ['uid' => 1, 'tx_structuredcontent_schema_type' => 'SoftwareApplication']),
                [],
            ),
        );
    }

    #[Test]
    public function anOrdinaryPageIsNoSoftwarePage(): void
    {
        self::assertFalse($this->software()->canHandle($this->page(overrides: ['slug' => '/ueber-uns']), []));
    }

    /**
     * Die Autorenseite braucht eine Person, die es gibt. Ohne sie waere die
     * Seite eine `ProfilePage` ueber niemanden.
     */
    #[Test]
    public function aProfilePageWithoutAPersonIsNotHandled(): void
    {
        self::assertFalse($this->profile()->canHandle($this->page(overrides: ['slug' => '/autor/ada-lovelace']), []));
        self::assertFalse($this->profile()->canHandle($this->page(), []));
    }
}
