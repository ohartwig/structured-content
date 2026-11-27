<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\AuthoredPagesRepositoryInterface;
use Koh\StructuredContent\Domain\Model\PageDates;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class DoctrineAuthoredPagesRepository implements AuthoredPagesRepositoryInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findByAuthorUid(int $personUid, int $languageId = 0, int $limit = 50): array
    {
        if ($personUid <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('pages');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $pages = $qb->select('uid', 'pid', 'title', 'slug', 'description', 'abstract', 'tstamp', 'crdate', 'lastUpdated', 'starttime', 'SYS_LASTCHANGED')
            ->from('pages')
            ->where(
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageId, ParameterType::INTEGER),
                ),
                $qb->expr()->eq(
                    'doktype',
                    $qb->createNamedParameter(1, ParameterType::INTEGER),
                ),
                $qb->expr()->or(
                    $qb->expr()->like(
                        'tx_structuredcontent_author_records',
                        $qb->createNamedParameter('%,' . $personUid . ',%'),
                    ),
                    $qb->expr()->like(
                        'tx_structuredcontent_author_records',
                        $qb->createNamedParameter($personUid . ',%'),
                    ),
                    $qb->expr()->like(
                        'tx_structuredcontent_author_records',
                        $qb->createNamedParameter('%,' . $personUid),
                    ),
                    $qb->expr()->eq(
                        'tx_structuredcontent_author_records',
                        $qb->createNamedParameter((string) $personUid),
                    ),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Newest publication first, by the date the page is presented with
        // (PageDates: lastUpdated, else starttime, else crdate) — the date
        // its BlogPosting node gives as datePublished. tstamp listed the most
        // recently edited pages instead, so fixing a typo in an old post moved
        // it to the top. The fallback chain is no single column, so the order
        // is applied here; a person's pages are few enough to sort in memory.
        usort($pages, PageDates::newestFirst(...));

        return array_slice($pages, 0, max(0, $limit));
    }
}
