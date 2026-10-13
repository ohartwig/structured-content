<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * Loads sys_category records assigned to a given page.
 * Used by ArticleSchemaGenerator to emit the Schema.org `articleSection` property.
 */
interface CategoryRepositoryInterface
{
    /**
     * @return list<array{uid: int, title: string, slug: string}>
     */
    public function findByPageUid(int $pageUid): array;
}
