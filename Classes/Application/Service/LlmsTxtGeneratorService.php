<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

use Koh\StructuredContent\Application\Dto\SiteContext;
use Koh\StructuredContent\Domain\Contract\PageTreeRepositoryInterface;

/**
 * Builds the llms.txt and llms-full.md payloads from the TYPO3 page tree
 * for a given site.
 *
 * Source-of-truth for what gets included:
 *   - pages.nav_hide = 0 (and not hidden / not deleted)
 *   - pages.doktype IN [1, 2, 4]  (regular page, advanced, shortcut)
 *   - root page (uid = site root) is ALWAYS included as homepage entry
 *
 * The resulting Markdown follows the llmstxt.org spec:
 *   # <SiteTitle>
 *
 *   > <Summary>                 — tagline, else the organization description
 *
 *   <Intro paragraph>
 *
 *   ## <Featured label>         — pages flagged tx_structuredcontent_llms_featured,
 *                                 in page-tree order, at most featuredLimit
 *   - [<Title>](<URL>): <Description>
 *
 *   ## <Hub-1 label>            — top-level child page with subtree; a hub
 *   - [<Title>](<URL>): <Desc>    with more posts than postListLimit lists the
 *                                 newest ones plus its archive/index pages
 *
 *   ## <Hub-2 label>
 *   - ...
 *
 *   ## Wichtige Seiten          — top-level pages without their own hub
 *   - ...
 *
 *   ## Optional                 — subtrees flagged as optional (skippable)
 *   - ...
 *
 *   ## Kontakt                  — site-wide contact info
 *   - E-Mail: <email>
 */
