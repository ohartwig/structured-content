<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PodcastSeriesSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Podcast-Serie, wie Suchmaschinen und Podcast-Verzeichnisse sie lesen.
 *
 * Das Besondere an dieser Klasse ist, woher ihre Daten kommen: nicht aus der
 * eigenen Datenbank, sondern aus einem Cache-Eintrag, den eine andere
 * Extension schreibt. Die Kopplung besteht aus einem abgesprochenen
 * Schluessel — und sie ist schon einmal gebrochen, als dort von md5 auf
 * sha256 umgestellt wurde und hier md5 stehen blieb. Der Leser fand nie
 * Daten, `canHandle()` sagte nein, und das JSON-LD verschwand vollstaendig.
 * Kein Fehler, kein Log, nur eine Seite ohne Schema.
 *
 * Genau das ist deshalb die erste Eigenschaft hier: der Schluessel wird
 * gebildet wie drueben.
 *
 * Die zweite ist die Trennung von Uebersicht und Detailseite. Auf einer
 * Episodenseite darf keine Serie ausgegeben werden — dort ist die Episode das
 * Thema, und zwei konkurrierende Haupt-Entitaeten auf einer Seite sind fuer
 * jeden Leser eine Seite ohne klares Thema.
 */
final class PodcastSeriesSchemaGeneratorTest extends TestCase
{
    use PodcastFeedDoubles;

    private const FEED_URL = 'https://example.com/feed.xml';

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

