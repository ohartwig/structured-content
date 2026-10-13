<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Model;

use Koh\StructuredContent\Domain\Enum\RelationType;

final readonly class ContentRelation
{
    public function __construct(
        public string $sourceTable,
        public int $sourceUid,
        public string $targetTable,
        public int $targetUid,
        public RelationType $relationType,
        public string $direction,
        public float $confidence,
        public string $createdBy,
        public ?string $description,
    ) {}
}
