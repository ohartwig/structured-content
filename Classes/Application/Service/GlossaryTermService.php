<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

use Koh\StructuredContent\Domain\Contract\GlossaryTermRepositoryInterface;

/**
 * Zentraler Service für die featdd/dpn-glossary-Integration.
 *
 * Dient als Conditional-Loading-Guard: `isAvailable()` MUSS von jedem
 * Glossary-Generator + der Middleware vor jedem DB-Zugriff aufgerufen
 * werden, damit das Paket auch ohne installierte dpn_glossary-Extension
 * fehlerfrei läuft (keine Queries auf nicht-existente Tabellen).
 *
 * Per-Request-Cache (Singleton): Terms werden für DefinedTerm-Generator,
 * DefinedTermSet-Generator und den Middleware-Body-Scan ein einziges Mal
 * geladen — bei dutzenden Glossar-Refs auf einer Page wäre N+1 sonst teuer.
 *
 * No SingletonInterface: the per-request caches below need exactly one
 * instance per request, and they still get it. Every consumer takes this
 * service through constructor injection and Symfony shares services by
 * default; the marker only matters for GeneralUtility::makeInstance(), which
 * nothing here uses. If that ever changes, the caches silently stop working —
 * so fetch this service from the container, not from makeInstance().
 *
 * Reading is delegated to the repository. What stays here is what makes this
 * an application service rather than a query: the per-request caches, the
 * site-settings lookup, and the shaping of stored values into what the schema
 * generators need.
 */
final class GlossaryTermService
{
    /** @var array<string, array<string, mixed>|null> */
    private array $termCacheBySlug = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $allTermsCache = [];

    /** @var array<int, string> Page-UID → resolved slug */
    private array $basePathCache = [];

    public function __construct(
        private readonly GlossaryTermRepositoryInterface $repository,
    ) {}

    /**
     * Bestimmt, ob die dpn_glossary-Extension verfuegbar ist. Genau EINE
     * Quelle der Wahrheit fuer den Conditional-Loading-Guard — wenn diese
     * Methode false zurueckgibt, MUSS jeder Caller den Glossar-Pfad
     * komplett skippen (keine DB-Queries, keine Generators).
     */
    public function isAvailable(): bool
    {
        return $this->repository->isAvailable();
    }

    /**
     * Resolved den site-relativen Basis-Pfad zur Glossar-Listing-Page
     * (z.B. "/glossar"). Site-Setting `dpn-glossary.glossaryPage` (Page-UID,
     * vom Site-Set featdd/dpn-glossary definiert) → daraus Slug aus
     * pages.slug. Fallback: '/glossar'.
     *
     * @param array<string, mixed> $pageData
     */
    public function resolveGlossaryBasePath(array $pageData): string
    {
        if (!$this->isAvailable()) {
            return '/glossar';
        }
        $detailPageUid = $this->resolveDetailPageUid($pageData);
        if (0 === $detailPageUid) {
            return '/glossar';
        }
        if (isset($this->basePathCache[$detailPageUid])) {
            return $this->basePathCache[$detailPageUid];
        }

        $resolved = $this->repository->findPageSlug($detailPageUid) ?? '/glossar';
        $this->basePathCache[$detailPageUid] = $resolved;

        return $resolved;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTermBySlug(string $slug, int $languageId = 0): ?array
    {
        if (!$this->isAvailable() || '' === $slug) {
            return null;
        }
        $cacheKey = $slug . '_' . $languageId;
        if (array_key_exists($cacheKey, $this->termCacheBySlug)) {
            return $this->termCacheBySlug[$cacheKey];
        }

        $result = $this->repository->findTermBySlug($slug, $languageId);
        $this->termCacheBySlug[$cacheKey] = $result;

        return $result;
    }

    /**
     * The term a detail URL points at. The Glossary route enhancer maps the
     * slug through a PersistedAliasMapper, so the routing argument is the uid
     * of the default-language record; a plain Extbase route passes the slug.
     * Both arrive here as a string.
     *
     * @return array<string, mixed>|null
     */
    public function findTerm(string $identifier, int $languageId = 0): ?array
    {
        if (!ctype_digit($identifier)) {
            return $this->findTermBySlug($identifier, $languageId);
        }
        if (!$this->isAvailable()) {
            return null;
        }
        $cacheKey = '#' . $identifier . '_' . $languageId;
        if (!array_key_exists($cacheKey, $this->termCacheBySlug)) {
            $this->termCacheBySlug[$cacheKey] = $this->repository->findTermByUid((int) $identifier, $languageId);
        }

        return $this->termCacheBySlug[$cacheKey];
    }

    /**
     * The definition of a term as plain text: the first paragraph of its
     * first description, without the run-in label that opens it. Used by the
     * DefinedTerm generator for Schema.org `description` and by the term
     * page's meta description.
     *
     * Descriptions are written as labelled paragraphs ("<strong>Definition.
     * </strong> …", "<strong>Why it matters.</strong> …"). Stripping the tags
     * alone turned the label into the first word of every description.
     */
    public function loadFirstDescription(int $termUid, int $languageId = 0): string
    {
        if (!$this->isAvailable() || $termUid <= 0) {
            return '';
        }

        $description = $this->repository->findFirstDescription($termUid, $languageId);
        if (null === $description || '' === $description) {
            return '';
        }

        return self::extractDefinition($description);
    }

    /**
     * The meta description of a term page: the editor's own meta description
     * first, then the definition, then the tooltip — shortened to fit a
     * search result snippet.
     */
    public function describeTerm(string $identifier, int $languageId = 0, int $maxLength = 155): string
    {
        $row = '' === $identifier ? null : $this->findTerm($identifier, $languageId);
        if (null === $row) {
            return '';
        }

        foreach ([
            static fn(): string => self::plainText((string) ($row['meta_description'] ?? '')),
            fn(): string => $this->loadFirstDescription((int) ($row['uid'] ?? 0), $languageId),
            static fn(): string => self::plainText((string) ($row['tooltiptext'] ?? '')),
        ] as $source) {
            $text = $source();
            if ('' !== $text) {
                return self::shorten($text, $maxLength);
            }
        }

        return '';
    }

    private static function extractDefinition(string $html): string
    {
        // A heading in front of the text is a label, not part of it.
        $html = preg_replace('#^\s*<h([1-6])\b[^>]*>.*?</h\1>#is', '', $html) ?? $html;

        $paragraphs = preg_split('#</p\s*>#i', $html);
        if (false === $paragraphs) {
            $paragraphs = [$html];
        }
        foreach ($paragraphs as $paragraph) {
            // A run-in label: bold text ending in "." or ":" at the start.
            $paragraph = preg_replace(
                '#^\s*(?:<p\b[^>]*>)?\s*(?:<(strong|b)\b[^>]*>[^<]{1,40}[.:]\s*</\1>|<(strong|b)\b[^>]*>[^<]{1,40}</\2>\s*[.:])#i',
                '',
                $paragraph,
            ) ?? $paragraph;
            // Checked after the conversion: a paragraph that held only a label
            // or markup is empty only once the tags are gone.
            $text = self::plainText($paragraph);
            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }

    private static function plainText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>|</(p|div|li|h[1-6])>#i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * Whole sentences if they fit, otherwise whole words and an ellipsis.
     */
    private static function shorten(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength);
        $sentenceEnd = max((int) mb_strrpos($cut, '. '), (int) mb_strrpos($cut, '! '), (int) mb_strrpos($cut, '? '));
        if ($sentenceEnd >= (int) ($maxLength / 2)) {
            return mb_substr($cut, 0, $sentenceEnd + 1);
        }

        $cut = mb_substr($cut, 0, $maxLength - 1);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space >= (int) ($maxLength / 2)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " ,;:-\u{2013}\u{2014}") . '…';
    }

