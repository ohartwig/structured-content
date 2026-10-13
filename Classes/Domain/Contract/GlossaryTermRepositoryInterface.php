<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * Reads glossary terms from the featdd/dpn-glossary tables.
 *
 * Every method returns raw rows. Shaping them — stripping HTML, trimming,
 * dropping empties — stays in the application service, so this contract has
 * exactly one job and the caching layer above it has something stable to cache.
 *
 * `isAvailable()` is part of the contract rather than a separate check: whether
 * the backing extension is installed is a property of the store, and every
 * caller has to ask before touching it. The implementations return empty
 * results when it is absent, so a missing dpn_glossary can never produce a
 * query against a non-existent table.
 */
interface GlossaryTermRepositoryInterface
{
    /**
     * Whether the dpn_glossary extension — and therefore its tables — exists.
     */
    public function isAvailable(): bool;

    public function findPageSlug(int $pageUid): ?string;

    /**
     * @return array<string, mixed>|null
     */
    public function findTermBySlug(string $slug, int $languageId): ?array;

    /**
     * The term in the given language, identified by the uid of its
     * default-language record — the value a PersistedAliasMapper hands over.
     *
     * @return array<string, mixed>|null
     */
    public function findTermByUid(int $uid, int $languageId): ?array;

    /**
     * The first description of a term, as stored (may contain HTML).
     */
    public function findFirstDescription(int $termUid, int $languageId): ?string;

    /**
     * Synonym names of a term, in editorial order, as stored.
     *
     * @return list<string>
     */
    public function findSynonymNames(int $termUid, int $languageId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllTerms(int $languageId): array;
}
