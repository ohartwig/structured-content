<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

/**
 * Single source of truth for Article-/WebPage-family schema-type detection.
 *
 * Resolves a single Schema.org type for a page from the following ordered
 * sources:
 *  1. Manual override via `pages.tx_structuredcontent_schema_type`
 *     (with legacy fallback to `tx_semanticdelivery_schema_type` for sites
 *     that have not yet run the MigrateSchemaTypeFieldUpgrade wizard).
 *  2. Slug heuristic (language-stable index patterns: `/blog`, `/news`,
 *     `/press`, `/presse`, `/portfolio`, `/referenz*`).
 *  3. CType hint from the first hero content block on the page.
 *  4. Keywords heuristic (`pages.keywords` substrings).
 *  5. Per-family default (Article for doktype 1/2, otherwise WebPage).
 *
 * Returns `null` when the page is owned by another generator (FAQ, Service,
 * Product, Podcast …) so those generators can keep ownership.
 */
final class SchemaTypeResolverService
{
    private const ARTICLE_DOKTYPES = [1, 2];

    /** @var array<string, string> CType → Schema.org type */
    private const HERO_CTYPE_MAP = [
        'moselwal_bloghero' => 'BlogPosting',
        'moselwal_pagehero' => 'Article',
        'moselwal_hero' => 'WebPage',
        'moselwal_herocard' => 'Article',
        'moselwal_olehero' => 'AboutPage',
        'moselwal_nozzlehero' => 'WebPage',
        'moselwal_teaser' => 'NewsArticle',
    ];

    private const ARTICLE_FAMILY = [
        'Article',
        'BlogPosting',
        'NewsArticle',
        'TechArticle',
        'ScholarlyArticle',
        'Report',
        'SocialMediaPosting',
    ];

    private const WEBPAGE_FAMILY = [
        'WebPage',
        'AboutPage',
        'ContactPage',
        'CollectionPage',
        'ItemPage',
        'ProfilePage',
        'QAPage',
    ];

    /** Slugs handled by other generators — resolver returns null. */
    private const FOREIGN_SLUG_PATTERNS = [
        '/leistungen',
        '/impressum',
        '/datenschutz',
        '/karriere',
        '/podcasts',
        '/open-source',
        '/opensource',
    ];

    /** CTypes owned by FAQ/Service/Product/Podcast generators. */
    private const FOREIGN_CTYPES_EXACT = [
        'moselwal_videopodcastfeed',
        'contentblockscollection_podcast',
        'moselwal_packagesgrid',
    ];

    /** CType substrings that signal a foreign generator owns the page. */
    private const FOREIGN_CTYPE_PATTERNS = [
        'service',
        'solution',
    ];

    /**
     * Resolve the schema type for the given page. Returns `null` when the
     * page is not in the Article- or WebPage-family.
     *
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function resolve(array $pageData, array $contentElements): ?string
    {
        $isRootPage = (int) ($pageData['uid'] ?? 0) === (int) ($pageData['_rootPageId'] ?? -1);
        if ($isRootPage) {
            return null;
        }

        // 1) Manual override — highest priority. Read new field first, fall
        //    back to the legacy field name for sites that have not yet run
        //    the MigrateSchemaTypeFieldUpgrade wizard.
        $override = trim((string) ($pageData['tx_structuredcontent_schema_type'] ?? ''));
        if ('' === $override) {
            $override = trim((string) ($pageData['tx_semanticdelivery_schema_type'] ?? ''));
        }
        if ('' !== $override) {
            if ('article' === $this->getFamily($override) || 'webpage' === $this->getFamily($override)) {
                return $override;
            }

            return null;
        }

        if ($this->hasForeignContent($contentElements)) {
            return null;
        }

        if ($this->hasForeignSlug($pageData)) {
            return null;
        }

        // 2) Slug heuristic — wins over hero-CType so unambiguous index
        //    pages aren't mislabeled as "Article" just because they happen
        //    to use a generic page-hero block.
        $slugMatch = $this->matchSlug((string) ($pageData['slug'] ?? ''));
        if (null !== $slugMatch) {
            return $slugMatch;
        }

        // 3) CType hint from the first matching hero content block.
        foreach ($contentElements as $element) {
            $ctype = (string) ($element['CType'] ?? '');
            if (isset(self::HERO_CTYPE_MAP[$ctype])) {
                return self::HERO_CTYPE_MAP[$ctype];
            }
        }

        // 4) Keywords heuristic (legacy + new tech/scholarly hints).
        $keywordMatch = $this->matchKeywords((string) ($pageData['keywords'] ?? ''));
        if (null !== $keywordMatch) {
            return $keywordMatch;
        }

        // 5) Per-family default — only when there is real content to describe.
        if ([] === $contentElements) {
            return null;
        }

        $doktype = (int) ($pageData['doktype'] ?? 0);
        if (in_array($doktype, self::ARTICLE_DOKTYPES, true)) {
            return 'Article';
        }

        return 'WebPage';
    }

    /**
     * @return 'article'|'webpage'|'other'
     */
    public function getFamily(string $schemaType): string
    {
        if (in_array($schemaType, self::ARTICLE_FAMILY, true)) {
            return 'article';
        }
        if (in_array($schemaType, self::WEBPAGE_FAMILY, true)) {
            return 'webpage';
        }

        return 'other';
    }

