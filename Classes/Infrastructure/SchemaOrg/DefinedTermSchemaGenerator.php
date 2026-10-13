<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

/**
 * Emits Schema.org DefinedTerm fuer dpn-glossary-Detail-Pages
 * (/glossar/<slug>). Conditional Loading: feuert nur wenn die
 * dpn_glossary-Extension installiert ist.
 *
 * Trigger: routing argument tx_dpnglossary_glossary.term, resolved to the
 * term uid by the PersistedAliasMapper of the Glossary route enhancer (or
 * the raw slug without one). The service looks the term up either way.
 */
final class DefinedTermSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    public function __construct(
        private readonly GlossaryTermService $glossaryService,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        if (!$this->glossaryService->isAvailable()) {
            return false;
        }
        $identifier = $this->resolveTermIdentifier($pageData);
        if ('' === $identifier) {
            return false;
        }
        $languageId = (int) ($pageData['_languageId'] ?? 0);

        return null !== $this->glossaryService->findTerm($identifier, $languageId);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $identifier = $this->resolveTermIdentifier($pageData);
        $languageId = (int) ($pageData['_languageId'] ?? 0);
        $row = $this->glossaryService->findTerm($identifier, $languageId);
        if (null === $row) {
            return new JsonLdDocument('DefinedTerm', []);
        }
        // The URL carries the slug even when the routing argument is a uid.
        $slug = trim((string) ($row['url_segment'] ?? ''));
        if ('' === $slug) {
            $slug = $identifier;
        }

        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $glossaryBase = '/' . trim((string) ($pageData['_glossaryBasePath'] ?? '/glossar'), '/');
        $termUrl = $siteBase . $glossaryBase . '/' . $slug;

        $data = [
            '@id' => $termUrl . '#term',
            'name' => $this->sanitizeText((string) ($row['name'] ?? '')),
            'url' => $termUrl,
            'inDefinedTermSet' => ['@id' => $siteBase . $glossaryBase . '#termset'],
        ];

        // Description: erste Description-Row (gestripped HTML), Fallback
        // auf tooltiptext und meta_description.
        $description = $this->glossaryService->loadFirstDescription((int) $row['uid'], $languageId);
        if ('' === $description) {
            $description = trim((string) ($row['meta_description'] ?? ''));
        }
        if ('' === $description) {
            $description = trim((string) ($row['tooltiptext'] ?? ''));
        }
        if ('' !== $description) {
            $data['description'] = $this->sanitizeText($description);
        }

        // Synonyme als Schema.org alternateName — gibt Crawlern + LLMs
        // alternative Bezeichnungen ueber die der Term findbar ist. Bei
        // 1 Synonym als String, sonst als Array.
        $synonyms = $this->glossaryService->loadSynonyms((int) $row['uid'], $languageId);
        if ([] !== $synonyms) {
            $sanitized = array_map(fn(string $s): string => $this->sanitizeText($s), $synonyms);
            $data['alternateName'] = 1 === count($sanitized) ? $sanitized[0] : $sanitized;
        }

        return new JsonLdDocument('DefinedTerm', $data);
    }

    public function getPriority(): int
    {
        // Zwischen PersonPage (80) und BreadcrumbList (90). DefinedTerm
        // ist die primaere Entitaet auf der Detail-Page — sollte direkt
        // nach BreadcrumbList kommen, sodass Crawler die Hauptentity
        // schnell erkennen.
        return 85;
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function resolveTermIdentifier(array $pageData): string
    {
        $routingArguments = $pageData['_routingArguments'] ?? [];
        if (!is_array($routingArguments)) {
            return '';
        }
        $args = $routingArguments['tx_dpnglossary_glossary'] ?? null;
        if (!is_array($args)) {
            return '';
        }
        // The Glossary route enhancer with a PersistedAliasMapper resolves
        // {term} to the uid; a plain Extbase route passes the slug.
        // GlossaryTermService::findTerm() accepts both.
        return trim((string) ($args['term'] ?? ''));
    }
}
