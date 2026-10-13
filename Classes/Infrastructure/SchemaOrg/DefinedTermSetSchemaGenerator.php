<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

/**
 * Emits Schema.org DefinedTermSet auf der Glossar-Listing-Page
 * (Page-UID == dpnGlossary.settings.detailPage). Conditional Loading
 * via GlossaryTermService::isAvailable().
 *
 * `hasDefinedTerm` enthaelt @id-Refs auf jeden Term (kein Inline), die
 * Detail-Pages emittieren parallel ihre eigenen DefinedTerm-Entities
 * mit den vollen Properties. So bleibt der Knowledge-Graph konsistent
 * ohne doppelte Term-Daten.
 */
final class DefinedTermSetSchemaGenerator implements SchemaGeneratorInterface
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
        // DefinedTermSet feuert nur auf der Listing-Page selbst — NICHT auf
        // den Detail-Pages (die haben DefinedTerm und die referenzieren das
        // Set via inDefinedTermSet.@id, brauchen aber nicht das volle Set
        // im Output zu doppeln).
        if ($this->isOnDetailRoute($pageData)) {
            return false;
        }

        return $this->glossaryService->isGlossaryListingPage($pageData);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $glossaryBase = '/' . trim((string) ($pageData['_glossaryBasePath'] ?? '/glossar'), '/');
        $languageId = (int) ($pageData['_languageId'] ?? 0);

        $terms = $this->glossaryService->findAllTerms($languageId);
        $items = [];
        foreach ($terms as $row) {
            $slug = trim((string) ($row['url_segment'] ?? ''));
            if ('' === $slug) {
                continue;
            }
            $items[] = ['@id' => $siteBase . $glossaryBase . '/' . $slug . '#term'];
        }

        $data = [
            '@id' => $siteBase . $glossaryBase . '#termset',
            'name' => $this->sanitizeText((string) ($pageData['title'] ?? 'Glossar')),
            'url' => $siteBase . $glossaryBase,
        ];
        if ([] !== $items) {
            $data['hasDefinedTerm'] = $items;
        }

        return new JsonLdDocument('DefinedTermSet', $data);
    }

    public function getPriority(): int
    {
        // Hoeher als WebPage (40) damit Crawler die Listing-Page primaer als
        // DefinedTermSet einordnen. Niedriger als BreadcrumbList (90) und
        // DefinedTerm (85) damit die Hierarchie in der JSON-LD-Reihenfolge
        // dem Lese-Pfad entspricht.
        return 78;
    }

    /**
     * Verhindert DefinedTermSet auf Detail-Pages — auch wenn detailPage-
     * Setting auf die gleiche Page zeigt (Plugin rendert dort die Detail-
     * View), wollen wir nicht zusaetzlich das ganze Set ausgeben.
     *
     * @param array<string, mixed> $pageData
     */
    private function isOnDetailRoute(array $pageData): bool
    {
        $routingArguments = $pageData['_routingArguments'] ?? [];
        if (!is_array($routingArguments)) {
            return false;
        }
        $args = $routingArguments['tx_dpnglossary_glossary'] ?? null;

        return is_array($args) && '' !== trim((string) ($args['term'] ?? ''));
    }
}
