<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\CategoryRepositoryInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class DoctrineCategoryRepository implements CategoryRepositoryInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findByPageUid(int $pageUid): array
    {
        if ($pageUid <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('sys_category');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $joinCondition = (string) $qb->expr()->and(
            $qb->expr()->eq('mm.uid_local', $qb->quoteIdentifier('c.uid')),
            $qb->expr()->eq('mm.tablenames', $qb->createNamedParameter('pages')),
            $qb->expr()->eq('mm.fieldname', $qb->createNamedParameter('categories')),
        );

        $rows = $qb->select('c.uid AS uid', 'c.title AS title', 'c.slug AS slug')
            ->from('sys_category', 'c')
            ->innerJoin('c', 'sys_category_record_mm', 'mm', $joinCondition)
            ->where(
                $qb->expr()->eq(
                    'mm.uid_foreign',
                    $qb->createNamedParameter($pageUid, ParameterType::INTEGER),
                ),
            )
            ->orderBy('c.sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'uid' => (int) $row['uid'],
                'title' => (string) $row['title'],
                'slug' => (string) ($row['slug'] ?? ''),
            ];
        }

        return $result;
    }
}
