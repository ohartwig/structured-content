<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Resolves the brand name for the site-wide Organization and WebSite nodes.
 *
 * The name of a node whose `@id` is site-scoped (`<siteBase>#organization`,
 * `<siteBase>#website`) MUST be identical on every page of that site. Falling
 * back to `$pageData['title']` made the publisher entity mutate per page — on
 * ole-hartwig.eu that produced 41 distinct Organization names across ~190 URLs,
 * all claiming the same `@id`, which destroys entity consolidation for search
 * engines and LLM crawlers.
 *
 * This mirrors the reasoning already applied to Organization/WebSite
 * `description` (stable, never page-derived) and extends it to `name`.
 */
trait ResolvesStableBrandNameTrait
{
    /**
     * @param array<string, mixed> $settings site settings (`_siteSettings`)
     */
    private function resolveStableBrandName(array $settings, string $siteBase): string
    {
        $brandName = trim((string) ($settings['footerBrandName'] ?? ''));
        if ('' !== $brandName) {
            return $brandName;
        }

        $schemaSettings = $settings['structuredContent']['schema'] ?? [];
        if (is_array($schemaSettings)) {
            $legalName = trim((string) ($schemaSettings['legalName'] ?? ''));
            if ('' !== $legalName) {
                return $legalName;
            }
        }

        // Last resort: the registrable host. Page-independent by construction,
        // so a missing setting degrades to a stable — if unbranded — value
        // instead of silently forking the entity on every page.
        $host = parse_url($siteBase, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }
}
