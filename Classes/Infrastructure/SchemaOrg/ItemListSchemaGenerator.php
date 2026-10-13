<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Emits Schema.org ItemList for Card-Container-Blocks on Hub-Pages.
 *
 * Themen-Hubseiten haben Sections wie "Related Articles", "Open Source
 * Packages", "Kubernetes Patterns" — meistens als moselwal_cards/smallcards/
 * uspcards/teaser-CB realisiert. Pro Block emittieren wir eine ItemList
 * mit name aus dem Block-Header und itemListElement aus den verknuepften
 * moselwal_card-Records (header + cta_link.url).
 *
 * Bloecke ohne Header werden uebersprungen — leere ItemList ist schlechter
 * als gar keine.
 */
final class ItemListSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    /**
     * CType-Whitelist fuer ItemList-Generierung. Andere Card-CBs (z.B.
     * pain-cards/usp-cards aus moselwal-de-site) sind editorial, nicht
     * Item-orientiert — bewusst nicht drin.
     *
     * @var list<string>
     */
    private const CARD_CTYPES = [
        'moselwal_cards',
        'moselwal_smallcards',
        'moselwal_smallcardscarousel',
        'moselwal_teaser',
    ];

    /**
     * Core-TYPO3-Menu-CTypes — rendern Listen von Pages, ideale ItemList-
     * Kandidaten (Use-Case: "Verwandte Beiträge" auf Blog-Detail-Pages
     * als menu_pages-CB, Subpage-Übersichten als menu_subpages).
     *
     * @var list<string>
     */
    private const MENU_CTYPES = [
        'menu_pages',
        'menu_subpages',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $isListingPage = $this->isListingPage($pageData);
        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            $header = trim((string) ($element['header'] ?? ''));
            if ('' === $header) {
                continue;
            }
            if (in_array($cType, self::CARD_CTYPES, true)) {
                return true;
            }
            // Menu-CBs (Verwandte-Beitraege-Pattern) NUR auf Detail-Pages
            // emittieren. Auf der eigentlichen Listing-Page (/blog, /news,
            // /portfolio) hat WebPageSchemaGenerator schon CollectionPage
            // mit mainEntity:ItemList — ein zweites ItemList parallel waere
            // doppelt und bloat-y (50+ Blog-Posts ins JSON-LD).
            if (in_array($cType, self::MENU_CTYPES, true) && !$isListingPage) {
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
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $languageId = (int) ($pageData['_languageId'] ?? 0);
        $isListingPage = $this->isListingPage($pageData);
        $lists = [];
        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            $isCard = in_array($cType, self::CARD_CTYPES, true);
            $isMenu = in_array($cType, self::MENU_CTYPES, true);
            if (!$isCard && (!$isMenu || $isListingPage)) {
                continue;
            }
            $list = $isMenu
                ? $this->buildItemListForMenuBlock($element, $siteBase, $languageId)
                : $this->buildItemListForBlock($element, $siteBase, $languageId);
            if (null !== $list) {
                $lists[] = $list;
            }
        }

        if ([] === $lists) {
            return new JsonLdDocument('ItemList', []);
        }
        if (1 === count($lists)) {
            return new JsonLdDocument('ItemList', $lists[0]);
        }

        // Mehrere Card-Bloecke auf einer Page → @graph mit allen ItemLists.
        return new JsonLdDocument('ItemList', [
            '@graph' => $lists,
        ]);
    }

    public function getPriority(): int
    {
        return 35;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>|null
     */
    private function buildItemListForBlock(array $element, string $siteBase, int $languageId): ?array
    {
        $header = trim((string) ($element['header'] ?? ''));
        if ('' === $header) {
            return null;
        }

        $items = $this->loadCardItems((int) ($element['uid'] ?? 0), $siteBase, $languageId);

        $list = [
            '@type' => 'ItemList',
            'name' => $this->sanitizeText($header),
        ];

        $subheader = trim((string) ($element['subheader'] ?? ''));
        if ('' !== $subheader) {
            $list['description'] = $this->sanitizeText($subheader);
        }

        if ([] !== $items) {
            $list['numberOfItems'] = count($items);
            $list['itemListElement'] = $items;
        }

        return $list;
    }

    /**
     * Laedt moselwal_card-Records des angegebenen tt_content-Parents und
     * konvertiert sie zu Schema.org-ListItem-Eintraegen mit position,
     * name + url (wenn cta_link.url gepflegt).
     *
     * @return list<array<string, mixed>>
     */
    private function loadCardItems(int $parentUid, string $siteBase, int $languageId): array
    {
        if ($parentUid <= 0) {
            return [];
        }
        // Content-Blocks-Konvention: Fields bekommen den Table-Prefix
        // (moselwal_card.moselwal_card_header statt nur .header). Ohne
        // Prefix knallt der Query mit "Unknown column 'header'" auf
        // jeder Page mit Card-CB → 503 fuer die ganze Page.
        //
        // Die Default-Sprache liefert Reihenfolge und Bestand: die Karten
        // einer Uebersetzung sind Overlays, kein eigener Satz. Die Texte
        // kommen danach aus der Uebersetzung (withTranslations).
        $qb = $this->connectionPool->getQueryBuilderForTable('moselwal_card');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $rows = $qb
            ->select('uid', 'moselwal_card_header', 'moselwal_card_cta_link', 'moselwal_card_cta_label')
            ->from('moselwal_card')
            ->where(
                $qb->expr()->eq('foreign_table_parent_uid', $qb->createNamedParameter($parentUid, ParameterType::INTEGER)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $rows = self::withTranslations(
            $rows,
            $this->loadTranslations(
                'moselwal_card',
                ['moselwal_card_header', 'moselwal_card_cta_link', 'moselwal_card_cta_label'],
                array_map(static fn(array $r): int => (int) ($r['uid'] ?? 0), $rows),
                $languageId,
            ),
            self::translationPointerField('moselwal_card'),
        );

        $items = [];
        $position = 0;
        foreach ($rows as $row) {
            $name = trim((string) ($row['moselwal_card_header'] ?? ''));
            if ('' === $name) {
                continue;
            }
            ++$position;
            $item = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $this->sanitizeText($name),
            ];
            $url = $this->resolveCtaUrl((string) ($row['moselwal_card_cta_link'] ?? ''), $siteBase);
            if ('' !== $url) {
                $item['url'] = $url;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * TYPO3-Link-Field-Werte koennen folgende Formate haben:
     * - "t3://page?uid=42" (interne Page-Ref)
     * - "https://...." (absolute URL)
     * - "/relative/path" (Site-relative)
     *
     * Fuer Schema.org wandeln wir auf absolute URLs um. t3://page-Refs werden
     * hier NICHT aufgeloest (das braeuchte den TypolinkBuilder mit
     * Request-Context) — der Generator emittiert dann keinen url-Eintrag,
     * besser kein url als ein t3://-Pseudo-URL der Crawler verwirrt.
     */
    private function resolveCtaUrl(string $raw, string $siteBase): string
    {
        $url = trim($raw);
        if ('' === $url) {
            return '';
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        if (str_starts_with($url, '/') && '' !== $siteBase) {
            return $siteBase . $url;
        }

        return '';
    }

    /**
     * Laedt die Uebersetzungen der genannten Default-Records.
     *
     * Leere Liste, wenn die Seite in der Default-Sprache laeuft — dann gibt
     * es nichts zu ueberlagern.
     *
     * @param list<string> $fields    zu uebersetzende Felder, ohne uid/l10n_parent
     * @param list<int>    $parentUids
     *
     * @return list<array<string, mixed>>
     */
    private function loadTranslations(string $table, array $fields, array $parentUids, int $languageId): array
    {
        if ($languageId <= 0 || [] === $parentUids || [] === $fields) {
            return [];
        }
        $transField = self::translationPointerField($table);
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        return $qb
            ->select($transField, ...$fields)
            ->from($table)
            ->where(
                $qb->expr()->in($transField, $qb->createNamedParameter($parentUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Das Feld, das eine Uebersetzung auf ihre Standardzeile zeigen laesst.
     *
     * Aus der TCA, wie FaqSchemaGenerator und PersonSchemaGenerator es auch
     * lesen: die Content-Blocks-Tabellen nutzen `l10n_parent`, aeltere
     * Tabellen das historische `l18n_parent`. Der Default deckt den Fall ab,
     * dass die TCA im Unit-Test gar nicht geladen ist.
     */
    private static function translationPointerField(string $table): string
    {
        $field = (string) ($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');

        return '' !== $field ? $field : 'l10n_parent';
    }

    /**
     * Legt Uebersetzungen ueber die Default-Zeilen, in deren Reihenfolge.
     *
     * Bis zum 23.09.2026 gab es das nicht: die Generatoren fragten immer
     * `sys_language_uid = 0` und reichten die Sprache zwar durch, benutzten
     * sie aber nirgends. Auf jeder englischen Seite standen deshalb die
     * deutschen Kartennamen im JSON-LD — sichtbar nur im Quelltext, und
     * damit genau dort, wo ausser Crawlern niemand hinsieht.
     *
     * Zwei Regeln, beide absichtlich:
     *
     *   - Ohne Uebersetzung bleibt die Default-Zeile stehen. Ein fehlendes
     *     Overlay ist kein Grund, den Eintrag aus der Liste zu werfen.
     *   - Ein leeres Feld der Uebersetzung zaehlt als "nicht uebersetzt",
     *     nicht als "absichtlich leer". Ein leerer Slug wuerde sonst eine
     *     URL auf die Site-Basis erzeugen.
     *
     * @param list<array<string, mixed>> $defaults     Zeilen der Default-Sprache, je mit uid
     * @param list<array<string, mixed>> $translations Zeilen der Zielsprache, je mit Zeiger auf die Standardzeile
     *
     * @return list<array<string, mixed>>
     */
    private static function withTranslations(array $defaults, array $translations, string $parentField = 'l10n_parent'): array
    {
        if ([] === $translations) {
            return $defaults;
        }
        $byParent = [];
        foreach ($translations as $row) {
            $parent = (int) ($row[$parentField] ?? 0);
            if ($parent > 0) {
                $byParent[$parent] = $row;
            }
        }

        $out = [];
        foreach ($defaults as $row) {
            $translated = $byParent[(int) ($row['uid'] ?? 0)] ?? null;
            if (null !== $translated) {
                foreach ($translated as $field => $value) {
                    if ('uid' === $field || $field === $parentField) {
                        continue;
                    }
                    if (null === $value || '' === trim((string) $value)) {
                        continue;
                    }
                    $row[$field] = $value;
                }
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Heuristik fuer Listing-Pages (CollectionPage-Kandidaten): Pages mit
     * manueller schema_type=CollectionPage ODER Slug-Pattern das auf eine
     * Top-Level-Listing-Section deutet (z.B. /blog, /news, /portfolio).
     *
     * Auf solchen Pages emittiert WebPageSchemaGenerator schon ein
     * CollectionPage-Schema mit mainEntity:ItemList. Ein zusaetzliches
     * ItemList vom menu_*-CB waere doppelt — der Generator skipped dann
     * den Menu-Block.
     *
     * Detail-Pages (/blog/<slug>, /news/<slug>) sind KEINE Listing-Pages
     * im Sinne dieser Heuristik — dort ist das Menu-CB ein "Verwandte
     * Beitraege"-Pattern, und ein ItemList macht Sinn.
     *
     * @param array<string, mixed> $pageData
     */
    private function isListingPage(array $pageData): bool
    {
        $manual = strtolower(trim((string) ($pageData['tx_structuredcontent_schema_type'] ?? '')));
        if ('collectionpage' === $manual) {
            return true;
        }

        $slug = trim((string) ($pageData['slug'] ?? ''));
        if ('' === $slug) {
            return false;
        }
        // Stamm-Listing-Pages mit Slug ohne weitere Sub-Segmente. /blog,
        // /news, /portfolio etc. Detail-Pages /blog/<slug> haben ein
        // zweites Segment und sind damit ausgeschlossen.
        return 1 === preg_match(
            '~^/(blog|news|press|presse|portfolio|referenz[a-z]*|glossar|autor|podcasts?)/?$~i',
            $slug,
        );
    }

    /**
     * ItemList aus einem Core-TYPO3 Menu-CB (menu_pages, menu_subpages).
     * tt_content.pages enthaelt die referenzierten Page-UIDs als CSV; bei
     * menu_subpages sind das die Parent-Pages, deren Children aufgelistet
     * werden, bei menu_pages sind es direkt die zu listenden Pages.
     *
     * @param array<string, mixed> $element
     *
     * @return array<string, mixed>|null
     */
    private function buildItemListForMenuBlock(array $element, string $siteBase, int $languageId): ?array
    {
        $header = trim((string) ($element['header'] ?? ''));
        if ('' === $header) {
            return null;
        }
        $cType = strtolower((string) ($element['CType'] ?? ''));
        $rawPages = (string) ($element['pages'] ?? '');
        $pageUids = array_values(array_filter(
            array_map('intval', explode(',', $rawPages)),
            static fn(int $uid): bool => $uid > 0,
        ));
        if ([] === $pageUids) {
            return null;
        }

        $resolvedUids = 'menu_subpages' === $cType
            ? $this->resolveSubpageUids($pageUids, $languageId)
            : $pageUids;
        $items = $this->buildItemsFromPageUids($resolvedUids, $siteBase, $languageId);

        $list = [
            '@type' => 'ItemList',
            'name' => $this->sanitizeText($header),
        ];
        if ([] !== $items) {
            $list['numberOfItems'] = count($items);
            $list['itemListElement'] = $items;
        }

        return $list;
    }

    /**
     * Resolved Children-Page-UIDs fuer eine Liste von Parent-UIDs
     * (menu_subpages-Pattern). Beachtet doktype=1 (Standard-Pages), keine
     * Spacer/Folder/Sysordner. Sortierung wie Page-Tree.
     *
     * @param list<int> $parentUids
     *
     * @return list<int>
     */
    private function resolveSubpageUids(array $parentUids, int $languageId): array
    {
        if ([] === $parentUids) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $rows = $qb->select('uid')
            ->from('pages')
            ->where(
                $qb->expr()->in('pid', $qb->createNamedParameter($parentUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
                $qb->expr()->eq('doktype', $qb->createNamedParameter(1, ParameterType::INTEGER)),
                $qb->expr()->eq('nav_hide', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $r): int => (int) $r['uid'], $rows);
    }

    /**
     * Laedt Page-Records (uid, title, slug) und baut ListItem-Eintraege.
     *
     * @param list<int> $pageUids
     *
     * @return list<array<string, mixed>>
     */
    private function buildItemsFromPageUids(array $pageUids, string $siteBase, int $languageId): array
    {
        if ([] === $pageUids) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $rows = $qb->select('uid', 'title', 'slug', 'nav_title')
            ->from('pages')
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($pageUids, \Doctrine\DBAL\ArrayParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Titel UND Slug aus der Uebersetzung: die englische Seite heisst
        // anders und liegt unter einem anderen Pfad. Ohne das Overlay
        // entstuende aus englischer Basis und deutschem Slug eine URL, die
        // es nicht gibt.
        $rows = self::withTranslations(
            $rows,
            $this->loadTranslations('pages', ['title', 'slug', 'nav_title'], $pageUids, $languageId),
            self::translationPointerField('pages'),
        );

        // pages-Field-Order beibehalten — TYPO3-Editor erwartet die
        // Reihenfolge aus dem CSV-Field, nicht aus der DB-Reihenfolge.
        $byUid = [];
        foreach ($rows as $row) {
            $byUid[(int) $row['uid']] = $row;
        }

        $items = [];
        $position = 0;
        foreach ($pageUids as $uid) {
            $row = $byUid[$uid] ?? null;
            if (null === $row) {
                continue;
            }
            $title = trim((string) ($row['nav_title'] ?? ''));
            if ('' === $title) {
                $title = trim((string) ($row['title'] ?? ''));
            }
            if ('' === $title) {
                continue;
            }
            $slug = trim((string) ($row['slug'] ?? ''));
            ++$position;
            $item = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $this->sanitizeText($title),
            ];
            if ('' !== $slug && '' !== $siteBase) {
                $item['url'] = $siteBase . $slug;
            }
            $items[] = $item;
        }

        return $items;
    }
}
