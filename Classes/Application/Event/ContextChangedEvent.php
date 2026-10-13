<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Event;

use Koh\StructuredContent\Domain\Model\ResolvedContext;

final readonly class ContextChangedEvent
{
    public function __construct(
        public string $table,
        public int $uid,
        public ResolvedContext $oldContext,
        public ResolvedContext $newContext,
    ) {}
}
