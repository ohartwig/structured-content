<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\PageTreeRepositoryInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die fuenf Seitenbaum-Abfragen, wortgleich aus LlmsTxtGeneratorService hierher
 * verschoben.
 *
 * Wortgleich ist Absicht. In isPubliclyReachable() steckt der Audit-Befund M4;
 * eine Klasse mit Sicherheitsbezug beim Umzug gleich mit umzuschreiben macht
 * aus einem nachvollziehbaren Diff einen, den niemand mehr pruefen kann. Die
 * Verweise auf TYPO3 und Doctrine stehen hier richtig: Infrastructure darf laut
 * Regelsatz auf Framework zeigen, Application nicht.
 */
final readonly class PageTreeRepository implements PageTreeRepositoryInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Load uid → pid for every non-deleted page. Used to traverse through
     * nav-hidden intermediates (year folders, sysfolders) that loadVisiblePages
     * filters out but which still form valid ancestry chains.
     *
     * @return array<int, int>
     */
    public function loadPidMap(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $qb->select('uid', 'pid')
            ->from('pages')
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['uid']] = (int) $row['pid'];
        }
        return $map;
    }

    /**
     * Load uid → sorting for every non-deleted page, for the page-tree order
     * of the llms.txt featured section. Unfiltered like loadPidMap(): the
     * order runs through nav-hidden folders too.
     *
     * @return array<int, int>
     */
    public function loadSortingMap(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $qb->select('uid', 'sorting')
            ->from('pages')
            ->executeQuery()
            ->fetchAllAssociative();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['uid']] = (int) $row['sorting'];
        }
        return $map;
    }

    /**
     * UIDs of the default-language pages flagged for the llms.txt featured
     * section (`tx_structuredcontent_llms_featured`), DeletedRestriction only
     * — visibility is decided by intersecting with loadVisiblePages().
     *
     * A query of its own, and tolerant of the column not existing yet: the
     * extension can be deployed before the database schema update, and then
     * this query — and only this one — hits an unknown column. That is the
     * one DBAL exception caught here; the featured section is then simply
     * empty and the rest of the llms.txt renders as before. Folding the flag
     * into the main page query would take the whole file down with it.
     *
     * @return list<int>
     */
    public function loadFeaturedPageUids(): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        try {
            $rows = $qb->select('uid')
                ->from('pages')
                ->where(
                    $qb->expr()->eq('tx_structuredcontent_llms_featured', $qb->createNamedParameter(1, ParameterType::INTEGER)),
                    $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (InvalidFieldNameException) {
            return [];
        }

        $uids = [];
        foreach ($rows as $row) {
            $uids[] = (int) $row['uid'];
        }
        return $uids;
    }

    /**
     * Load visible pages for the given language. For non-default languages,
     * default-language records are overlaid with translated title/slug/
     * description from sys_language_uid=$languageId records (joined via
     * l10n_parent). The returned `uid` stays the default-language UID so
     * downstream pid-chain logic continues to work; the translated UID is
     * stashed in `_translatedUid` for reference.
     *
     * @return list<array<string, mixed>>
     */
    public function loadVisiblePages(int $rootPageId, int $languageId = 0, int $limit = 0): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        // `crdate` orders long post lists newest first in the llms.txt — the
        // same key the blog's own feed and ticker sort by. The featured flag
        // is deliberately NOT selected here: see loadFeaturedPageUids().
        $query = $qb
            ->select('uid', 'pid', 'title', 'slug', 'description', 'abstract', 'doktype', 'crdate')
            ->from('pages')
            ->where(
                $qb->expr()->in('doktype', $qb->createNamedParameter([1, 2, 4], ArrayParameterType::INTEGER)),
                $qb->expr()->eq('nav_hide', $qb->createNamedParameter(0, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC');

        if ($limit > 0) {
            $query = $query->setMaxResults($limit);
        }

        $rows = $query->executeQuery()->fetchAllAssociative();

        // Overlay translated title/slug/description for non-default languages.
        // Pages without a translation keep the default-language values (matches
        // TYPO3's fallback behaviour for fallbackType=fallback). The caller can
        // detect untranslated pages via the missing `_translatedUid` key.
        if ($languageId > 0 && [] !== $rows) {
            $defaultUids = array_map(static fn(array $r): int => (int) $r['uid'], $rows);

            $tQb = $this->connectionPool->getQueryBuilderForTable('pages');
            $tQb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
            $translations = $tQb
                ->select('uid', 'l10n_parent', 'title', 'slug', 'description', 'abstract')
                ->from('pages')
                ->where(
                    $tQb->expr()->eq('sys_language_uid', $tQb->createNamedParameter($languageId, ParameterType::INTEGER)),
                    $tQb->expr()->in('l10n_parent', $tQb->createNamedParameter($defaultUids, ArrayParameterType::INTEGER)),
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $byParent = [];
            foreach ($translations as $t) {
                $byParent[(int) $t['l10n_parent']] = $t;
            }

            foreach ($rows as &$row) {
                $uid = (int) $row['uid'];
                if (isset($byParent[$uid])) {
                    $t = $byParent[$uid];
                    $row['_translatedUid'] = (int) $t['uid'];
                    $row['title'] = trim((string) ($t['title'] ?? '')) !== '' ? $t['title'] : $row['title'];
                    $row['slug'] = trim((string) ($t['slug'] ?? '')) !== '' ? $t['slug'] : $row['slug'];
                    $row['description'] = trim((string) ($t['description'] ?? '')) !== '' ? $t['description'] : $row['description'];
                    $row['abstract'] = trim((string) ($t['abstract'] ?? '')) !== '' ? $t['abstract'] : $row['abstract'];
                }
            }
            unset($row);
        }

        // Filter to pages that are reachable from the rootPage (rootline check)
        $reachable = [];
        foreach ($rows as $row) {
            if ($this->isPubliclyReachable((int) $row['uid'], $rootPageId)) {
                $reachable[] = $row;
            }
        }

        return $reachable;
    }

    /**
     * Walk the rootline from $pageUid up to $rootPageId and verify EACH
     * ancestor against the public-access criteria. The leaf page itself is
     * already filtered by FrontendRestrictionContainer in loadVisiblePages(),
     * but that container only inspects the leaf's own row — it cannot see
     * `extendToSubpages` flags on parents.
     *
     * SECURITY (audit M4): TYPO3 supports per-page access constraints
     * (hidden, starttime/endtime, fe_group) and a flag `extendToSubpages`
     * that propagates the constraint to all descendants. A page that is
     * itself unrestricted but lives under a parent with hidden=1 +
     * extendToSubpages=1 is NOT publicly accessible. The original walk only
     * applied DeletedRestriction to the parent rows, so hidden / scheduled /
     * fe_group-gated subtrees were dumped verbatim into the public
     * llms-full.md including their full bodytext. Read also as
     * PageRepository::getPage() — Core does the same check; this is the
     * llms.txt-specific re-implementation.
     */
    public function isPubliclyReachable(int $pageUid, int $rootPageId): bool
    {
        if ($pageUid === $rootPageId) {
            return true;
        }
        if ($pageUid <= 0) {
            return false;
        }

        $now = (int) GeneralUtility::makeInstance(\TYPO3\CMS\Core\Context\Context::class)
            ->getPropertyFromAspect('date', 'timestamp', 0);

        $current = $pageUid;
        $maxDepth = 20;
        while ($maxDepth-- > 0 && $current > 0) {
            $qb = $this->connectionPool->getQueryBuilderForTable('pages');
            $qb->getRestrictions()->removeAll()->add(new DeletedRestriction());
            $row = $qb
                ->select('pid', 'hidden', 'starttime', 'endtime', 'fe_group', 'extendToSubpages')
                ->from('pages')
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($current, ParameterType::INTEGER)))
                ->executeQuery()
                ->fetchAssociative();
            if (false === $row) {
                return false;
            }
            $pid = (int) $row['pid'];

            // Ancestor restrictions only apply when extendToSubpages=1 — the
            // current node was already checked against FrontendRestrictionContainer
            // in loadVisiblePages(), so we don't recheck its own row.
            if ($current !== $pageUid && 1 === (int) ($row['extendToSubpages'] ?? 0)) {
                if (1 === (int) ($row['hidden'] ?? 0)) {
                    return false;
                }
                $starttime = (int) ($row['starttime'] ?? 0);
                if ($starttime > 0 && (0 === $now || $starttime > $now)) {
                    return false;
                }
                $endtime = (int) ($row['endtime'] ?? 0);
                if ($endtime > 0 && (0 === $now || $endtime < $now)) {
                    return false;
                }
                // Any non-empty fe_group other than "0" (= everyone) on an
                // anonymous-facing dump means the subtree is access-gated. We
                // cannot resolve groups for an anonymous request — the
                // llms.txt endpoint is public — so reject conservatively.
                $feGroupRaw = (string) ($row['fe_group'] ?? '');
                if ('' !== $feGroupRaw && '0' !== $feGroupRaw) {
                    return false;
                }
            }

            if ($pid === $rootPageId) {
                return true;
            }
            if (0 === $pid) {
                return false;
            }
            $current = $pid;
        }

        return false;
    }

    /**
     * Single-page counterpart of loadVisiblePages(): same column list, same
     * FrontendRestrictionContainer, one row instead of a subtree. Serves
     * buildPageMarkdown(), which renders a page on demand rather than walking
     * the tree.
     *
     * No rootline check here — the caller renders exactly the page the request
     * already resolved to, so its ancestry was decided by TYPO3's own routing.
     * That matches what the service did before the move.
     *
     * @return array<string, mixed>|null
     */
    public function loadVisiblePage(int $pageUid): ?array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $page = $qb
            ->select('uid', 'title', 'slug', 'description', 'abstract', 'doktype')
            ->from('pages')
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $page ? null : $page;
    }

    public function renderPageBody(int $pageUid, int $languageId = 0): string
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        // For non-default languages, query the translated content elements
        // whose default-language record sits on $pageUid. l18n_parent points
        // at the default-language CE; pid stays on the default page.
        $rows = $qb
            ->select('header', 'bodytext', 'CType')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        // For non-default languages with no translated CEs at all, fall back to
        // the default-language content so the page isn't blank in the dump.
        if ($languageId > 0 && [] === $rows) {
            $fb = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $fb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
            $rows = $fb
                ->select('header', 'bodytext', 'CType')
                ->from('tt_content')
                ->where(
                    $fb->expr()->eq('pid', $fb->createNamedParameter($pageUid, ParameterType::INTEGER)),
                    $fb->expr()->eq('sys_language_uid', $fb->createNamedParameter(0, ParameterType::INTEGER)),
                )
                ->orderBy('sorting', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        }

        $parts = [];
        foreach ($rows as $row) {
            $header = trim((string) ($row['header'] ?? ''));
            if ('' !== $header) {
                $parts[] = '### ' . $header;
            }
            $bodytext = trim((string) ($row['bodytext'] ?? ''));
            if ('' === $bodytext) {
                continue;
            }
            // Decode entities, normalise breaks, then strip tags.
            $bodytext = html_entity_decode($bodytext, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $bodytext = (string) preg_replace('/<br\s*\/?>/i', "\n", $bodytext);
            $bodytext = (string) preg_replace('/<\/(p|div|h[1-6]|li|tr|td|th|blockquote)>/i', "\n", $bodytext);
            $bodytext = strip_tags($bodytext);
            $bodytext = (string) preg_replace('/\n{3,}/', "\n\n", $bodytext);
            $parts[] = trim($bodytext);
        }

        return implode("\n\n", array_filter($parts, static fn(string $p): bool => '' !== $p));
    }
}
