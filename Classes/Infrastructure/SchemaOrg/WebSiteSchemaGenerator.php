<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class WebSiteSchemaGenerator implements SchemaGeneratorInterface
{
    use ResolvesStableBrandNameTrait;
    use SanitizesSchemaTrait;

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        // Spec: Jede Seite hat eine WebSite-Entity im @graph. Ohne sie sind
        // die isPartOf-Refs (BlogPosting → WebSite, WebPage → WebSite) tote
        // Referenzen. Vorher war auf Root-Page beschraenkt.
        return (int) ($pageData['uid'] ?? 0) > 0;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $settings = $pageData['_siteSettings'] ?? [];
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);

        // Wie bei Organization: der site-weite `#website`-Knoten darf seinen
        // Namen nicht aus der aktuellen Page ziehen.
        $name = $this->resolveStableBrandName($settings, $siteBase);

        $data = [
            '@id' => $entityBase . '#website',
            'name' => $this->sanitizeText($name),
            'url' => $siteBase . '/',
        ];

        // WebSite-Description ebenso stabil wie Organization-Description —
        // niemals aus pageData (Spec-Verletzung). Quelle: Site-Setting
        // organizationDescription, Fallback footerBrandClaim.
        $schemaSettings = $settings['structuredContent']['schema'] ?? [];
        // A language may override it in its site configuration, because site
        // settings are the same for every language.
        $languageSchema = $pageData['_languageSchema'] ?? [];
        $description = is_array($languageSchema) ? trim((string) ($languageSchema['organizationDescription'] ?? '')) : '';
        if ('' === $description) {
            $description = trim((string) ($schemaSettings['organizationDescription'] ?? ''));
        }
        if ('' === $description) {
            $description = trim((string) ($settings['footerBrandClaim'] ?? ''));
        }
        if ('' !== $description) {
            $data['description'] = $this->sanitizeText($description);
        }

        // Reference the Organization Schema-Node so crawlers connect WebSite
        // → Organization without having to inspect the entity graph twice.
        // Google's Sitelinks-Search-Box rich result rewards this connection.
        $data['publisher'] = [
            '@id' => $entityBase . '#organization',
        ];

        $language = (string) ($pageData['_language'] ?? '');
        if ('' !== $language) {
            $data['inLanguage'] = $language;
        }

        // Sitelinks-Suchfeld Rich Result: explicit URL via site setting,
        // otherwise fall back to /?q=… on the site root. Even without a
        // dedicated /search route the SearchAction triggers Google's
        // rich result and the homepage handles the query parameter
        // gracefully (defaults to root listing).
        $searchActionUrl = trim((string) ($settings['structuredContent']['schema']['searchActionUrl'] ?? ''));
        if ('' !== $searchActionUrl) {
            $target = $siteBase . '/' . ltrim($searchActionUrl, '/');
            if (!str_contains($target, '{search_term_string}')) {
                $separator = str_contains($target, '?') ? '&' : '?';
                $target .= $separator . 'q={search_term_string}';
            }
            $data['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => $target,
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return new JsonLdDocument('WebSite', array_filter($data, SchemaValues::present(...)));
    }

    public function getPriority(): int
    {
        return 80;
    }
}
