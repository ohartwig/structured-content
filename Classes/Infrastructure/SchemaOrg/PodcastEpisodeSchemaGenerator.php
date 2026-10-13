<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Domain\Contract\EpisodeGuestsRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * Generates PodcastEpisode schema for episode detail pages.
 *
 * Detects episode detail pages by checking for a single-episode
 * routing argument. Produces a full PodcastEpisode with VideoObject
 * that has url/mainEntityOfPage pointing to the own TYPO3 page
 * (not the external podcast platform).
 *
 * Google VideoObject required: name, thumbnailUrl, uploadDate
 * Google VideoObject recommended: description, duration, contentUrl, embedUrl
 */
final class PodcastEpisodeSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use PodcastFeedCacheReaderTrait;

    public function __construct(
        private readonly CacheManager $cacheManager,
        private readonly PersonSchemaBuilder $personBuilder,
        private readonly EpisodeGuestsRepositoryInterface $episodeGuestsRepository,
        private readonly \TYPO3\CMS\Core\Database\ConnectionPool $connectionPool,
    ) {}

    private function getCacheManager(): CacheManager
    {
        return $this->cacheManager;
    }

    /** @param array<string, mixed> $pageData */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $episodeSlug = $this->resolveEpisodeSlug($pageData, $contentElements);
        if ('' === $episodeSlug) {
            return false;
        }

        // Only emit a PodcastEpisode if we can actually resolve the episode
        // payload from the cached feed — otherwise we'd ship `{name: ""}`
        // stubs that hurt SEO more than they help.
        $languageId = (int) ($pageData['sys_language_uid'] ?? 0);
        $feedUrl = $this->resolveEffectiveFeedUrl($contentElements, $pageData, $languageId);
        if ('' === $feedUrl) {
            return false;
        }

        return null !== $this->findEpisodeBySlug($feedUrl, $episodeSlug);
    }

    /** @param array<string, mixed> $pageData */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);
        $language = (string) ($pageData['_language'] ?? 'de');
        $pageSlug = (string) ($pageData['slug'] ?? '');
        $pageUrl = $siteBase . $pageSlug;

        $episodeSlug = $this->resolveEpisodeSlug($pageData, $contentElements);
        $languageId = (int) ($pageData['sys_language_uid'] ?? 0);
        $feedUrl = $this->resolveEffectiveFeedUrl($contentElements, $pageData, $languageId);
        $episode = $this->findEpisodeBySlug($feedUrl, $episodeSlug);
        // canHandle() guarantees these are populated.
        \assert(null !== $episode);

        $meta = $this->getFeedMeta($feedUrl);
        $episodeUrl = rtrim($pageUrl, '/') . '/' . $episodeSlug;
        $seriesImage = $meta['image'] ?? '';
        $imageUrl = (string) ($episode['localImage'] ?? $episode['imageUrl'] ?? '');
        if ('' === $imageUrl) {
            $imageUrl = $seriesImage;
        }

        // Multi-Type-Knoten bei Video-Episoden mit auflösbarer enclosureUrl:
        // PodcastEpisode + VideoObject. Google klassifiziert die Page als
        // "Video Playback Page" nur, wenn eine VideoObject-Entity die
        // Hauptentity auf der Seite ist — associatedMedia.VideoObject
        // alleine (nested) reicht dafuer nicht. Ohne enclosureUrl koennten
        // wir die VideoObject-Required-Properties nicht befuellen, dann
        // bleibt es bei PodcastEpisode-pur.
        $isVideo = (bool) ($episode['isVideo'] ?? false)
            && ((string) ($episode['enclosureUrl'] ?? '')) !== '';

        $data = [
            '@type' => $isVideo ? ['PodcastEpisode', 'VideoObject'] : 'PodcastEpisode',
            'name' => $this->sanitizeText((string) ($episode['title'] ?? '')),
            'url' => $episodeUrl,
            'mainEntityOfPage' => [
                '@id' => $episodeUrl . '#webpage',
            ],
        ];

        if ('' !== $language) {
            $data['inLanguage'] = $language;
        }

        $data['publisher'] = ['@id' => $entityBase . '#organization'];

        // Hosts (plugin-level) + Guests (per-episode) → Schema.org actor[]
        $persons = $this->collectHostPersons($contentElements, $pageData, $entityBase, $episodeSlug, $languageId);
        if ([] !== $persons) {
            $data['actor'] = 1 === count($persons) ? $persons[0] : $persons;
        }

        $description = (string) ($episode['description'] ?? '');
        if ('' !== $description) {
            $data['description'] = $this->sanitizeText($description);
        }

        $pubDate = (string) ($episode['pubDate'] ?? '');
        if ('' !== $pubDate) {
            try {
                $data['datePublished'] = new \DateTimeImmutable($pubDate)->format('c');
            } catch (\Exception) {
                // skip
            }
        }

        $duration = (int) ($episode['duration'] ?? 0);
        if ($duration > 0) {
            $data['timeRequired'] = $this->formatIso8601Duration($duration);
        }

        $episodeNumber = (string) ($episode['episode'] ?? '');
        if ('' !== $episodeNumber) {
            $data['episodeNumber'] = (int) $episodeNumber;
        }

        $season = (string) ($episode['season'] ?? '');
        if ('' !== $season) {
            $data['partOfSeason'] = [
                '@type' => 'PodcastSeason',
                'seasonNumber' => (int) $season,
            ];
        }

        // partOfSeries reference
        $seriesName = $this->sanitizeText($meta['title'] ?? '');
        if ('' !== $seriesName) {
            $data['partOfSeries'] = [
                '@type' => 'PodcastSeries',
                'name' => $seriesName,
                'url' => $pageUrl,
            ];
        }

        if ('' !== $imageUrl) {
            $data['thumbnailUrl'] = $this->absolutize($imageUrl, $siteBase);
        }

        // Transcript — Schema.org/PodcastEpisode.transcript akzeptiert plain
        // Text. Wir liefern die rohe Textarea-Eingabe (timestamp/speaker/text)
        // 1:1 raus — Whisper- und LLM-Crawler verstehen das Format gut, und
        // Google's Rich-Result-Engine ignoriert es schadlos wenn nicht
        // unterstuetzt. Plus mirroring auf VideoObject.transcript /
        // AudioObject.transcript fuer Google Search Console "Videos" report.
        //
        // Lookup-Strategie (analog PodcastController parent-page-promotion):
        // 1. Plugin auf der current page (Detail-Container) → Lookup
        // 2. Wenn leer, Plugin auf Parent-Page (Listing-Page) → Lookup
        // Das spiegelt die Editor-Workflow-Vereinfachung: Episode-Meta muss
        // nur am Listing-Plugin gepflegt werden, nicht doppelt am Detail-
        // Container.
        $transcript = '';
        $pluginUid = $this->findPodcastPluginUidOnPage(
            (int) ($pageData['uid'] ?? 0),
            $languageId,
        );
        if ($pluginUid > 0) {
            $transcript = $this->episodeGuestsRepository->findTranscriptByPluginAndSlug(
                $pluginUid,
                $episodeSlug,
                $languageId,
            );
        }
        if ('' === $transcript) {
            $parentPid = $this->resolveParentPageUid((int) ($pageData['pid'] ?? 0));
            if (0 === $parentPid && (int) ($pageData['uid'] ?? 0) > 0) {
                // pageData has uid but pid wasn't passed — derive parent
                // from current uid's parent.
                $parentPid = $this->resolveParentPageUid((int) $pageData['uid']);
            }
            if ($parentPid > 0) {
                $parentPluginUid = $this->findPodcastPluginUidOnPage($parentPid, $languageId);
                if ($parentPluginUid > 0 && $parentPluginUid !== $pluginUid) {
                    $transcript = $this->episodeGuestsRepository->findTranscriptByPluginAndSlug(
                        $parentPluginUid,
                        $episodeSlug,
                        $languageId,
                    );
                }
            }
        }
        if ('' !== $transcript) {
            $data['transcript'] = $transcript;
        }

        $enclosureUrl = (string) ($episode['enclosureUrl'] ?? '');
        $enclosureType = (string) ($episode['enclosureType'] ?? '');
        $link = (string) ($episode['link'] ?? '');

        // Bei Video-Episoden hebt der Multi-Type-Knoten die VideoObject-
        // Required-Properties auf Top-Level (uploadDate, duration, contentUrl,
        // embedUrl). thumbnailUrl + name + description + transcript sind oben
        // bereits gesetzt und gelten fuer beide @type-Werte gleichermassen.
        if ($isVideo && '' !== $enclosureUrl) {
            if (isset($data['datePublished'])) {
                $data['uploadDate'] = $data['datePublished'];
            }
            if ($duration > 0) {
                $data['duration'] = $this->formatIso8601Duration($duration);
            }
            $data['contentUrl'] = $enclosureUrl;
            if ('' !== $link) {
                // Schema.org: embedUrl ist die Player-URL, NICHT die Watch-Page.
                // Watch-Page-Bezug laeuft ueber mainEntityOfPage (s.o.).
                $data['embedUrl'] = $link;
            }
            if ('' !== $enclosureType) {
                $data['encodingFormat'] = $enclosureType;
            }
        }

        // associatedMedia — VideoObject or AudioObject (Spec-Compliance fuer
        // Crawler, die die Media-Datei als separates Sub-Object erwarten;
        // bei isVideo redundant zu den Top-Level-Properties, aber unschaedlich).
        if ('' !== $enclosureUrl) {
            $mediaObject = [
                '@type' => $isVideo ? 'VideoObject' : 'AudioObject',
                'name' => $data['name'],
                'contentUrl' => $enclosureUrl,
            ];

            if ('' !== $description) {
                $mediaObject['description'] = $this->sanitizeText($description);
            }
            if ('' !== $imageUrl) {
                $mediaObject['thumbnailUrl'] = $this->absolutize($imageUrl, $siteBase);
            }
            if (isset($data['datePublished'])) {
                $mediaObject['uploadDate'] = $data['datePublished'];
            }
            if ($duration > 0) {
                $mediaObject['duration'] = $this->formatIso8601Duration($duration);
            }
            if ('' !== $transcript) {
                $mediaObject['transcript'] = $transcript;
            }
            if ('' !== $enclosureType) {
                $mediaObject['encodingFormat'] = $enclosureType;
            }
            if ('' !== $link) {
                $mediaObject['embedUrl'] = $link;
            }

            $data['associatedMedia'] = $mediaObject;
        }

        return new JsonLdDocument('PodcastEpisode', $data);
    }

    public function getPriority(): int
    {
        return 80; // Higher than Article (50) and FAQ (70)
    }

    /**
     * Collects plugin-level hosts plus per-episode guests (when an episode
     * slug is resolvable) and returns them as a deduplicated, ordered list of
     * Schema.org Person objects.
     *
     * Walks both the current page's plugin and — if the current page is a
     * Detail-Container with empty fields — the parent (Listing) page's
     * plugin. Same parent-page-promotion-pattern the PodcastController uses,
     * mirrored here for the Schema-Generator.
     *
     * @param list<array<string, mixed>> $contentElements
     * @param array<string, mixed>       $pageData
     *
     * @return list<array<string, mixed>>
     */
    private function collectHostPersons(
        array $contentElements,
        array $pageData,
        string $entityBase,
        string $episodeSlug,
        int $languageId,
    ): array {
        $uids = [];
        $pluginUid = 0;
        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') !== 'contentblockscollection_podcast') {
                continue;
            }
            if (0 === $pluginUid) {
                $pluginUid = (int) ($element['uid'] ?? 0);
            }
            $value = $element['moselwal_videopodcastfeed_hosts'] ?? null;
            $uids = array_merge($uids, $this->personBuilder->parseUidList($value));
        }

        // Parent-page-promotion: if current detail-page has no plugin or
        // its hosts are empty, look up the listing-page plugin. Wichtig:
        // wir UEBERSCHREIBEN $pluginUid mit der Parent-Plugin-UID — auch
        // wenn lokal ein leeres Plugin existiert. Episode-Meta-Records
        // (foreign_table_parent_uid) haengen am Parent-Plugin, der Guest-
        // Lookup mit lokaler Plugin-UID waere sonst leer.
        if ([] === $uids) {
            $currentPid = (int) ($pageData['uid'] ?? 0);
            $parentPid = $this->resolveParentPageUid($currentPid);
            if ($parentPid > 0) {
                $parentRow = $this->fetchPodcastPluginRowOnPage($parentPid, $languageId);
                if (null !== $parentRow) {
                    $pluginUid = (int) ($parentRow['uid'] ?? 0);
                    $uids = array_merge(
                        $uids,
                        $this->personBuilder->parseUidList(
                            $parentRow['moselwal_videopodcastfeed_hosts'] ?? null,
                        ),
                    );
                }
            }
        }

        if ('' !== $episodeSlug && $pluginUid > 0) {
            // Subtract per-episode absent hosts from the host UIDs BEFORE
            // merging guests — mirrors PodcastController's frontend filtering
            // (only hosts are removed; guests stay additive). Keeps the
            // actor[] list consistent with the rendered episode page.
            $absentHostUids = $this->episodeGuestsRepository->findAbsentHostUidsByPluginAndSlug(
                $pluginUid,
                $episodeSlug,
                $languageId,
            );
            if ([] !== $absentHostUids) {
                $uids = array_values(array_filter(
                    $uids,
                    static fn(int $u): bool => !in_array($u, $absentHostUids, true),
                ));
            }

            $guestUids = $this->episodeGuestsRepository->findGuestUidsByPluginAndSlug(
                $pluginUid,
                $episodeSlug,
                $languageId,
            );
            $uids = array_merge($uids, $guestUids);
        }

        if ([] === $uids) {
            return [];
        }

        // @id-Ref auf die Author-Detail-Page bevorzugt — Person-Entity wird
        // einmal auf /autor/<slug> emittiert, Cross-Refs aus PodcastEpisode.
        // actor brauchen nur die Identitaet.
        $persons = [];
        foreach (array_values(array_unique($uids)) as $uid) {
            $ref = $this->personBuilder->buildPersonReference($uid, $entityBase);
            if (null !== $ref) {
                $persons[] = $ref;

                continue;
            }
            $inline = $this->personBuilder->buildFromPersonUid($uid, $entityBase);
            if (null !== $inline) {
                $persons[] = $inline;
            }
        }

        return $persons;
    }

    /**
     * Fetches a Podcast-plugin tt_content row on a given page (for parent-
     * page-promotion). Returns the FULL row (not just uid) because
     * collectHostPersons needs the moselwal_videopodcastfeed_hosts value.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPodcastPluginRowOnPage(int $pageUid, int $languageId): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }
        $row = $this->queryPodcastPluginRow($pageUid, $languageId);
        // Site-level fallbackType=fallback: if no translation of the plugin
        // record exists in the requested language, fall back to the default-
        // language row so the schema still emits with the canonical feed URL
        // instead of going silent on translated detail pages.
        if (null === $row && $languageId > 0) {
            $row = $this->queryPodcastPluginRow($pageUid, 0);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function queryPodcastPluginRow(int $pageUid, int $languageId): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->setRestrictions(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer::class,
        ));
        $row = $qb->select('uid', 'pid', 'moselwal_videopodcastfeed_hosts', 'moselwal_videopodcastfeed_feed_url')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq(
                    'pid',
                    $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
                $qb->expr()->eq(
                    'CType',
                    $qb->createNamedParameter('contentblockscollection_podcast'),
                ),
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Effektive Feed-URL: erst lokale ContentElements (eigenes Plugin auf der
     * Detail-Subpage), dann das Plugin auf der Parent-Page (Listing-Page).
     * Spiegelt PodcastController::resolveEffectivePluginData(), damit Editoren
     * das Plugin nur einmal am Listing pflegen muessen und die Episode-
     * Detail-Subpages als reine Routing-Container ohne eigenes Plugin
     * existieren koennen.
     *
     * @param list<array<string, mixed>> $contentElements
     * @param array<string, mixed>       $pageData
     */
    private function resolveEffectiveFeedUrl(array $contentElements, array $pageData, int $languageId): string
    {
        $local = $this->findFeedUrl($contentElements);
        if ('' !== $local) {
            return $local;
        }
        $currentPid = (int) ($pageData['uid'] ?? 0);
        $parentPid = $this->resolveParentPageUid($currentPid);
        if (0 === $parentPid) {
            return '';
        }
        $row = $this->fetchPodcastPluginRowOnPage($parentPid, $languageId);

        return null !== $row
            ? (string) ($row['moselwal_videopodcastfeed_feed_url'] ?? '')
            : '';
    }

    /**
     * Schema.org-Konsumenten (Google Rich Results, LLM-Crawler) erwarten in
     * `thumbnailUrl` absolute URLs. FAL liefert relative `/fileadmin/...`-
     * Paths — wir prefixen mit dem Site-Base, lassen aber bereits absolute
     * URLs unangetastet.
     */
    private function absolutize(string $url, string $siteBase): string
    {
        if ('' === $url || 1 === preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return rtrim($siteBase, '/') . '/' . ltrim($url, '/');
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
        if ($s > 0 || (0 === $h && 0 === $m)) {
            $duration .= $s . 'S';
        }

        return $duration;
    }

    /**
     * Finds the first Podcast-plugin tt_content on the given page in the
     * specified language. Used to locate the canonical plugin instance both
     * on the current detail page and on its parent (listing) page.
     */
    private function findPodcastPluginUidOnPage(int $pageUid, int $languageId): int
    {
        if ($pageUid <= 0) {
            return 0;
        }
        $uid = $this->queryPodcastPluginUid($pageUid, $languageId);
        // Site fallback semantics: when the requested language has no translated
        // plugin row, fall back to the default-language one. Otherwise host-
        // person resolution silently drops on EN-pages without translations.
        if (0 === $uid && $languageId > 0) {
            $uid = $this->queryPodcastPluginUid($pageUid, 0);
        }

        return $uid;
    }

    private function queryPodcastPluginUid(int $pageUid, int $languageId): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->setRestrictions(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer::class,
        ));
        $uid = $qb->select('uid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq(
                    'pid',
                    $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
                $qb->expr()->eq(
                    'CType',
                    $qb->createNamedParameter('contentblockscollection_podcast'),
                ),
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            )
            ->orderBy('sorting', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_int($uid) || ctype_digit((string) $uid) ? (int) $uid : 0;
    }

    /**
     * Resolves the parent page UID for a given page UID. Workspace-aware.
     */
    private function resolveParentPageUid(int $pageUid): int
    {
        if ($pageUid <= 0) {
            return 0;
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->setRestrictions(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer::class,
        ));
        $pid = $qb->select('pid')
            ->from('pages')
            ->where(
                $qb->expr()->eq(
                    'uid',
                    $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_int($pid) || ctype_digit((string) $pid) ? (int) $pid : 0;
    }
}
