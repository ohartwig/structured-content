<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\EpisodeGuestsRepositoryInterface;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PodcastEpisodeSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die einzelne Folge — das Dokument, mit dem eine Detailseite in
 * Podcast-Verzeichnissen und in Suchergebnissen auftaucht.
 *
 * Zwei Dinge tragen es.
 *
 * Die Folge wird ueber ihren Slug im Feed gesucht, und der Slug wird hier
 * berechnet — aus Foldennummer und Titel. Er ist zugleich das, was in der URL
 * steht. Aendert sich die Berechnung, findet `canHandle()` die Folge nicht
 * mehr, und die Detailseite liefert schlicht kein Schema aus: kein Fehler,
 * kein Log, nur eine Seite, die aus jedem Verzeichnis verschwindet.
 *
 * Und die Folge gehoert zu ihrer Serie. `partOfSeries` mit der Adresse der
 * Uebersichtsseite ist das, woran ein Verzeichnis zwanzig Detailseiten als
 * einen Podcast erkennt statt als zwanzig einzelne Aufnahmen.
 */
final class PodcastEpisodeSchemaGeneratorTest extends TestCase
{
    use PodcastFeedDoubles;

    private const FEED_URL = 'https://example.com/feed.xml';

    private string $transcript = '';

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

    private function generator(): PodcastEpisodeSchemaGenerator
    {
        $guests = $this->createStub(EpisodeGuestsRepositoryInterface::class);
        $guests->method('findTranscriptByPluginAndSlug')->willReturnCallback(fn (): string => $this->transcript);
        $guests->method('findGuestUidsByPluginAndSlug')->willReturn([]);

        return new PodcastEpisodeSchemaGenerator(
            $this->cacheManager(),
            // Der Person-Builder ist final und wird echt gebaut, auf demselben
            // gedoubelten Verbindungspool.
            new PersonSchemaBuilder($this->connectionPool()),
            $guests,
            $this->connectionPool(),
        );
    }

    /**
     * Der Slug, unter dem eine Folge in der URL und im Feed gefunden wird.
     * Er wird hier nachgebaut, weil die Detailseite ihn als Routing-Argument
     * mitbringt — und weil der Test damit genau die Berechnung festhaelt, von
     * der das Wiederfinden abhaengt.
     */
    private function slugOf(string $episodeNumber, string $title): string
    {
        $text = mb_strtolower('folge-' . $episodeNumber . '-' . $title);
        $text = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text);
        $text = (string) preg_replace('/[^a-z0-9\-]/', '-', $text);

