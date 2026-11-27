<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * Reverse-lookup: pages that link a given moselwal_person UID via the
 * tx_structuredcontent_author_records group field.
 * Used by ProfilePageSchemaGenerator for the `hasPart` array.
 */
interface AuthoredPagesRepositoryInterface
{
    /**
     * The pages in the given language, newest publication (crdate) first.
     *
     * @return list<array<string, mixed>>
     */
    public function findByAuthorUid(int $personUid, int $languageId = 0, int $limit = 50): array;
}
