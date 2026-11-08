<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * Optional addition to GlossaryTermRepositoryInterface: several terms by slug
 * in one query.
 *
 * A separate interface so that existing implementations of the repository
 * contract keep working. GlossaryTermService uses it when the repository
 * implements it and falls back to one findTermBySlug() per slug otherwise.
 */
interface GlossaryTermBatchLookupInterface
{
    /**
     * Several terms by slug in one query: the same match, row and restrictions
     * as GlossaryTermRepositoryInterface::findTermBySlug(), for each slug.
     * Keyed by the slug as asked for; slugs without a term are absent.
     *
     * @param list<string> $slugs
     *
     * @return array<string, array<string, mixed>>
     */
    public function findTermsBySlugs(array $slugs, int $languageId): array;
}
