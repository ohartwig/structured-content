<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Enum\RelationType;
use Koh\StructuredContent\Domain\Model\ContentRelation;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DoctrineRelationshipRepository implements RelationshipRepositoryInterface
{
    private const string TABLE = 'tx_structuredcontent_relation';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<ContentRelation>
     */
    public function findBySource(string $table, int $uid, ?RelationType $type = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'source_table',
                    $queryBuilder->createNamedParameter($table),
                ),
                $queryBuilder->expr()->eq(
                    'source_uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
            );

        if (null !== $type) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'relation_type',
                    $queryBuilder->createNamedParameter($type->value),
                ),
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map($this->mapRowToContentRelation(...), $rows);
    }

    /**
     * @return list<ContentRelation>
     */
    public function findBidirectionalByTarget(string $table, int $uid, ?RelationType $type = null): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'target_table',
                    $queryBuilder->createNamedParameter($table),
                ),
                $queryBuilder->expr()->eq(
                    'target_uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'direction',
                    $queryBuilder->createNamedParameter('bidirectional'),
                ),
            );

        if (null !== $type) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    'relation_type',
                    $queryBuilder->createNamedParameter($type->value),
                ),
            );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        return array_map($this->mapRowToContentRelation(...), $rows);
    }

    public function exists(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
    ): bool {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $count = $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'source_table',
                    $queryBuilder->createNamedParameter($sourceTable),
                ),
                $queryBuilder->expr()->eq(
                    'source_uid',
                    $queryBuilder->createNamedParameter($sourceUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'target_table',
                    $queryBuilder->createNamedParameter($targetTable),
                ),
                $queryBuilder->expr()->eq(
                    'target_uid',
                    $queryBuilder->createNamedParameter($targetUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'relation_type',
                    $queryBuilder->createNamedParameter($type->value),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $count > 0;
    }

    public function insert(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
        string $direction,
        float $confidence,
        string $createdBy,
    ): void {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(
            self::TABLE,
            [
                'source_table' => $sourceTable,
                'source_uid' => $sourceUid,
                'target_table' => $targetTable,
                'target_uid' => $targetUid,
                'relation_type' => $type->value,
                'direction' => $direction,
                'confidence' => $confidence,
                'created_by' => $createdBy,
            ],
        );
    }

    public function delete(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
    ): void {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->delete(
            self::TABLE,
            [
                'source_table' => $sourceTable,
                'source_uid' => $sourceUid,
                'target_table' => $targetTable,
                'target_uid' => $targetUid,
                'relation_type' => $type->value,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRowToContentRelation(array $row): ContentRelation
    {
        return new ContentRelation(
            sourceTable: (string) $row['source_table'],
            sourceUid: (int) $row['source_uid'],
            targetTable: (string) $row['target_table'],
            targetUid: (int) $row['target_uid'],
            relationType: RelationType::from((string) $row['relation_type']),
            direction: (string) $row['direction'],
            confidence: (float) $row['confidence'],
            createdBy: (string) $row['created_by'],
            description: isset($row['description']) && is_string($row['description']) && '' !== $row['description']
                ? $row['description']
                : null,
        );
    }
}
