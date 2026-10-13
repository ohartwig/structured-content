<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Single source of truth fuer die "ist das eine Service-Detail-Page?"-
 * Erkennung. Genutzt vom ServiceSchemaGenerator (Hauptsubject-Emission)
 * und vom WebPageSchemaGenerator (typisiert sich auf solchen Pages als
 * ItemPage statt plain WebPage + verknuepft per mainEntity-@id-Ref).
 *
 * Die Heuristik mirroring ServiceSchemaGenerator::canHandle()-Logic:
 * - Root-Page-Skip
 * - SoftwareApplication-/SoftwareSourceCode-Override-Skip
 * - tx_structuredcontent_code_repository-Skip
 * - /open-source/-Slug-Skip
 * - CType-Pattern (service/solution) ODER
 * - Page-Slug-/Keyword-Pattern (leistung/service/dienstleistung)
 */
trait ServicePageDetectionTrait
{
    /**
     * CType-Substrings, die einen Service-CB auf der Page signalisieren.
     *
     * @var list<string>
     */
    private const SERVICE_CTYPE_PATTERNS = ['service', 'solution'];

    /**
     * Slug-/Keyword-Substrings, die eine Service-Detail-Page signalisieren
     * (z.B. /leistungen/foo, /loesungen/foo-as-a-service).
     *
     * @var list<string>
     */
    private const SERVICE_PAGE_PATTERNS = ['leistung', 'service', 'dienstleistung'];

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    private function isServicePage(array $pageData, array $contentElements): bool
    {
        $isRootPage = (int) ($pageData['uid'] ?? 0) === (int) ($pageData['_rootPageId'] ?? -1);
        if ($isRootPage) {
            return false;
        }

        // SoftwareApplication/SoftwareSourceCode owns themselves —
        // Service-Schema waere doppelt-typisiert.
        $override = trim((string) ($pageData['tx_structuredcontent_schema_type'] ?? ''));
        if ('SoftwareApplication' === $override || 'SoftwareSourceCode' === $override) {
            return false;
        }
        if ('' !== trim((string) ($pageData['tx_structuredcontent_code_repository'] ?? ''))) {
            return false;
        }

        $slug = strtolower((string) ($pageData['slug'] ?? ''));
        if (str_starts_with($slug, '/open-source/') || str_starts_with($slug, '/opensource/')) {
            return false;
        }

        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            foreach (self::SERVICE_CTYPE_PATTERNS as $pattern) {
                if (str_contains($cType, $pattern)) {
                    return true;
                }
            }
        }

        $keywords = strtolower((string) ($pageData['keywords'] ?? ''));
        $combined = $slug . ' ' . $keywords;
        foreach (self::SERVICE_PAGE_PATTERNS as $pattern) {
            if (str_contains($combined, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
