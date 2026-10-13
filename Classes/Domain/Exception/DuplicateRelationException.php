<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Exception;

final class DuplicateRelationException extends \RuntimeException
{
    public static function forRelation(
        string $sourceTable,
        int $sourceUid,
        string $targetTable,
        int $targetUid,
        string $type,
    ): self {
        return new self(
            message: sprintf(
                'Relation of type "%s" already exists between %s:%d and %s:%d',
                $type,
                $sourceTable,
                $sourceUid,
                $targetTable,
                $targetUid,
            ),
        );
    }
}
