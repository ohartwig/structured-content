<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class ProductSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $keywords = strtolower((string) ($pageData['keywords'] ?? ''));

        // Wort-Boundary-Match — vermeidet false-positives bei "Produktivitaet",
        // "Produktion", "productive" usw. Nur "Produkt(e)" / "Product(s)" als
        // ganzes Wort triggern den Generator.
        if (1 !== preg_match('/\b(?:produkt[e]?|product[s]?)\b/u', $keywords)) {
            return false;
        }

        // Service-/Solution-Pages haben Vorrang fuer ServiceSchemaGenerator
        // (per CType oder Slug "leistung/service/dienstleistung"). Bei Mischung
        // gewinnt Service — sonst kollidieren zwei semantisch verschiedene
        // Schemas auf derselben Seite.
        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            if (str_contains($cType, 'service') || str_contains($cType, 'solution')) {
                return false;
            }
        }
        $slug = strtolower((string) ($pageData['slug'] ?? ''));
        if (1 === preg_match('/\b(leistung|service|dienstleistung)/u', $slug)) {
            return false;
        }

        // Schema.org Product verlangt mindestens eines von offers, review oder
        // aggregateRating (Google Rich Result Validator). Ohne diese Felder
        // produzieren wir invalides JSON-LD — daher: Schema komplett
        // unterdruecken statt Google-Fehler zu provozieren.
        foreach (['offers', 'review', 'aggregateRating'] as $field) {
            $value = $pageData[$field] ?? null;
            if (null !== $value && '' !== $value && [] !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $data = [
            'name' => $this->sanitizeText($pageData['title'] ?? ''),
            'description' => $this->sanitizeText($pageData['description'] ?? $pageData['abstract'] ?? ''),
        ];

        if (isset($pageData['og_image']) && '' !== $pageData['og_image']) {
            $data['image'] = $pageData['og_image'];
        }

        // canHandle stellt sicher dass mindestens eines davon gesetzt ist.
        foreach (['offers', 'review', 'aggregateRating'] as $field) {
            $value = $pageData[$field] ?? null;
            if (null !== $value && '' !== $value && [] !== $value) {
                $data[$field] = $value;
            }
        }

        return new JsonLdDocument('Product', array_filter($data, SchemaValues::present(...)));
    }

    public function getPriority(): int
    {
        return 60;
    }
}
