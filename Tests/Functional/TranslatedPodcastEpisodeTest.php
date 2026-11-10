<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Functional;

use Koh\StructuredContent\Infrastructure\Persistence\DoctrineEpisodeGuestsRepository;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PodcastEpisodeSchemaGenerator;
use Koh\StructuredContent\Presentation\Middleware\PageContentCollector;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Guests and transcript of an episode on a translated page come from the
 * episode meta records of the translated podcast plugin.
 *
 * Observed in production: the English episode pages of a podcast whose
 * English plugin carries its own episode meta records (attached to the
 * translation's uid, free mode, `l10n_parent = 0`) listed no guests. The
 * generator looked the meta up by the plugin's `uid` — after the overlay the
 * uid of the default-language plugin — and the page's language, and found
 * nothing.
 *
 * The other podcast's English plugin has no meta records on purpose: there
 * are no English transcripts. Its schema must then carry neither guests nor
 * a transcript, and in particular not the German one.
 */
final class TranslatedPodcastEpisodeTest extends FunctionalTestCase
{
    private const FEED_URL = 'https://example.com/feed.xml';
    private const SLUG = 'folge-12-wie-suchmaschinen-podcasts-lesen';

    protected array $testExtensionsToLoad = [
        'koh/structured-content',
        'typo3conf/ext/structured_content/Tests/Functional/Fixtures/Extensions/podcast_fixture',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/TranslatedPodcastPlugins.csv');
    }

    #[Test]
    public function aTranslatedPluginBringsItsOwnGuestsAndTranscript(): void
    {
        $data = $this->episodeSchema(2, $this->english());

        self::assertSame(['Hanna Host', 'Grace Guest'], $this->actorNames($data));
        self::assertSame('English transcript.', $data['transcript'] ?? null);
    }

    #[Test]
    public function aTranslatedPluginWithoutMetaRecordsClaimsNoGuestsAndNoTranscript(): void
    {
        $data = $this->episodeSchema(4, $this->english());

        self::assertSame(['Hanna Host'], $this->actorNames($data), 'the plugin-level hosts stay');
        self::assertArrayNotHasKey('transcript', $data, 'no fallback to the German transcript');
        self::assertArrayNotHasKey('transcript', $data['associatedMedia'] ?? []);
    }

    #[Test]
    public function theDefaultLanguageReadsTheDefaultLanguageMeta(): void
    {
        $data = $this->episodeSchema(2, new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_ON_WITH_FLOATING, []));

        self::assertSame(['Hanna Host', 'Gerda Gast'], $this->actorNames($data));
        self::assertSame('Deutsches Transkript.', $data['transcript'] ?? null);
    }

    private function english(): LanguageAspect
    {
        return new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON_WITH_FLOATING, []);
    }

    /**
     * @return array<string, mixed>
     */
    private function episodeSchema(int $pageUid, LanguageAspect $language): array
    {
        GeneralUtility::makeInstance(Context::class)->setAspect('language', $language);
        $connectionPool = $this->get(ConnectionPool::class);

        $elements = (new PageContentCollector($connectionPool))->findRenderedContent($pageUid, $language);
        $pageData = [
            'uid' => $pageUid,
            'pid' => 1,
            'title' => 'Podcast',
            'slug' => '/podcast',
            '_siteBase' => 'https://example.com/',
            '_language' => 0 === $language->getId() ? 'de-DE' : 'en-GB',
            'sys_language_uid' => $language->getId(),
            '_routingArguments' => [
                'tx_contentblockscollection_podcast' => ['episode' => self::SLUG],
            ],
        ];

        $generator = new PodcastEpisodeSchemaGenerator(
            $this->feedCache(),
            new PersonSchemaBuilder($connectionPool),
            new DoctrineEpisodeGuestsRepository($connectionPool),
            $connectionPool,
        );
        self::assertTrue($generator->canHandle($pageData, $elements));

        return $generator->generate($pageData, $elements)->data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<mixed>
     */
    private function actorNames(array $data): array
    {
        $actors = $data['actor'] ?? [];
        if (isset($actors['@type'])) {
            $actors = [$actors];
        }

        return array_column($actors, 'name');
    }

    /**
     * The feed cache content-blocks-collection fills, with the one episode.
     */
    private function feedCache(): CacheManager
    {
        $entries = [
            'rssfeed_' . hash('sha256', self::FEED_URL) => [
                'meta' => ['title' => 'Der Beispiel-Podcast'],
                'episodes' => [[
                    'episode' => '12',
                    'title' => 'Wie Suchmaschinen Podcasts lesen',
                    'guid' => 'https://example.com/feed/12',
                    'enclosureUrl' => 'https://example.com/media/12.mp3',
                    'enclosureType' => 'audio/mpeg',
                ]],
            ],
        ];
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('has')->willReturnCallback(static fn(string $key): bool => isset($entries[$key]));
        $cache->method('get')->willReturnCallback(static fn(string $key): mixed => $entries[$key] ?? false);

        $manager = $this->createStub(CacheManager::class);
        $manager->method('getCache')->willReturn($cache);

        return $manager;
    }
}
