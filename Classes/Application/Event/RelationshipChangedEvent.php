<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Event;

use Koh\StructuredContent\Domain\Enum\RelationType;

final readonly class RelationshipChangedEvent
{
    public function __construct(
        public string $action,
        public string $sourceTable,
        public int $sourceUid,
        public string $targetTable,
        public int $targetUid,
        public RelationType $relationType,
    ) {}
}
