<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Cache\CacheManager;

final class BreadcrumbListSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use PodcastFeedCacheReaderTrait;

    public function __construct(
        private readonly CacheManager $cacheManager,
    ) {}

    private function getCacheManager(): CacheManager
    {
        return $this->cacheManager;
    }

    /**
     * Doktypes that represent navigable, content-bearing pages and therefore
     * belong in a breadcrumb. Anything else (Menu Separator 199, Folder 254,
     * Recycler 255, Backend Section 6, Mountpoint 7) is purely a structural
     * container and must be skipped — Google's Rich Result test rejects
     * breadcrumbs that include them, and editors don't expect to see
     * separator entries or sysfolder titles in the trail.
     */
    private const NAVIGABLE_DOKTYPES = [
        1, // Standard page
        4, // Shortcut
        3, // External URL
    ];

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $uid = (int) ($pageData['uid'] ?? 0);
        $rootPageId = (int) ($pageData['_rootPageId'] ?? -1);

        return $uid !== $rootPageId && isset($pageData['_rootline']) && is_array($pageData['_rootline']);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        /** @var array<int, array<string, mixed>> $rootline */
        $rootline = $pageData['_rootline'] ?? [];
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');

        // TYPO3 rootline is ordered from current page to root — reverse for breadcrumb order
        $sortedRootline = array_reverse($rootline);

        $items = [];
        $position = 1;

        foreach ($sortedRootline as $page) {
            // Skip menu separators, sysfolders, recyclers, mountpoints —
            // anything that doesn't represent a navigable page.
            $doktype = (int) ($page['doktype'] ?? 1);
            if (!in_array($doktype, self::NAVIGABLE_DOKTYPES, true)) {
                continue;
            }

            // Pages explicitly hidden from navigation (nav_hide=1) shouldn't
            // appear in the breadcrumb either — the trail is part of the
            // navigation surface.
            if (1 === (int) ($page['nav_hide'] ?? 0)) {
                continue;
            }

            $navTitle = $this->sanitizeText((string) ($page['nav_title'] ?? ''));
            $title = '' !== $navTitle ? $navTitle : $this->sanitizeText((string) ($page['title'] ?? ''));
            $slug = (string) ($page['slug'] ?? '');

            if ('' === $title) {
                continue;
            }

            if ('/' === $slug || '' === $slug) {
                $url = $siteBase;
            } else {
                $url = rtrim($siteBase, '/') . '/' . ltrim($slug, '/');
            }

            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $title,
                'item' => $url,
            ];

            ++$position;
        }

        // Append podcast episode as a final crumb when the page resolves to
        // an Extbase episode-show route. Without this the breadcrumb stops
        // at the series page even though the URL points at a single episode.
        $episodeCrumb = $this->buildEpisodeCrumb($pageData, $contentElements, $siteBase, $position);
        if (null !== $episodeCrumb) {
            $items[] = $episodeCrumb;
        }

        // Stable @id pro Page: <pageUrl>#breadcrumb. Spec.
        $pageSlug = (string) ($pageData['slug'] ?? '');
        $pageUrl = '' === $pageSlug || '/' === $pageSlug
            ? $siteBase
            : rtrim($siteBase, '/') . '/' . ltrim($pageSlug, '/');

        $data = [];
        if ('' !== $pageUrl) {
            $data['@id'] = $pageUrl . '#breadcrumb';
        }
        $data['itemListElement'] = $items;

        return new JsonLdDocument('BreadcrumbList', $data);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     *
     * @return array<string, mixed>|null
     */
    private function buildEpisodeCrumb(array $pageData, array $contentElements, string $siteBase, int $position): ?array
    {
        $episodeSlug = $this->resolveEpisodeSlug($pageData, $contentElements);
        if ('' === $episodeSlug) {
            return null;
        }

        $feedUrl = $this->findFeedUrl($contentElements);
        $episode = $this->findEpisodeBySlug($feedUrl, $episodeSlug);
        if (null === $episode) {
            return null;
        }

        $title = $this->sanitizeText((string) ($episode['title'] ?? ''));
        if ('' === $title) {
            return null;
        }

        $pageSlug = (string) ($pageData['slug'] ?? '');
        $pageUrl = '' === $pageSlug || '/' === $pageSlug
            ? $siteBase
            : rtrim($siteBase, '/') . '/' . ltrim($pageSlug, '/');
        $episodeUrl = rtrim($pageUrl, '/') . '/' . $episodeSlug;

        return [
            '@type' => 'ListItem',
            'position' => $position,
            'name' => $title,
            'item' => $episodeUrl,
        ];
    }

    public function getPriority(): int
    {
        return 90;
    }
}