final readonly class LlmsTxtGeneratorService
{
    /**
     * Newest pages kept per hub section when the setting is absent. Mirrors
     * the default in settings.definitions.yaml.
     */
    public const int DEFAULT_POST_LIST_LIMIT = 30;

    /**
     * Maximum number of flagged pages in the featured section when the
     * setting is absent. Mirrors the default in settings.definitions.yaml.
     */
    public const int DEFAULT_FEATURED_LIMIT = 15;

    public function __construct(
        private PageTreeRepositoryInterface $pageTree,
    ) {}

    /**
     * Render the llms.txt for the given site in the given language.
     *
     * Per-language settings use a camelCase ISO-2 suffix (e.g. `taglineEn`,
     * `introEn`, `mainGroupLabelEn`, `contactSectionLabelEn`). When the language-
     * suffixed key is empty, the default key is used as fallback (default
     * language = no suffix).
     */
    public function buildLlmsTxt(SiteContext $context): string
    {
        $languageId = $context->languageId;

        $settings = $context->settings;
        $sc = $settings['structuredContent'] ?? [];
        $llms = is_array($sc) ? ($sc['llmsTxt'] ?? []) : [];
        $llms = is_array($llms) ? $llms : [];

        $base = rtrim($context->base, '/');
        // Site title comes from the language-specific `websiteTitle` config
        // (TYPO3 v14: SiteLanguage::getWebsiteTitle()) if set, otherwise from
        // the global site `websiteTitle`. The previous logic mistakenly used
        // `$language->getTitle()` which returns the *language name* (e.g.
        // "English"), not the site/brand title.
        $title = $this->resolveSiteTitle($context);

        $tagline = $this->resolveSummary($llms, is_array($sc) ? $sc : [], $context);
        $intro = $this->getLangSetting($llms, 'intro', $context);
        $mainGroupLabel = $this->getLangSetting($llms, 'mainGroupLabel', $context);
        if ('' === $mainGroupLabel) {
            $mainGroupLabel = $languageId === 0 ? 'Wichtige Seiten' : 'Important Pages';
        }
        $optionalGroupLabel = $this->getLangSetting($llms, 'optionalGroupLabel', $context);
        if ('' === $optionalGroupLabel) {
            $optionalGroupLabel = 'Optional';
        }
        $contactGroupLabel = $this->getLangSetting($llms, 'contactGroupLabel', $context);
        if ('' === $contactGroupLabel) {
            $contactGroupLabel = $languageId === 0 ? 'Kontakt' : 'Contact';
        }
        $featuredGroupLabel = $this->getLangSetting($llms, 'featuredGroupLabel', $context);
        if ('' === $featuredGroupLabel) {
            $featuredGroupLabel = $languageId === 0 ? 'Empfohlene Seiten' : 'Featured Pages';
        }
        $archiveLinkLabel = $this->getLangSetting($llms, 'archiveLinkLabel', $context);
        if ('' === $archiveLinkLabel) {
            $archiveLinkLabel = $languageId === 0 ? 'Alle Beiträge' : 'All posts';
        }
        // Zero or less falls back to the default rather than meaning
        // "unlimited": the section exists to be short.
        $featuredLimitRaw = $llms['featuredLimit'] ?? self::DEFAULT_FEATURED_LIMIT;
        $featuredLimit = is_numeric($featuredLimitRaw) ? (int) $featuredLimitRaw : self::DEFAULT_FEATURED_LIMIT;
        if ($featuredLimit <= 0) {
            $featuredLimit = self::DEFAULT_FEATURED_LIMIT;
        }
        // Missing key = the default of the settings definition. Zero or less
        // switches the cap off and restores the full list.
        $postListLimitRaw = $llms['postListLimit'] ?? self::DEFAULT_POST_LIST_LIMIT;
        $postListLimit = is_numeric($postListLimitRaw) ? (int) $postListLimitRaw : self::DEFAULT_POST_LIST_LIMIT;
        $optionalParentsRaw = $llms['optionalParents'] ?? [];
        $optionalParents = $this->normaliseUidList($optionalParentsRaw);

        $contactEmail = trim((string) ($llms['contactEmail'] ?? ''));
        $contactLocation = $this->getLangSetting($llms, 'contactLocation', $context);
        $linkedin = trim((string) ($llms['linkedinUrl'] ?? ''));
        $github = trim((string) ($llms['githubUrl'] ?? ''));

        $lines = [];
        $lines[] = '# ' . $title;
        $lines[] = '';
        if ('' !== $tagline) {
            $taglineLines = preg_split('/\r?\n/', $tagline);
            foreach ($taglineLines === false ? [$tagline] : $taglineLines as $taglineLine) {
                $lines[] = '> ' . $taglineLine;
            }
            $lines[] = '';
        }
        if ('' !== $intro) {
            $introLines = preg_split('/\r?\n/', $intro);
            foreach ($introLines === false ? [$intro] : $introLines as $introLine) {
                $lines[] = $introLine;
            }
            $lines[] = '';
        }

        $rootPageId = $context->rootPageId;
        $pages = $this->pageTree->loadVisiblePages($rootPageId, $languageId);
        $pidMap = $this->pageTree->loadPidMap();
        $groups = $this->groupPagesByHub($rootPageId, $pages, $pidMap, $optionalParents, $mainGroupLabel);

        // The curated section comes first: a reader that stops early should
        // have seen the pages the owner considers essential.
        $this->appendGroup($lines, $base, $featuredGroupLabel, $this->selectFeaturedPages($pages, $pidMap, $featuredLimit));

        foreach ($groups['hubs'] as $group) {
            $this->appendGroup(
                $lines,
                $base,
                $group['label'],
                $this->capPostList($group['hub'], $group['pages'], $pidMap, $postListLimit, $archiveLinkLabel),
            );
        }
        if ([] !== $groups['main']) {
            $this->appendGroup($lines, $base, $mainGroupLabel, $groups['main']);
        }
        if ([] !== $groups['optional']) {
            $this->appendGroup($lines, $base, $optionalGroupLabel, $groups['optional']);
        }

        if ('' !== $contactEmail || '' !== $contactLocation || '' !== $linkedin || '' !== $github) {
            $lines[] = '## ' . $contactGroupLabel;
            $lines[] = '';
            if ('' !== $contactEmail) {
                $lines[] = '- ' . ($languageId === 0 ? 'E-Mail' : 'Email') . ': ' . $contactEmail;
            }
            if ('' !== $contactLocation) {
                $lines[] = '- ' . ($languageId === 0 ? 'Standort' : 'Location') . ': ' . $contactLocation;
            }
            if ('' !== $linkedin) {
                $lines[] = '- LinkedIn: ' . $linkedin;
            }
            if ('' !== $github) {
                $lines[] = '- GitHub: ' . $github;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * The summary blockquote under the H1 (llmstxt.org: "a blockquote with a
     * short summary of the project").
     *
     * Order of preference:
     *   1. the tagline written for this language (`tagline`, or `taglineEn`
     *      etc. for a non-default language),
     *   2. the language's own organization description from the site
     *      configuration (`languages[].structuredContent.organizationDescription`),
     *   3. the default-language tagline,
     *   4. `structuredContent.schema.organizationDescription`.
     *
     * The organization description is the stable "who we are" sentence the
     * Organization and WebSite JSON-LD already carry, so a site that has not
     * written a tagline still gets a summary instead of none.
     *
     * @param array<string, mixed> $llms
     * @param array<array-key, mixed> $sc
     */
    private function resolveSummary(array $llms, array $sc, SiteContext $context): string
    {
        $candidates = [];
        if ($context->languageId > 0) {
            $iso = strtolower($context->languageIsoCode);
            if ('' !== $iso) {
                $candidates[] = $llms['tagline' . ucfirst($iso)] ?? '';
            }
            $candidates[] = $context->languageStructuredContent['organizationDescription'] ?? '';
            $candidates[] = $llms['tagline'] ?? '';
        } else {
            $candidates[] = $llms['tagline'] ?? '';
            $candidates[] = $context->languageStructuredContent['organizationDescription'] ?? '';
        }
        $schema = $sc['schema'] ?? [];
        $candidates[] = is_array($schema) ? ($schema['organizationDescription'] ?? '') : '';

        foreach ($candidates as $candidate) {
            $value = is_scalar($candidate) ? trim((string) $candidate) : '';
            if ('' !== $value) {
                return $value;
            }
        }

        return '';
    }

    /**
     * The pages editors flagged for the featured section, in page-tree order,
     * at most $limit of them.
     *
     * Only visible pages are candidates — $pages is already filtered — so a
     * flagged page that is hidden, nav-hidden or outside this site is left
     * out: listing it would recommend a page nobody can open.
     *
     * Page-tree order means depth-first by `sorting`: `sorting` is only
     * comparable between siblings, so each page is keyed by the sorting
     * values along its rootline, taken from the full sorting map so that
     * nav-hidden folders in between still count.
     *
     * @param list<array<string, mixed>> $pages
     * @param array<int, int> $pidMap
     * @return list<array<string, mixed>>
     */
    private function selectFeaturedPages(array $pages, array $pidMap, int $limit): array
    {
        // Editors tick `tx_structuredcontent_llms_featured` on the
        // default-language page; the uids are default-language uids like the
        // rows in $pages, so every language features the same pages.
        $flaggedUids = array_flip($this->pageTree->loadFeaturedPageUids());
        $flagged = [];
        foreach ($pages as $row) {
            if (isset($flaggedUids[(int) $row['uid']])) {
                $flagged[(int) $row['uid']] ??= $row;
            }
        }
        if ([] === $flagged) {
            return [];
        }

        $sortingMap = $this->pageTree->loadSortingMap();
        $treeKeys = [];
        foreach (array_keys($flagged) as $uid) {
            $path = [];
            $current = $uid;
            $maxDepth = 20;
            while ($maxDepth-- > 0 && $current > 0 && isset($pidMap[$current])) {
                $path[] = $sortingMap[$current] ?? 0;
                $current = $pidMap[$current];
            }
            $treeKeys[$uid] = array_reverse($path);
        }

        uksort($flagged, static function (int $a, int $b) use ($treeKeys): int {
            $pathA = $treeKeys[$a];
            $pathB = $treeKeys[$b];
            $length = min(count($pathA), count($pathB));
            for ($i = 0; $i < $length; ++$i) {
                if ($pathA[$i] !== $pathB[$i]) {
                    return $pathA[$i] <=> $pathB[$i];
                }
            }

            // A parent comes before its children; siblings with equal
            // sorting fall back to the uid, so the order is stable.
            return [count($pathA), $a] <=> [count($pathB), $b];
        });

        return array_slice(array_values($flagged), 0, $limit);
    }

    /**
     * Keep a hub section bounded.
     *
     * A hub's pages split into index pages — those with visible pages below
     * them inside the hub, such as per-year archive pages — and leaf pages,
     * the posts. When there are more posts than $limit, only the newest $limit
     * (by `crdate`, the key the blog's own feeds sort by) are listed, followed
     * by the index pages, which lead to the rest. A hub without index pages
     * gets a single link to itself instead, labelled $archiveLabel.
     *
     * Below the limit, or with a limit of zero or less, the section is left
     * exactly as it was, order included.
     *
     * @param array<string, mixed> $hub
     * @param list<array<string, mixed>> $pages
     * @param array<int, int> $pidMap
     * @return list<array<string, mixed>>
     */
    private function capPostList(array $hub, array $pages, array $pidMap, int $limit, string $archiveLabel): array
    {
        if ($limit <= 0 || count($pages) <= $limit) {
            return $pages;
        }

        $hubUid = (int) ($hub['uid'] ?? 0);
        $inHub = [];
        foreach ($pages as $row) {
            $inHub[(int) $row['uid']] = true;
        }
        $hasChildrenInHub = [];
        foreach ($pages as $row) {
            $current = $pidMap[(int) $row['uid']] ?? 0;
            $maxDepth = 20;
            while ($maxDepth-- > 0 && $current > 0 && $current !== $hubUid) {
                if (isset($inHub[$current])) {
                    $hasChildrenInHub[$current] = true;
                }
                $current = $pidMap[$current] ?? 0;
            }
        }

        $indexPages = [];
        $posts = [];
        foreach ($pages as $row) {
            if (isset($hasChildrenInHub[(int) $row['uid']])) {
                $indexPages[] = $row;
            } else {
                $posts[] = $row;
            }
        }
        if (count($posts) <= $limit) {
            return $pages;
        }

        // usort is stable: posts sharing a crdate keep their tree order.
        usort($posts, static fn (array $a, array $b): int => (int) ($b['crdate'] ?? 0) <=> (int) ($a['crdate'] ?? 0));
        $kept = array_slice($posts, 0, $limit);

        if ([] === $indexPages && $hubUid > 0) {
            $indexPages[] = [...$hub, 'title' => $archiveLabel];
        }

        return [...$kept, ...$indexPages];
    }

    /**
     * Look up a per-language setting key. For non-default languages, try
     * `<key><Iso2Ucfirst>` first (e.g. `taglineEn`); fall back to plain `<key>`.
     *
     * @param array<string, mixed> $llms
     */
    private function getLangSetting(array $llms, string $key, SiteContext $context): string
    {
        $languageId = $context->languageId;
        if ($languageId > 0) {
            $iso = strtolower($context->languageIsoCode);
            if ('' !== $iso) {
                $langKey = $key . ucfirst($iso);
                if (isset($llms[$langKey])) {
                    $value = trim((string) $llms[$langKey]);
                    if ('' !== $value) {
                        return $value;
                    }
                }
            }
        }
        return trim((string) ($llms[$key] ?? ''));
    }

    /**
     * Resolve the site/brand title for the given language.
     *
     * Order of preference:
     *   1. SiteLanguage::getWebsiteTitle() — language-specific override set
     *      in site config (`languages: [{websiteTitle: "..."}]`).
     *   2. Site `websiteTitle` from the global site configuration.
     *   3. Site identifier as last-resort fallback.
     *
     * Importantly does NOT fall through to `$language->getTitle()` — that
     * returns the language name (e.g. "English"), not the site title.
     */
    private function resolveSiteTitle(SiteContext $context): string
    {
        $langWebsiteTitle = trim($context->languageWebsiteTitle);
        if ('' !== $langWebsiteTitle) {
            return $langWebsiteTitle;
        }
        $siteWebsiteTitle = trim($context->siteWebsiteTitle);
        if ('' !== $siteWebsiteTitle) {
            return $siteWebsiteTitle;
        }
        return $context->siteIdentifier;
    }

    /**
     * Render a group of pages as a Markdown H2 section with link list.
     *
     * @param list<string> $lines
     * @param list<array<string, mixed>> $pages
     */
    private function appendGroup(array &$lines, string $base, string $label, array $pages): void
    {
        if ([] === $pages) {
            return;
        }
        $lines[] = '## ' . $label;
        $lines[] = '';
        $seenUrls = [];
        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $url = '' === $slug ? $base . '/' : $base . $slug;
            if (isset($seenUrls[$url])) {
                continue;
            }
            $seenUrls[$url] = true;
            $title = trim((string) ($page['title'] ?? ''));
            if ('' === $title) {
                continue;
            }
            $description = trim((string) ($page['description'] ?? $page['abstract'] ?? ''));
            $line = '- [' . $title . '](' . $url . ')';
            if ('' !== $description) {
                $line .= ': ' . $description;
            }
            $lines[] = $line;
        }
        $lines[] = '';
    }

    /**
     * Group all reachable pages by top-level hub (= direct child of root with
     * its own subtree). Pages directly under root that are not themselves
     * hubs go into the "main" bucket. Pages whose subtree-root is listed in
     * $optionalParents go into the "optional" bucket.
     *
     * @param list<array<string, mixed>> $pages
     * @param array<int, int> $pidMap UID → pid for all non-deleted pages (used to walk through nav-hidden / sysfolder ancestors)
     * @param list<int> $optionalParents
     * @return array{hubs: list<array{label: string, hub: array<string, mixed>, pages: list<array<string, mixed>>}>, main: list<array<string, mixed>>, optional: list<array<string, mixed>>}
     */
    private function groupPagesByHub(int $rootPageId, array $pages, array $pidMap, array $optionalParents, string $mainGroupLabel): array
    {
        // Identify direct children of root that are themselves visible — those
        // become candidate hubs. We use $pages (visible) for the title lookup
        // but $pidMap (full tree) for traversal of nav-hidden intermediates.
        $directChildren = [];
        foreach ($pages as $row) {
            if ((int) $row['pid'] === $rootPageId) {
                $directChildren[(int) $row['uid']] = $row;
            }
        }
        // A hub is a direct visible child that has at least one descendant
        // (anywhere down the tree, via $pidMap) which itself appears in $pages.
        $hasDescendant = [];
        foreach ($pages as $row) {
            $ancestor = $this->findTopLevelAncestor((int) $row['uid'], $rootPageId, $pidMap);
            if ($ancestor > 0 && isset($directChildren[$ancestor]) && (int) $row['uid'] !== $ancestor) {
                $hasDescendant[$ancestor] = true;
            }
        }

        $hubs = [];
        $main = [];
        $optional = [];
        foreach ($pages as $row) {
            $uid = (int) $row['uid'];
            if ($uid === $rootPageId) {
                $main[] = $row; // Root page itself
                continue;
            }
            $ancestor = $this->findTopLevelAncestor($uid, $rootPageId, $pidMap);
            if ($ancestor > 0 && in_array($ancestor, $optionalParents, true)) {
                $optional[] = $row;
                continue;
            }
            if ($ancestor > 0 && isset($hasDescendant[$ancestor])) {
                $hubs[$ancestor] ??= [
                    'label' => trim((string) ($directChildren[$ancestor]['title'] ?? 'Section')),
                    'hub' => $directChildren[$ancestor],
                    'pages' => [],
                ];
                // Skip the hub itself from its own list (it's the section heading).
                if ($uid !== $ancestor) {
                    $hubs[$ancestor]['pages'][] = $row;
                }
                continue;
            }
            // Visible direct child of root without subtree, or page whose
            // ancestor chain is broken (orphan) → main group
            $main[] = $row;
        }

        // Preserve sorting from $pages (sorted by `sorting ASC` in SQL).
        return [
            'hubs' => array_values($hubs),
            'main' => $main,
            'optional' => $optional,
        ];
    }

    /**
     * Walk up the pid chain until reaching a page whose pid equals
     * $rootPageId — that page is the top-level ancestor (= hub candidate).
     * Uses the full pid map so traversal works through nav-hidden ancestors
     * (year folders, sysfolders) that are excluded from the visible-pages set.
     *
     * @param array<int, int> $pidMap
     */
    private function findTopLevelAncestor(int $pageUid, int $rootPageId, array $pidMap): int
    {
        $current = $pageUid;
        $maxDepth = 20;
        while ($maxDepth-- > 0 && $current > 0) {
            if (!isset($pidMap[$current])) {
                return 0;
            }
            $pid = $pidMap[$current];
            if ($pid === $rootPageId) {
                return $current;
            }
            $current = $pid;
        }
        return 0;
    }

    /**
     * Coerce a settings value into a list of integer page UIDs.
     * Accepts: array of strings/ints, comma-separated string, single value.
     *
     * @param mixed $value
     * @return list<int>
     */
    private function normaliseUidList(mixed $value): array
    {
        if (is_string($value)) {
            $parts = preg_split('/[\s,]+/', $value);
            $value = $parts === false ? [] : $parts;
        }
        if (!is_array($value)) {
            return [];
        }
        $uids = [];
        foreach ($value as $item) {
            $uid = (int) $item;
            if ($uid > 0) {
                $uids[] = $uid;
            }
        }
        return array_values(array_unique($uids));
    }

    /**
     * Render the llms-full.md — a concatenated Markdown dump of every
     * visible page. Each page is rendered as a section with its title, URL,
     * description, and content elements (best-effort plain-text extraction).
     */
    /**
     * Builds the markdown representation of a single page for content
     * negotiation (Accept: text/markdown). Returns empty string when the
     * page has no useful content to render.
     *
     * Layout matches the per-page sections in `buildLlmsFullMd` so a crawler
     * that consumes both endpoints sees a consistent structure:
     *   # Page Title
     *   <https://...canonical url>
     *   > description (if present)
     *   ### CE header
     *   ce body text
     */
    public function buildPageMarkdown(SiteContext $context, int $pageUid, int $languageId = 0): string
    {
        if ($pageUid <= 0) {
            return '';
        }

        $page = $this->pageTree->loadVisiblePage($pageUid);
        if (null === $page) {
            return '';
        }

        // Skip non-content pages (sysfolders, separator, mountpoint etc.)
        $doktype = (int) ($page['doktype'] ?? 0);
        if (!in_array($doktype, [1, 2, 4], true)) {
            return '';
        }

        $base = rtrim($context->siteBase, '/');
        $slug = (string) ($page['slug'] ?? '');
        $url = '' === $slug ? $base . '/' : $base . $slug;
        $title = trim((string) ($page['title'] ?? ''));

        $lines = [];
        if ('' !== $title) {
            $lines[] = '# ' . $title;
            $lines[] = '';
        }
        $lines[] = '<' . $url . '>';
        $lines[] = '';

        $description = trim((string) ($page['description'] ?? $page['abstract'] ?? ''));
        if ('' !== $description) {
            $lines[] = '> ' . $description;
            $lines[] = '';
        }

        $body = $this->pageTree->renderPageBody($pageUid);
        if ('' !== $body) {
            $lines[] = $body;
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    public function buildLlmsFullMd(SiteContext $context): string
    {
        $languageId = $context->languageId;

        $settings = $context->settings;
        $sc = $settings['structuredContent'] ?? [];
        $llms = is_array($sc) ? ($sc['llmsTxt'] ?? []) : [];

        if (is_array($llms) && false === (bool) ($llms['fullDumpEnabled'] ?? true)) {
            return '';
        }

        $maxPages = is_array($llms) ? (int) ($llms['fullDumpMaxPages'] ?? 100) : 100;
        if ($maxPages <= 0) {
            $maxPages = 100;
        }

        $base = rtrim($context->base, '/');
        $title = $this->resolveSiteTitle($context);

        $lines = [];
        $lines[] = '# ' . $title . ($languageId === 0 ? ' — Vollständiger Inhalt' : ' — Full Content');
        $lines[] = '';
        $lines[] = '> ' . ($languageId === 0
            ? 'Automatisch generierter Markdown-Dump aller öffentlichen Seiten zum Zeitpunkt: '
            : 'Auto-generated Markdown dump of all public pages, snapshot at: ')
            . new \DateTimeImmutable()->format('c');
        $lines[] = '';

        $pages = $this->pageTree->loadVisiblePages($context->rootPageId, $languageId, $maxPages);

        foreach ($pages as $page) {
            $slug = (string) ($page['slug'] ?? '');
            $url = '' === $slug ? $base . '/' : $base . $slug;
            $pageTitle = trim((string) ($page['title'] ?? ''));
            if ('' === $pageTitle) {
                continue;
            }

            $lines[] = '---';
            $lines[] = '';
            $lines[] = '## ' . $pageTitle;
            $lines[] = '';
            $lines[] = '<' . $url . '>';
            $lines[] = '';

            $description = trim((string) ($page['description'] ?? $page['abstract'] ?? ''));
            if ('' !== $description) {
                $lines[] = $description;
                $lines[] = '';
            }

            $body = $this->pageTree->renderPageBody((int) $page['uid'], $languageId);
            if ('' !== $body) {
                $lines[] = $body;
                $lines[] = '';
            }
        }

        return implode("\n", $lines);
    }

}
