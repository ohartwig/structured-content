<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

use Koh\StructuredContent\Domain\Enum\RelationType;
use Koh\StructuredContent\Domain\Model\ContentRelation;

interface RelationshipRepositoryInterface
{
    /**
     * @return list<ContentRelation>
     */
    public function findBySource(string $table, int $uid, ?RelationType $type = null): array;

    /**
     * @return list<ContentRelation>
     */
    public function findBidirectionalByTarget(string $table, int $uid, ?RelationType $type = null): array;

    public function exists(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
    ): bool;

    public function insert(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
        string $direction,
        float $confidence,
        string $createdBy,
    ): void;

    public function delete(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
    ): void;
}
