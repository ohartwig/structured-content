<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

/**
 * Fuzzy auto-detection of Schema.org types based on CType patterns,
 * page title, header content and content structure.
 * Works without explicit keyword configuration or schema mappings.
 *
 * Acts as a safety net: skips when {@see SchemaTypeResolverService} already
 * resolves a value (then Article/WebPageSchemaGenerator handles the page).
 */
final class AutoDetectSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    public function __construct(
        private readonly SchemaTypeResolverService $schemaTypeResolver,
    ) {}
    /** @var array<string, string> CType pattern => Schema.org type */
    private const CTYPE_PATTERNS = [
        'faq' => 'FAQPage',
        'testimonial' => 'Review',
        'review' => 'Review',
        'team' => 'AboutPage',
        'about' => 'AboutPage',
        'contact' => 'ContactPage',
        'service' => 'Service',
    ];

    /** @var array<string, string> Page title/slug pattern => Schema.org type */
    private const PAGE_PATTERNS = [
        'impressum' => 'AboutPage',
        'imprint' => 'AboutPage',
        'datenschutz' => 'WebPage',
        'privacy' => 'WebPage',
        'kontakt' => 'ContactPage',
        'contact' => 'ContactPage',
        'blog' => 'Blog',
        'news' => 'Blog',
        'about' => 'AboutPage',
        'ueber' => 'AboutPage',
        'leistung' => 'Service',
        'service' => 'Service',
        'referenz' => 'ItemPage',
        'portfolio' => 'ItemPage',
        'faq' => 'FAQPage',
    ];

    // Schema.org Product verlangt mind. eines von offers/review/aggregateRating
    // (Google Rich Result Validator). AutoDetect kann diese Felder nicht liefern
    // — daher emittieren wir kein Product. Spezialisierte Generatoren mit
    // hoeherer Priority (PackagesGridProductSchemaGenerator, ProductSchemaGenerator)
    // greifen wenn echte Produkt-Daten vorliegen.
    private const TYPES_REQUIRING_OFFERS_OR_REVIEW = ['Product'];

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    /** CTypes that have their own dedicated schema generators */
    private const DEDICATED_SCHEMA_CTYPES = [
        'moselwal_videopodcastfeed',
        'contentblockscollection_podcast',
    ];

    public function canHandle(array $pageData, array $contentElements): bool
    {
        $isRootPage = (int) ($pageData['uid'] ?? 0) === (int) ($pageData['_rootPageId'] ?? -1);
        if ($isRootPage) {
            return false;
        }

        // Resolver owns Article-/WebPage-family routing — skip when it matches
        // so we don't emit duplicate documents alongside Article/WebPage.
        if (null !== $this->schemaTypeResolver->resolve($pageData, $contentElements)) {
            return false;
        }

        foreach ($contentElements as $element) {
            if (in_array($element['CType'] ?? '', self::DEDICATED_SCHEMA_CTYPES, true)) {
                return false;
            }
        }

        return [] !== $contentElements || ($pageData['title'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $detectedType = $this->detectFromPage($pageData);
        $detectedFromContent = $this->detectFromContent($contentElements);

        // Content-based detection takes precedence over page-based
        $schemaType = $detectedFromContent ?? $detectedType ?? 'WebPage';

        // Defense-in-depth: falls eine kuenftige Pattern-Erweiterung wieder
        // einen Type mit Required-Properties traefe, ohne sie zu liefern,
        // downgrade zu WebPage — invalides Schema schadet SEO mehr als das
        // generische WebPage.
        if (in_array($schemaType, self::TYPES_REQUIRING_OFFERS_OR_REVIEW, true)
            && !$this->hasOffersOrReview($pageData)) {
            $schemaType = 'WebPage';
        }

        $title = $pageData['title'] ?? '';
        $description = $pageData['description'] ?? $pageData['abstract'] ?? '';

        $data = array_filter(
            [
                'name' => $this->sanitizeText($title),
                'description' => $this->sanitizeText((string) $description),
                'url' => $pageData['canonical_link'] ?? '',
            ],
            SchemaValues::present(...),
        );

        // Enrich with content summary
        $bodyParts = [];
        foreach ($contentElements as $element) {
            $body = $this->sanitizeText((string) ($element['bodytext'] ?? ''));
            if ('' !== $body) {
                $bodyParts[] = $body;
            }
        }
        if ([] !== $bodyParts) {
            $fullText = implode(' ', $bodyParts);
            // Limit to ~500 chars for schema description
            if (($data['description'] ?? '') === '' && mb_strlen($fullText) > 10) {
                $data['description'] = mb_substr($fullText, 0, 500);
            }
        }

        // Add timestamps
        if (isset($pageData['crdate']) && (int) $pageData['crdate'] > 0) {
            $data['datePublished'] = date('c', (int) $pageData['crdate']);
        }
        if (isset($pageData['tstamp']) && (int) $pageData['tstamp'] > 0) {
            $data['dateModified'] = date('c', (int) $pageData['tstamp']);
        }

        return new JsonLdDocument($schemaType, array_filter($data, SchemaValues::present(...)));
    }

    public function getPriority(): int
    {
        // Low priority — other generators with explicit detection take precedence
        return 10;
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function hasOffersOrReview(array $pageData): bool
    {
        foreach (['offers', 'review', 'aggregateRating'] as $field) {
            $value = $pageData[$field] ?? null;
            if (null !== $value && '' !== $value && [] !== $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function detectFromPage(array $pageData): ?string
    {
        $title = strtolower((string) ($pageData['title'] ?? ''));
        $slug = strtolower((string) ($pageData['slug'] ?? ''));
        $keywords = strtolower((string) ($pageData['keywords'] ?? ''));
        $combined = $title . ' ' . $slug . ' ' . $keywords;

        foreach (self::PAGE_PATTERNS as $pattern => $schemaType) {
            if (str_contains($combined, $pattern)) {
                return $schemaType;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $contentElements
     */
    private function detectFromContent(array $contentElements): ?string
    {
        /** @var array<string, int> */
        $typeVotes = [];

        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            $header = strtolower((string) ($element['header'] ?? ''));

            // Match CType against patterns
            foreach (self::CTYPE_PATTERNS as $pattern => $schemaType) {
                if (str_contains($cType, $pattern)) {
                    $typeVotes[$schemaType] = ($typeVotes[$schemaType] ?? 0) + 2;
                }
            }

            // Match header content against patterns
            foreach (self::PAGE_PATTERNS as $pattern => $schemaType) {
                if (str_contains($header, $pattern)) {
                    $typeVotes[$schemaType] = ($typeVotes[$schemaType] ?? 0) + 1;
                }
            }
        }

        if ([] === $typeVotes) {
            return null;
        }

        // Return type with most votes (CType matches weigh more)
        arsort($typeVotes);

        return array_key_first($typeVotes);
    }
}
