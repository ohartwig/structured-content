<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\AuthoredPagesRepositoryInterface;
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

        return $qb->select('uid', 'pid', 'title', 'slug', 'description', 'abstract', 'tstamp', 'crdate', 'lastUpdated')
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
            // Newest publication first: crdate is the date the BlogPosting
            // node gives as datePublished. tstamp listed the most recently
            // edited pages instead, so fixing a typo in an old post moved it
            // to the top. The uid keeps posts of the same second in a stable
            // order.
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
