<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

use Koh\StructuredContent\Application\Event\RelationshipChangedEvent;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Enum\RelationType;
use Koh\StructuredContent\Domain\Exception\CircularRelationException;
use Koh\StructuredContent\Domain\Exception\DuplicateRelationException;
use Koh\StructuredContent\Domain\Model\ContentRelation;
use Koh\StructuredContent\Domain\Contract\EventDispatcherInterface;

final readonly class RelationshipService
{
    public function __construct(
        private RelationshipRepositoryInterface $repository,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * @return list<ContentRelation>
     */
    public function getRelationsFor(string $table, int $uid, ?RelationType $type = null): array
    {
        return $this->repository->findBySource(table: $table, uid: $uid, type: $type);
    }

    public function addRelation(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
        string $direction,
        float $confidence,
        string $createdBy,
    ): void {
        if ($this->repository->exists(
            sourceTable: $sourceTable,
            sourceUid: $sourceUid,
            targetTable: $targetTable,
            targetUid: $targetUid,
            type: $type,
        )) {
            throw DuplicateRelationException::forRelation(sourceTable: $sourceTable, sourceUid: $sourceUid, targetTable: $targetTable, targetUid: $targetUid, type: $type->value);
        }

        if ($this->wouldCreateCycle(
            sourceTable: $sourceTable,
            sourceUid: $sourceUid,
            targetTable: $targetTable,
            targetUid: $targetUid,
            type: $type,
        )) {
            throw CircularRelationException::forRelation(sourceTable: $sourceTable, sourceUid: $sourceUid, targetTable: $targetTable, targetUid: $targetUid, type: $type->value);
        }

        $this->repository->insert(
            sourceTable: $sourceTable,
            sourceUid: $sourceUid,
            targetTable: $targetTable,
            targetUid: $targetUid,
            type: $type,
            direction: $direction,
            confidence: $confidence,
            createdBy: $createdBy,
        );

        $this->eventDispatcher->dispatch(new RelationshipChangedEvent(
            action: 'added',
            sourceTable: $sourceTable,
            sourceUid: $sourceUid,
            targetTable: $targetTable,
            targetUid: $targetUid,
            relationType: $type,
        ));
    }

    /**
     * Validates a relation type string from user input.
     *
     * @throws \InvalidArgumentException if the value does not map to a valid RelationType
     */
    public function validateRelationType(string $typeValue): RelationType
    {
        $type = RelationType::tryFrom($typeValue);
        if (null === $type) {
            throw new \InvalidArgumentException(sprintf('Invalid relation type "%s". Valid types: %s', $typeValue, implode(', ', array_map(static fn(RelationType $t): string => $t->value, RelationType::cases()))), 1712000000);
        }

        return $type;
    }

    public function wouldCreateCycle(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        RelationType $type,
    ): bool {
        // Self-reference is always a cycle
        if ($sourceTable === $targetTable && $sourceUid === $targetUid) {
            return true;
        }

        // Check if target already has a relation back to source (direct cycle)
        $targetRelations = $this->repository->findBySource(
            table: $targetTable,
            uid: $targetUid,
            type: $type,
        );

        foreach ($targetRelations as $relation) {
            if ($relation->targetTable === $sourceTable && $relation->targetUid === $sourceUid) {
                return true;
            }
        }

        return false;
    }
}