    /**
     * @param list<array<string, mixed>> $contentElements
     */
    private function hasForeignContent(array $contentElements): bool
    {
        foreach ($contentElements as $element) {
            $ctype = (string) ($element['CType'] ?? '');
            if (in_array($ctype, self::FOREIGN_CTYPES_EXACT, true)) {
                return true;
            }
            $lowered = strtolower($ctype);
            foreach (self::FOREIGN_CTYPE_PATTERNS as $pattern) {
                if (str_contains($lowered, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function hasForeignSlug(array $pageData): bool
    {
        $slug = (string) ($pageData['slug'] ?? '');
        foreach (self::FOREIGN_SLUG_PATTERNS as $pattern) {
            if ($slug === $pattern || str_starts_with($slug, $pattern . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match the page slug against language-stable index/section patterns.
     *
     * Only patterns that look the same across the major Western languages
     * are matched here (`/blog`, `/news`, `/press`, `/portfolio`,
     * `/referenz*`). Language-specific page-type slugs like `/kontakt` or
     * `/ueber-uns` are intentionally NOT matched — editors should use the
     * backend override (`tx_structuredcontent_schema_type`) for those, with
     * help from the per-item dropdown descriptions.
     */
    private function matchSlug(string $slug): ?string
    {
        if ('' === $slug) {
            return null;
        }

        $lower = strtolower($slug);
        $segments = explode('/', trim($lower, '/'));
        $first = $segments[0];
        $hasSubpath = count($segments) > 1 && '' !== $segments[count($segments) - 1];

        // Blog index vs. detail.
        if ('blog' === $first) {
            return $hasSubpath ? 'BlogPosting' : 'CollectionPage';
        }

        // News / press index vs. detail.
        if ('news' === $first || 'presse' === $first || 'press' === $first) {
            return $hasSubpath ? 'NewsArticle' : 'CollectionPage';
        }

        // Portfolio / case studies / references — landing or item page.
        if (str_starts_with($first, 'referenz') || str_starts_with($first, 'portfolio')) {
            return $hasSubpath ? 'ItemPage' : 'CollectionPage';
        }

        return null;
    }

    private function matchKeywords(string $keywords): ?string
    {
        $lower = strtolower($keywords);
        if ('' === $lower) {
            return null;
        }

        if (str_contains($lower, 'tech') || str_contains($lower, 'technical')) {
            return 'TechArticle';
        }
        if (str_contains($lower, 'paper') || str_contains($lower, 'study') || str_contains($lower, 'research')) {
            return 'ScholarlyArticle';
        }
        if (str_contains($lower, 'social')) {
            return 'SocialMediaPosting';
        }
        if (str_contains($lower, 'report')) {
            return 'Report';
        }
        if (str_contains($lower, 'blog')) {
            return 'BlogPosting';
        }
        if (str_contains($lower, 'news') || str_contains($lower, 'press')) {
            return 'NewsArticle';
        }
        if (str_contains($lower, 'collection') || str_contains($lower, 'index')) {
            return 'CollectionPage';
        }

        return null;
    }
}
