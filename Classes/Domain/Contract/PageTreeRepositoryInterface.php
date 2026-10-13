<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * Port fuer die Seitenbaum-Abfragen, die llms.txt und llms-full.md brauchen.
 *
 * Existiert, weil LlmsTxtGeneratorService in der Application-Schicht liegt und
 * der deptrac-Regelsatz dort ausschliesslich Domain erlaubt. Der Service hat
 * ConnectionPool, GeneralUtility, die Restriction-Container und
 * Doctrine\DBAL\ParameterType direkt benutzt — 35 gemeldete Verstoesse, alle in
 * dieser einen Klasse.
 *
 * Aufgefallen sind sie erst jetzt: deptrac 3.0.0 brachte nikic/php-parser v4
 * mit, der PHP 8.4 nicht parst und deshalb nichts gesehen hat. Mit 4.7.1 sind
 * sie sichtbar. Die Verstoesse selbst sind aelter.
 *
 * Die Methoden sind bewusst genau die fuenf Abfragen, die der Service vorher
 * selbst gefuehrt hat, mit identischem Verhalten. Ein Port, der die Gelegenheit
 * fuer eine "bessere" Schnittstelle nutzt, vermischt Umzug und Umbau — und bei
 * einer Klasse, in der ein Sicherheits-Audit-Befund steckt, ist das die falsche
 * Reihenfolge.
 */
interface PageTreeRepositoryInterface
{
    /**
     * uid => pid fuer ALLE Seiten, nur DeletedRestriction.
     *
     * Bewusst ohne Sichtbarkeitsfilter: die Karte dient dem Hochlaufen der
     * Rootline, und dabei muessen auch unsichtbare Vorfahren auftauchen —
     * sonst bricht der Weg nach oben ab und eine Seite gilt faelschlich als
     * nicht im Baum.
     *
     * @return array<int, int>
     */
    public function loadPidMap(): array;

    /**
     * uid => sorting for ALL pages, DeletedRestriction only.
     *
     * Unfiltered for the same reason as loadPidMap(): a page's position in
     * the tree depends on the sorting of its ancestors, and those include
     * nav-hidden folders that have no visible row.
     *
     * @return array<int, int>
     */
    public function loadSortingMap(): array;

    /**
     * uids of the default-language pages with
     * `tx_structuredcontent_llms_featured` set, DeletedRestriction only.
     *
     * An empty list when the column does not exist yet (deployed before the
     * database schema update) — then only the section is missing, not the
     * file.
     *
     * @return list<int>
     */
    public function loadFeaturedPageUids(): array;

    /**
     * Oeffentlich sichtbare Seiten unterhalb der Wurzel, in Sortierreihenfolge.
     *
     * @return list<array<string, mixed>>
     */
    public function loadVisiblePages(int $rootPageId, int $languageId = 0, int $limit = 0): array;

    /**
     * Prueft JEDEN Vorfahren gegen die Kriterien oeffentlicher Erreichbarkeit.
     *
     * Traegt den Audit-Befund M4: FrontendRestrictionContainer prueft nur die
     * Zeile der Blattseite und sieht `extendToSubpages` auf Elternseiten nicht.
     * Eine selbst unbeschraenkte Seite unter einem verborgenen Elternteil ist
     * NICHT oeffentlich erreichbar.
     */
    public function isPubliclyReachable(int $pageUid, int $rootPageId): bool;

    /**
     * Die Zeile EINER oeffentlich sichtbaren Seite, oder null.
     *
     * Was loadVisiblePages() fuer den ganzen Baum tut, tut dies fuer eine
     * einzelne uid: dieselbe Spaltenauswahl, derselbe
     * FrontendRestrictionContainer. Gebraucht von buildPageMarkdown(), das
     * eine Seite auf Zuruf abzieht, statt den Baum zu durchlaufen.
     *
     * @return array<string, mixed>|null
     */
    public function loadVisiblePage(int $pageUid): ?array;

    /**
     * Der gerenderte Textkoerper einer Seite in der gegebenen Sprache.
     */
    public function renderPageBody(int $pageUid, int $languageId = 0): string;
}
