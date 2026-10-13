<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Shared lookup helpers for the podcast generators.
 *
 * Reads the cached RSS-feed payload that the moselwal/content-blocks-collection
 * podcast plugin populates (cache identifier `rssfeed_<sha256(feedUrl)>`) and
 * resolves an episode by its URL slug. The slug-generation logic mirrors
 * `PodcastFeedService::generateSlug()` exactly — both must agree on the slug
 * format or the schema generators will fail to find the episode payload that
 * the plugin renders on the same page.
 *
 * Consumers must implement getCacheManager() and return their own
 * CacheManager dependency. Avoiding a raw $this->cacheManager property
 * access here keeps PHPStan honest: the trait can't see traits/properties
 * defined on the consumer.
 */
trait PodcastFeedCacheReaderTrait
{
    abstract private function getCacheManager(): CacheManager;

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    private function resolveEpisodeSlug(array $pageData, array $contentElements): string
    {
        $routingArguments = $pageData['_routingArguments'] ?? [];
        if (is_array($routingArguments)) {
            $slug = (string) ($routingArguments['tx_contentblockscollection_podcast']['episode'] ?? '');
            if ('' !== $slug) {
                return $slug;
            }
        }

        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') === 'contentblockscollection_podcast'
                && ($element['_episodeSlug'] ?? '') !== '') {
                return (string) $element['_episodeSlug'];
            }
        }

        return '';
    }

    /** @param list<array<string, mixed>> $contentElements */
    private function findFeedUrl(array $contentElements): string
    {
        foreach ($contentElements as $element) {
            $feedUrl = (string) ($element['moselwal_videopodcastfeed_feed_url'] ?? '');
            if ('' !== $feedUrl) {
                return $feedUrl;
            }
        }

        return '';
    }

    /** @return array<string, mixed>|null */
    private function findEpisodeBySlug(string $feedUrl, string $slug): ?array
    {
        if ('' === $feedUrl || '' === $slug) {
            return null;
        }

        $feedData = $this->getCachedFeedData($feedUrl);
        if (null === $feedData) {
            return null;
        }

        foreach ($feedData['episodes'] as $episode) {
            if ($this->generateSlug($episode) === $slug) {
                return $episode;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function getFeedMeta(string $feedUrl): array
    {
        $feedData = $this->getCachedFeedData($feedUrl);

        return $feedData['meta'] ?? [];
    }

    /** @return array{meta: array<string, string>, episodes: list<array<string, mixed>>}|null */
    private function getCachedFeedData(string $feedUrl): ?array
    {
        try {
            $cache = $this->getCacheManager()->getCache('content_blocks_collection_rssfeed');
            // Cache-Key MUSS mit PodcastFeedService::cacheIdentifierFor()
            // uebereinstimmen — content-blocks-collection migrierte md5 → sha256
            // im SSRF-Fix-Commit 50d005a, hier blieb md5 stehen → Reader fand
            // nie Daten → canHandle() returnte false → PodcastEpisode JSON-LD
            // wurde nie emittiert.
            $data = $cache->get('rssfeed_' . hash('sha256', $feedUrl));
            if (is_array($data) && isset($data['meta'], $data['episodes'])) {
                return $data;
            }
        } catch (\Throwable) {
            // Cache not available
        }

        return null;
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
}
