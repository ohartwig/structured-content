<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

use Koh\StructuredContent\Domain\Model\ResolvedContext;

interface ContextResolverInterface
{
    /**
     * @param array<string, mixed> $record
     */
    public function resolve(string $table, array $record): ResolvedContext;

    public function resolveFromPageTree(int $pageUid): ResolvedContext;
}
