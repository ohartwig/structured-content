<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Model;

final readonly class FilteredRecord
{
    /**
     * @param array<string, mixed> $record
     * @param list<string>         $removedFields
     */
    public function __construct(
        public array $record,
        public array $removedFields,
        public ?string $reason,
    ) {}
}
