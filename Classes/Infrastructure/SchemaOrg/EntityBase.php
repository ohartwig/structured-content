<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Base URL for the `@id` of site-wide entities.
 *
 * Organization (`#organization`), WebSite (`#website`), ProfessionalService
 * and the Person nodes (`/autor/<slug>#person`) are one entity per site, not
 * one per language. Their `@id` must therefore be identical on every page in
 * every language, or the graph falls apart: an English page that declares
 * `<base>/en#organization` defines a second organization that no other page
 * references, while its own nodes point to ids nobody defines.
 *
 * `_siteBase` is the language base (e.g. `https://example.org/en`) and stays
 * right for page-scoped ids and URLs (WebPage, BlogPosting, breadcrumbs).
 * `_entityBase` is the base of the site's default language and is used for
 * the site-wide entities. Callers that do not provide `_entityBase` fall back
 * to `_siteBase`, which is the previous behaviour.
 */
final class EntityBase
{
    /**
     * @param array<string, mixed> $pageData
     */
    public static function fromPageData(array $pageData): string
    {
        $base = $pageData['_entityBase'] ?? $pageData['_siteBase'] ?? '';

        return rtrim(is_string($base) ? $base : '', '/');
    }
}