    private function generator(): PodcastSeriesSchemaGenerator
    {
        return new PodcastSeriesSchemaGenerator($this->connectionPool(), $this->cacheManager());
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
            'title' => 'Der Beispiel-Podcast',
            'description' => 'Jeden Monat eine Folge.',
            'slug' => '/podcast',
            '_siteBase' => 'https://example.com/',
            '_language' => 'de-DE',
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function podcastElement(string $feedUrl = self::FEED_URL): array
    {
        return [[
            'uid' => 1,
            'CType' => 'moselwal_videopodcastfeed',
            'moselwal_videopodcastfeed_feed_url' => $feedUrl,
        ]];
    }

    /**
     * Das Content-Element auf der Seite entscheidet, ob es hier ueberhaupt
     * einen Podcast gibt.
     */
    #[Test]
    public function aPageWithAPodcastElementIsHandled(): void
    {
        foreach (['moselwal_videopodcastfeed', 'contentblockscollection_podcast'] as $cType) {
            self::assertTrue(
                $this->generator()->canHandle($this->page(), [['CType' => $cType]]),
                $cType,
            );
        }
    }

    #[Test]
    public function aPageWithoutOneIsNot(): void
    {
        self::assertFalse($this->generator()->canHandle($this->page(), []));
        self::assertFalse($this->generator()->canHandle($this->page(), [['CType' => 'text']]));
    }

    /**
     * Die zweite Eigenschaft: auf einer Episodenseite entsteht keine Serie.
     * Dort ist die Episode das Thema, und zwei konkurrierende Haupt-Entitaeten
     * auf einer Seite sind eine Seite ohne klares Thema.
     */
    #[Test]
    public function anEpisodePageDoesNotAlsoBecomeTheSeries(): void
    {
        $episodePage = $this->page([
            '_routingArguments' => ['tx_contentblockscollection_podcast' => ['episode' => 'folge-12-...']],
        ]);

        self::assertFalse($this->generator()->canHandle($episodePage, $this->podcastElement()));
    }

    #[Test]
    public function theSeriesCarriesTheNameUrlAndPublisherOfThePage(): void
    {
        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('PodcastSeries', $document->schemaType);
        self::assertSame('Der Beispiel-Podcast', $document->data['name']);
        self::assertSame('https://example.com/podcast', $document->data['url']);
        self::assertSame('Jeden Monat eine Folge.', $document->data['description']);
        self::assertSame('de-DE', $document->data['inLanguage']);
        self::assertSame(['@id' => 'https://example.com#organization'], $document->data['publisher']);
    }

    /**
     * Die Startseite hat keinen Slug. Ihre Adresse ist die Site-Basis, nicht
     * die Basis mit einem angehaengten Leerstring.
     */
    #[Test]
    public function aPodcastOnTheHomepageUsesTheSiteBaseAsItsUrl(): void
    {
        foreach (['', '/'] as $slug) {
            $document = $this->generator()->generate($this->page(['slug' => $slug]), $this->podcastElement());

            self::assertSame('https://example.com', $document->data['url'], var_export($slug, true));
        }
    }

    /**
     * Die erste Eigenschaft, und die einzige, die diese Extension nicht allein
     * in der Hand hat: der Feed wird unter dem Schluessel gelesen, unter dem
     * die andere Extension ihn schreibt.
     */
    #[Test]
    public function theFeedIsReadUnderTheKeyTheOtherExtensionWritesIt(): void
    {
        $this->rows = [['moselwal_videopodcastfeed_feed_url' => self::FEED_URL, 'pages' => 0]];
        $this->feedInCache(
            self::FEED_URL,
            meta: ['title' => 'Der Beispiel-Podcast (aus dem Feed)', 'description' => 'Was der Feed sagt.'],
            episodes: [$this->episode()],
        );

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame(
            'Der Beispiel-Podcast (aus dem Feed)',
            $document->data['name'],
            'the feed was not found — check the cache identifier against PodcastFeedService',
        );
    }

    /**
     * Was im Feed steht, gewinnt gegen die Seite: der Feed ist die Quelle, die
     * auch jedes Podcast-Verzeichnis liest, und eine Abweichung zwischen
     * beiden ist genau das, was Verzeichnisse als zwei Podcasts sehen.
     */
    #[Test]
    public function whatTheFeedSaysWinsOverThePage(): void
    {
        $this->rows = [['moselwal_videopodcastfeed_feed_url' => self::FEED_URL, 'pages' => 0]];
        $this->feedInCache(self::FEED_URL, meta: [
            'title' => 'Der echte Titel',
            'image' => 'https://example.com/cover.jpg',
            'author' => 'Ada Lovelace',
        ]);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('Der echte Titel', $document->data['name']);
        self::assertSame('https://example.com/cover.jpg', $document->data['image']);
        self::assertSame(['@type' => 'Person', 'name' => 'Ada Lovelace'], $document->data['author']);
        self::assertSame(self::FEED_URL, $document->data['webFeed']);
    }

    /**
     * Ein Feed, der nicht im Cache liegt, ist kein Fehler — er ist der
     * Normalfall beim ersten Aufruf, bevor die andere Extension ihn geholt
     * hat. Dann steht eben das in der Ausgabe, was die Seite weiss.
     */
    #[Test]
    public function aFeedThatIsNotCachedYetLeavesThePageDataStanding(): void
    {
        $this->rows = [['moselwal_videopodcastfeed_feed_url' => self::FEED_URL, 'pages' => 0]];

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('Der Beispiel-Podcast', $document->data['name']);
        self::assertArrayNotHasKey('hasPart', $document->data);
    }

    /**
     * Die Folgen kommen als Verweise in die Serie — nicht als vollstaendige
     * Episoden. Die vollen Daten stehen auf den Detailseiten; sie hier zu
     * wiederholen, macht aus einer Uebersichtsseite ein Dokument mit zwanzig
     * Haupt-Entitaeten.
     */
    #[Test]
    public function theEpisodesAppearAsReferencesRatherThanInFull(): void
    {
        $this->rows = [['moselwal_videopodcastfeed_feed_url' => self::FEED_URL, 'pages' => 0]];
        $this->feedInCache(self::FEED_URL, meta: ['title' => 'Podcast'], episodes: [
            $this->episode(),
            $this->episode(['episode' => '13', 'title' => 'Zweite Folge']),
        ]);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertCount(2, $document->data['hasPart']);
        foreach ($document->data['hasPart'] as $part) {
            self::assertSame('PodcastEpisode', $part['@type']);
            self::assertArrayHasKey('url', $part);
            self::assertArrayNotHasKey('associatedMedia', $part, 'the full episode belongs on its own page');
        }
    }

    /**
     * Die Folgen-Adressen zeigen auf die Detailseite, wenn eine konfiguriert
     * ist — sonst laufen alle Verweise auf die Uebersicht und keine Folge ist
     * einzeln adressierbar.
     */
    #[Test]
    public function theEpisodeLinksPointAtTheDetailPageWhenThereIsOne(): void
    {
        $this->rows = [[
            'moselwal_videopodcastfeed_feed_url' => self::FEED_URL,
            'pages' => 99,
            'slug' => '/podcast/folgen',
        ]];
        $this->feedInCache(self::FEED_URL, meta: ['title' => 'Podcast'], episodes: [$this->episode()]);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertStringContainsString('https://example.com', $document->data['hasPart'][0]['url']);
    }

    /**
     * Die sameAs-Adressen sind die Profile in den Verzeichnissen — Spotify,
     * Apple, YouTube. Sie sind das, woran ein Verzeichnis diesen Podcast als
     * denselben erkennt, den es schon kennt.
     */
    #[Test]
    public function theDirectoryProfilesAreCarriedAsSameAs(): void
    {
        $this->rows = [[
            'moselwal_videopodcastfeed_feed_url' => self::FEED_URL,
            'moselwal_videopodcastfeed_same_as' => "https://open.spotify.com/show/abc\nhttps://podcasts.apple.com/de/podcast/id123",
            'pages' => 0,
        ]];

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame(
            ['https://open.spotify.com/show/abc', 'https://podcasts.apple.com/de/podcast/id123'],
            $document->data['sameAs'],
        );
    }

    /**
     * Und was keine Adresse ist, kommt nicht hinein. Ein `sameAs`, das
     * nirgends hinfuehrt, ist eine falsche Identitaetsangabe.
     */
    #[Test]
    public function anythingThatIsNotAUrlIsNotASameAs(): void
    {
        $this->rows = [[
            'moselwal_videopodcastfeed_feed_url' => self::FEED_URL,
            'moselwal_videopodcastfeed_same_as' => "spotify\n\n   \nhttps://open.spotify.com/show/abc",
            'pages' => 0,
        ]];

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame(['https://open.spotify.com/show/abc'], $document->data['sameAs']);
    }

    /**
     * Ohne Podcast-Datensatz auf der Seite bleibt die Ausgabe bei dem, was die
     * Seite selbst hergibt — und faellt nicht aus.
     */
    #[Test]
    public function aPageWhoseRecordIsGoneStillProducesASeries(): void
    {
        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('PodcastSeries', $document->schemaType);
        self::assertSame('Der Beispiel-Podcast', $document->data['name']);
        self::assertArrayNotHasKey('webFeed', $document->data);
    }

    #[Test]
    public function theSeriesOutranksTheGenericFallback(): void
    {
        self::assertGreaterThan(50, $this->generator()->getPriority());
    }
}
