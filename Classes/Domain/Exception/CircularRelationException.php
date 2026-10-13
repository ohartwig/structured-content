<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Exception;

final class CircularRelationException extends \RuntimeException
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
                'Adding relation of type "%s" from %s:%d to %s:%d would create a cycle',
                $type,
                $sourceTable,
                $sourceUid,
                $targetTable,
                $targetUid,
            ),
        );
    }
}