        return trim((string) preg_replace('/-{2,}/', '-', $text), '-');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function page(array $overrides = []): array
    {
        return [
            'uid' => 43,
            'pid' => 42,
            'title' => 'Podcast',
            'slug' => '/podcast',
            '_siteBase' => 'https://example.com/',
            '_language' => 'de-DE',
            'sys_language_uid' => 0,
            '_routingArguments' => [
                'tx_contentblockscollection_podcast' => [
                    'episode' => $this->slugOf('12', 'Wie Suchmaschinen Podcasts lesen'),
                ],
            ],
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function podcastElement(): array
    {
        return [[
            'uid' => 1,
            'CType' => 'contentblockscollection_podcast',
            'moselwal_videopodcastfeed_feed_url' => self::FEED_URL,
        ]];
    }

    /**
     * @param array<string, mixed> $episodeOverrides
     * @param array<string, mixed> $meta
     */
    private function feedWithTheEpisode(array $episodeOverrides = [], array $meta = ['title' => 'Der Beispiel-Podcast']): void
    {
        $this->feedInCache(self::FEED_URL, meta: $meta, episodes: [$this->episode($episodeOverrides)]);
    }

    /**
     * Die erste Eigenschaft: eine Folge wird ueber ihren Slug gefunden. Der
     * Slug in der URL und der aus dem Feed berechnete muessen derselbe sein —
     * sonst liefert die Detailseite kein Schema, ohne dass irgendetwas nach
     * einem Fehler aussieht.
     */
    #[Test]
    public function anEpisodeIsFoundByTheSlugThatIsAlsoInItsUrl(): void
    {
        $this->feedWithTheEpisode();

        self::assertTrue($this->generator()->canHandle($this->page(), $this->podcastElement()));
    }

    #[Test]
    public function aSlugThatMatchesNoEpisodeIsNotHandled(): void
    {
        $this->feedWithTheEpisode();

        $page = $this->page([
            '_routingArguments' => ['tx_contentblockscollection_podcast' => ['episode' => 'folge-999-gibt-es-nicht']],
        ]);

        self::assertFalse($this->generator()->canHandle($page, $this->podcastElement()));
    }

    /**
     * Ohne Folge in der Adresse ist es die Uebersichtsseite, nicht die
     * Detailseite — dort gehoert die Serie hin, nicht eine einzelne Folge.
     */
    #[Test]
    public function anOverviewPageIsNotAnEpisodePage(): void
    {
        $this->feedWithTheEpisode();

        self::assertFalse($this->generator()->canHandle($this->page(['_routingArguments' => []]), $this->podcastElement()));
    }

    /**
     * Ohne Feed gibt es nichts nachzuschlagen. Das ist der Zustand vor dem
     * ersten Abruf durch die andere Extension.
     */
    #[Test]
    public function withoutAFeedThereIsNothingToLookUp(): void
    {
        self::assertFalse($this->generator()->canHandle($this->page(), $this->podcastElement()));
        self::assertFalse($this->generator()->canHandle($this->page(), []));
    }

    #[Test]
    public function theEpisodeCarriesItsOwnNameUrlAndPage(): void
    {
        $this->feedWithTheEpisode();

        $document = $this->generator()->generate($this->page(), $this->podcastElement());
        $slug = $this->slugOf('12', 'Wie Suchmaschinen Podcasts lesen');

        self::assertSame('PodcastEpisode', $document->schemaType);
        self::assertSame('Wie Suchmaschinen Podcasts lesen', $document->data['name']);
        self::assertSame('https://example.com/podcast/' . $slug, $document->data['url']);
        self::assertSame(
            ['@id' => 'https://example.com/podcast/' . $slug . '#webpage'],
            $document->data['mainEntityOfPage'],
        );
        self::assertSame('de-DE', $document->data['inLanguage']);
        self::assertSame(['@id' => 'https://example.com#organization'], $document->data['publisher']);
    }

    /**
     * Die zweite Eigenschaft: die Folge gehoert zu ihrer Serie. Das ist, woran
     * ein Verzeichnis zwanzig Detailseiten als einen Podcast erkennt.
     */
    #[Test]
    public function theEpisodeSaysWhichSeriesItBelongsTo(): void
    {
        $this->feedWithTheEpisode(meta: ['title' => 'Der Beispiel-Podcast']);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame(
            ['@type' => 'PodcastSeries', 'name' => 'Der Beispiel-Podcast', 'url' => 'https://example.com/podcast'],
            $document->data['partOfSeries'],
        );
    }

    /**
     * Die Foldennummer wird als Zahl ausgegeben. Als Zeichenkette ist sie fuer
     * einen Leser keine Reihenfolge, sondern ein Name.
     */
    #[Test]
    public function theEpisodeNumberIsANumber(): void
    {
        $this->feedWithTheEpisode();

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame(12, $document->data['episodeNumber']);
    }

    /**
     * Eine Staffel wird als eigene Entitaet ausgegeben, wenn es eine gibt —
     * und weggelassen, wenn nicht. Ein `partOfSeason` ohne Nummer ist eine
     * Staffel, ueber die nichts bekannt ist.
     */
    #[Test]
    public function aSeasonAppearsOnlyWhenThereIsOne(): void
    {
        $this->feedWithTheEpisode(['season' => '2']);
        $withSeason = $this->generator()->generate($this->page(), $this->podcastElement());
        self::assertSame(['@type' => 'PodcastSeason', 'seasonNumber' => 2], $withSeason->data['partOfSeason']);

        $this->cacheEntries = [];
        $this->feedWithTheEpisode();
        $withoutSeason = $this->generator()->generate($this->page(), $this->podcastElement());
        self::assertArrayNotHasKey('partOfSeason', $withoutSeason->data);
    }

    /**
     * Eine Folge ohne eigenes Bild erbt das der Serie — eine Detailseite ganz
     * ohne Bild faellt in jedem Verzeichnis hinter die mit einem zurueck.
     */
    #[Test]
    public function anEpisodeWithoutItsOwnImageInheritsTheSeriesCover(): void
    {
        $this->feedWithTheEpisode(
            ['imageUrl' => ''],
            ['title' => 'Podcast', 'image' => 'https://example.com/cover.jpg'],
        );

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('https://example.com/cover.jpg', $document->data['thumbnailUrl']);
    }

    /**
     * Ein eigenes Bild gewinnt.
     */
    #[Test]
    public function anEpisodeWithItsOwnImageKeepsIt(): void
    {
        $this->feedWithTheEpisode(
            ['imageUrl' => 'https://example.com/media/12.jpg'],
            ['title' => 'Podcast', 'image' => 'https://example.com/cover.jpg'],
        );

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('https://example.com/media/12.jpg', $document->data['thumbnailUrl']);
    }

    /**
     * Ein relativer Bildpfad wird absolut gemacht. Relativ ist er fuer jeden
     * Leser ausserhalb dieser Seite nicht aufloesbar.
     */
    #[Test]
    public function aRelativeImagePathIsMadeAbsolute(): void
    {
        $this->feedWithTheEpisode(['imageUrl' => '/fileadmin/cover.jpg']);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('https://example.com/fileadmin/cover.jpg', $document->data['thumbnailUrl']);
    }

    /**
     * Ein Veroeffentlichungsdatum wird in die Form gebracht, die Schema.org
     * verlangt — und ein unlesbares laesst die Folge stehen, statt sie
     * mitzureissen.
     */
    #[Test]
    public function thePublicationDateIsNormalisedAndAnUnreadableOneIsDropped(): void
    {
        $this->feedWithTheEpisode(['pubDate' => 'Wed, 01 Jul 2026 09:00:00 +0200']);
        $document = $this->generator()->generate($this->page(), $this->podcastElement());
        self::assertStringStartsWith('2026-07-01T09:00:00', $document->data['datePublished']);

        $this->cacheEntries = [];
        $this->feedWithTheEpisode(['pubDate' => 'irgendwann letzten Sommer']);
        $document = $this->generator()->generate($this->page(), $this->podcastElement());
        self::assertArrayNotHasKey('datePublished', $document->data);
    }

    /**
     * Eine Laufzeit wird als ISO-8601-Dauer ausgegeben — Sekunden allein sind
     * fuer einen Leser keine Zeitangabe.
     */
    #[Test]
    public function theRunningTimeIsAnIsoDuration(): void
    {
        $this->feedWithTheEpisode(['duration' => 2520]);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('PT42M', $document->data['timeRequired']);
    }

    #[Test]
    public function anEpisodeWithoutARunningTimeSaysNothingAboutIt(): void
    {
        $this->feedWithTheEpisode(['duration' => 0]);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertArrayNotHasKey('timeRequired', $document->data);
    }

    /**
     * Ein Transkript ist der Text, den eine Suchmaschine tatsaechlich lesen
     * kann — bei einer Audiodatei das Einzige. Es wird uebernommen, wenn es
     * eines gibt.
     */
    #[Test]
    public function aTranscriptIsCarriedWhenThereIsOne(): void
    {
        $this->rows = [['uid' => 1]];
        $this->transcript = 'Willkommen zu Folge zwoelf.';
        $this->feedWithTheEpisode();

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('Willkommen zu Folge zwoelf.', $document->data['transcript']);
    }

    #[Test]
    public function anEpisodeWithoutATranscriptDoesNotClaimOne(): void
    {
        $this->feedWithTheEpisode();

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertArrayNotHasKey('transcript', $document->data);
    }

    /**
     * Eine Videofolge ist beides — Folge und Videoobjekt. Das ist der
     * Unterschied zwischen einem Treffer in der Video-Suche und keinem.
     */
    #[Test]
    public function aVideoEpisodeIsBothAnEpisodeAndAVideo(): void
    {
        $this->feedWithTheEpisode(['isVideo' => true, 'enclosureUrl' => 'https://example.com/media/12.mp4']);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame(['PodcastEpisode', 'VideoObject'], $document->data['@type']);
    }

    /**
     * Aber nur, wenn es die Datei auch gibt: ein Video-Kennzeichen ohne
     * Mediendatei ist eine Behauptung ueber ein Video, das niemand abspielen
     * kann.
     */
    #[Test]
    public function aVideoFlagWithoutAMediaFileIsNotAVideo(): void
    {
        $this->feedWithTheEpisode(['isVideo' => true, 'enclosureUrl' => '']);

        $document = $this->generator()->generate($this->page(), $this->podcastElement());

        self::assertSame('PodcastEpisode', $document->data['@type']);
    }

    #[Test]
    public function theEpisodeOutranksTheGenericFallback(): void
    {
        self::assertGreaterThan(50, $this->generator()->getPriority());
    }
}