    /**
     * Liefert Synonyme eines Terms als Plain-String-Liste, gefiltert nach
     * Sprache. Verwendet vom DefinedTerm-Generator fuer Schema.org
     * `alternateName` — gibt Crawlern alternative Bezeichnungen, ueber die
     * der Term gefunden werden kann (z.B. "AI Content Management System"
     * fuer den Term "AI-ready CMS").
     *
     * @return list<string>
     */
    public function loadSynonyms(int $termUid, int $languageId = 0): array
    {
        if (!$this->isAvailable() || $termUid <= 0) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), $this->repository->findSynonymNames($termUid, $languageId)),
            static fn(string $name): bool => '' !== $name,
        ));
    }

    /**
     * Alle Terms einer Sprache fuer den DefinedTermSet-Generator.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllTerms(int $languageId = 0): array
    {
        if (!$this->isAvailable()) {
            return [];
        }
        if (isset($this->allTermsCache[$languageId])) {
            return $this->allTermsCache[$languageId];
        }

        $rows = $this->repository->findAllTerms($languageId);
        $this->allTermsCache[$languageId] = $rows;

        return $rows;
    }

    /**
     * Prueft ob die aktuelle Page die Glossar-Listing-Page ist
     * (uid == dpn-glossary.glossaryPage-Setting). Genutzt vom
     * DefinedTermSet-Generator.
     *
     * @param array<string, mixed> $pageData
     */
    public function isGlossaryListingPage(array $pageData): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }
        $detailPageUid = $this->resolveDetailPageUid($pageData);
        if (0 === $detailPageUid) {
            return false;
        }
        $currentUid = (int) ($pageData['uid'] ?? 0);

        return $currentUid === $detailPageUid;
    }

    /**
     * dpn-glossary v7 nutzt Site-Sets-Settings. Settings-Key ist
     * `dpn-glossary.glossaryPage` (mit Bindestrich, NICHT camelCase) und
     * landet als flacher Top-Level-Key in $siteSettings.
     *
     * @param array<string, mixed> $pageData
     */
    private function resolveDetailPageUid(array $pageData): int
    {
        $settings = $pageData['_siteSettings'] ?? [];
        if (!is_array($settings)) {
            return 0;
        }

        return (int) ($settings['dpn-glossary.glossaryPage']
            ?? $settings['dpn-glossary']['glossaryPage']
            ?? 0);
    }
}
