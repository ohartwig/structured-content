<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PodcastSeriesSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    private const PODCAST_CTYPE = 'moselwal_videopodcastfeed';
    private const PODCAST_PLUGIN_CTYPE = 'contentblockscollection_podcast';
    private const FEED_URL_FIELD = 'moselwal_videopodcastfeed_feed_url';
    private const SAME_AS_FIELD = 'moselwal_videopodcastfeed_same_as';
    private const FEED_CACHE_NAME = 'content_blocks_collection_rssfeed';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly CacheManager $cacheManager,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        // Only generate Series schema on the list view, not on episode detail pages
        $routingArguments = $pageData['_routingArguments'] ?? [];
        if (is_array($routingArguments) && isset($routingArguments['tx_contentblockscollection_podcast']['episode'])) {
            return false;
        }

        foreach ($contentElements as $element) {
            $ctype = $element['CType'] ?? '';
            if (self::PODCAST_CTYPE === $ctype || self::PODCAST_PLUGIN_CTYPE === $ctype) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $pageUid = (int) ($pageData['uid'] ?? 0);
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);
        $slug = (string) ($pageData['slug'] ?? '');
        $pageUrl = '/' === $slug || '' === $slug
            ? $siteBase
            : rtrim($siteBase, '/') . '/' . ltrim($slug, '/');

        $podcastRecord = $this->fetchPodcastRecord($pageUid);
        $feedUrl = $podcastRecord['feedUrl'];
        $sameAsRaw = $podcastRecord['sameAs'];
        $detailPid = $podcastRecord['detailPid'];
        $feedData = '' !== $feedUrl ? $this->readCachedFeed($feedUrl) : null;

        $language = (string) ($pageData['_language'] ?? '');

        $data = [
            'name' => $this->sanitizeText($pageData['title'] ?? ''),
            'url' => $pageUrl,
        ];

        if (isset($pageData['description']) && '' !== $pageData['description']) {
            $data['description'] = $this->sanitizeText((string) $pageData['description']);
        }

        if ('' !== $language) {
            $data['inLanguage'] = $language;
        }

        $sameAs = $this->parseSameAs($sameAsRaw);
        if ([] !== $sameAs) {
            $data['sameAs'] = $sameAs;
        }

        $data['publisher'] = ['@id' => $entityBase . '#organization'];

        if (null !== $feedData) {
            $meta = $feedData['meta'];

            if (($meta['title'] ?? '') !== '') {
                $data['name'] = $this->sanitizeText((string) $meta['title']);
            }

            if (($meta['description'] ?? '') !== '' && !isset($data['description'])) {
                $data['description'] = $this->sanitizeText((string) $meta['description']);
            }

            if (($meta['image'] ?? '') !== '') {
                $data['image'] = (string) $meta['image'];
            }

            if (($meta['author'] ?? '') !== '') {
                $data['author'] = [
                    '@type' => 'Person',
                    'name' => $this->sanitizeText((string) $meta['author']),
                ];
            }

            $data['webFeed'] = $feedUrl;

            $episodes = $feedData['episodes'];
            if ([] !== $episodes) {
                $episodeBaseUrl = $pageUrl;
                if ($detailPid > 0) {
                    $detailSlug = $this->fetchPageSlug($detailPid);
                    if ('' !== $detailSlug) {
                        $episodeBaseUrl = rtrim($siteBase, '/') . $detailSlug;
                    }
                }
                $seriesImage = (string) ($meta['image'] ?? '');
                $data['hasPart'] = $this->buildEpisodeReferences($episodes, $episodeBaseUrl, $seriesImage);
            }
        }

        return new JsonLdDocument('PodcastSeries', array_filter($data, SchemaValues::present(...)));
    }

    public function getPriority(): int
    {
        return 65;
    }

    /**
     * @return array{feedUrl: string, sameAs: string, detailPid: int}
     */
    private function fetchPodcastRecord(int $pageUid): array
    {
        if (0 === $pageUid) {
            return ['feedUrl' => '', 'sameAs' => '', 'detailPid' => 0];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $row = $queryBuilder
            ->select(self::FEED_URL_FIELD, self::SAME_AS_FIELD, 'pages')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->eq(
                        'CType',
                        $queryBuilder->createNamedParameter(self::PODCAST_CTYPE),
                    ),
                    $queryBuilder->expr()->eq(
                        'CType',
                        $queryBuilder->createNamedParameter(self::PODCAST_PLUGIN_CTYPE),
                    ),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row) {
            return ['feedUrl' => '', 'sameAs' => '', 'detailPid' => 0];
        }

        return [
            'feedUrl' => (string) ($row[self::FEED_URL_FIELD] ?? ''),
            'sameAs' => (string) ($row[self::SAME_AS_FIELD] ?? ''),
            'detailPid' => (int) ($row['pages'] ?? 0),
        ];
    }

    /**
     * @return list<string>
     */
    private function parseSameAs(string $raw): array
    {
        if ('' === $raw) {
            return [];
        }

        $urls = [];
        $lines = preg_split('/[\r\n]+/', $raw);
        if (false === $lines) {
            return [];
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ('' !== $line && false !== filter_var($line, FILTER_VALIDATE_URL)) {
                $urls[] = $line;
            }
        }

        return $urls;
    }

    /**
     * @return array{meta: array<string, mixed>, episodes: array<int, mixed>}|null
     */
    private function readCachedFeed(string $feedUrl): ?array
    {
        try {
            $cache = $this->cacheManager->getCache(self::FEED_CACHE_NAME);
            // sha256 muss mit PodcastFeedService::cacheIdentifierFor() matchen.
            $cacheIdentifier = 'rssfeed_' . hash('sha256', $feedUrl);

            if (!$cache->has($cacheIdentifier)) {
                return null;
            }

            $data = $cache->get($cacheIdentifier);

            if (!is_array($data) || !is_array($data['meta'] ?? null) || !is_array($data['episodes'] ?? null)) {
                return null;
            }

            /** @var array{meta: array<string, mixed>, episodes: array<int, mixed>} $data */
            return $data;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchPageSlug(int $pageUid): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $slug = $queryBuilder
            ->select('slug')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($slug) ? $slug : '';
    }

    /**
     * Build lightweight @id references for the series hasPart.
     * Full episode schemas are generated on the detail pages.
     *
     * @param array<int, mixed> $episodes as they came out of the feed cache
     *
     * @return list<array<string, mixed>>
     */
    private function buildEpisodeReferences(array $episodes, string $pageUrl, string $seriesImage = ''): array
    {
        $refs = [];

        foreach ($episodes as $episode) {
            $slug = $this->generateSlug($episode);
            $episodeUrl = rtrim($pageUrl, '/') . '/' . $slug;
            $imageUrl = (string) ($episode['localImage'] ?? $episode['imageUrl'] ?? '');
            if ('' === $imageUrl) {
                $imageUrl = $seriesImage;
            }

            $ref = [
                '@type' => 'PodcastEpisode',
                '@id' => $episodeUrl,
                'name' => $this->sanitizeText((string) ($episode['title'] ?? '')),
                'url' => $episodeUrl,
            ];

            if ('' !== $imageUrl) {
                $ref['thumbnailUrl'] = $imageUrl;
            }

            $refs[] = $ref;
        }

        return $refs;
    }

    /** @param array<string, mixed> $episode */
    private function generateSlug(array $episode): string
    {
        $episodeNumber = (string) ($episode['episode'] ?? '');
        $title = (string) ($episode['title'] ?? '');

        if ('' !== $episodeNumber) {
            $raw = 'folge-' . $episodeNumber . '-' . $title;
        } elseif ('' !== $title) {
            $guid = (string) ($episode['guid'] ?? '');
            $prefix = '' !== $guid ? substr(md5($guid), 0, 8) : 'ep';
            $raw = $prefix . '-' . $title;
        } else {
            return substr(md5((string) ($episode['guid'] ?? '')), 0, 16);
        }

        $text = mb_strtolower($raw);
        $text = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'é', 'è', 'ê', 'à', 'â'],
            ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'e', 'a', 'a'],
            $text,
        );
        $text = (string) preg_replace('/[^a-z0-9\-]/', '-', $text);
        $text = (string) preg_replace('/-{2,}/', '-', $text);

        return trim($text, '-');
    }

    /**
     * Build full episode schema (used by PodcastEpisodeSchemaGenerator for detail pages).
     *
     * @param list<array<string, mixed>> $episodes
     *
     * @return list<array<string, mixed>>
     */
    public function buildEpisodeSchemas(array $episodes, string $language, string $siteBase, string $seriesImage = ''): array
    {
        $items = [];

        foreach ($episodes as $episode) {
            $item = [
                '@type' => 'PodcastEpisode',
                'name' => $this->sanitizeText((string) ($episode['title'] ?? '')),
            ];

            if ('' !== $language) {
                $item['inLanguage'] = $language;
            }

            $item['publisher'] = ['@id' => $siteBase . '#organization'];

            $description = (string) ($episode['description'] ?? '');
            if ('' !== $description) {
                $item['description'] = $this->sanitizeText($description);
            }

            $pubDate = (string) ($episode['pubDate'] ?? '');
            if ('' !== $pubDate) {
                try {
                    $item['datePublished'] = new \DateTimeImmutable($pubDate)->format('c');
                } catch (\Exception) {
                    // Skip invalid dates
                }
            }

            $duration = (int) ($episode['duration'] ?? 0);
            if ($duration > 0) {
                $item['timeRequired'] = $this->formatIso8601Duration($duration);
            }

            $episodeNumber = (string) ($episode['episode'] ?? '');
            if ('' !== $episodeNumber) {
                $item['episodeNumber'] = (int) $episodeNumber;
            }

            $season = (string) ($episode['season'] ?? '');
            if ('' !== $season) {
                $item['partOfSeason'] = [
                    '@type' => 'PodcastSeason',
                    'seasonNumber' => (int) $season,
                ];
            }

            $link = (string) ($episode['link'] ?? '');
            if ('' !== $link) {
                $item['url'] = $link;
            }

            $imageUrl = (string) ($episode['localImage'] ?? $episode['imageUrl'] ?? '');
            if ('' === $imageUrl) {
                // Falls die Episode kein eigenes Bild hat, erbt sie das der
                // Serie. Ist auch das leer, bleibt der Schluessel weg.
                $imageUrl = $seriesImage;
            }
            if ('' !== $imageUrl) {
                $item['thumbnailUrl'] = $imageUrl;
            }

            $enclosureUrl = (string) ($episode['enclosureUrl'] ?? '');
            if ('' !== $enclosureUrl) {
                $isVideo = (bool) ($episode['isVideo'] ?? false);
                $mediaObject = [
                    '@type' => $isVideo ? 'VideoObject' : 'AudioObject',
                    'contentUrl' => $enclosureUrl,
                    'name' => $item['name'],
                ];

                if (isset($item['description']) && '' !== $item['description']) {
                    $mediaObject['description'] = $item['description'];
                }
                if (isset($item['thumbnailUrl'])) {
                    $mediaObject['thumbnailUrl'] = $item['thumbnailUrl'];
                }
                if (isset($item['datePublished'])) {
                    $mediaObject['uploadDate'] = $item['datePublished'];
                }

                $enclosureType = (string) ($episode['enclosureType'] ?? '');
                if ('' !== $enclosureType) {
                    $mediaObject['encodingFormat'] = $enclosureType;
                }

                $item['associatedMedia'] = $mediaObject;
            }

            $items[] = $item;
        }

        return $items;
    }

    private function formatIso8601Duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        $duration = 'PT';
        if ($h > 0) {
            $duration .= $h . 'H';
        }
        if ($m > 0) {
            $duration .= $m . 'M';
        }
        if ($s > 0 || 'PT' === $duration) {
            $duration .= $s . 'S';
        }

        return $duration;
    }
}
