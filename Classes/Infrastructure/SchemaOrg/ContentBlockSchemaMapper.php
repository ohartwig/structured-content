<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Contract\SchemaMappingConfigurableInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class ContentBlockSchemaMapper implements SchemaGeneratorInterface, SchemaMappingConfigurableInterface
{
    use SanitizesSchemaTrait;
    private const ALLOWED_CONTENT_FIELDS = [
        'header', 'bodytext', 'subheader', 'header_link',
        'image', 'media', 'assets', 'categories',
        'date', 'pi_flexform',
    ];

    private const VALID_SCHEMA_TYPES = [
        'Thing', 'Article', 'NewsArticle', 'BlogPosting', 'Product',
        'Organization', 'LocalBusiness', 'FAQPage', 'HowTo',
        'Person', 'Place', 'Event', 'Recipe', 'CreativeWork',
        'WebPage', 'ItemPage', 'AboutPage', 'ContactPage',
        'Service', 'Offer', 'Review', 'AggregateRating',
        'PodcastSeries', 'PodcastEpisode',
    ];

    /** @var array<string, array{schemaType: string, fields: array<string, string>}> */
    private array $mappings = [];

    /**
     * @param array<string, array{schemaType: string, fields: array<string, string>}> $mappings
     */
    public function setMappings(array $mappings): void
    {
        $this->mappings = $mappings;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        if ([] === $this->mappings) {
            return false;
        }

        foreach ($contentElements as $element) {
            $cType = $element['CType'] ?? '';
            if (isset($this->mappings[$cType])) {
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
        $schemas = [];

        foreach ($contentElements as $element) {
            $cType = $element['CType'] ?? '';
            if (!isset($this->mappings[$cType])) {
                continue;
            }

            $mapping = $this->mappings[$cType];
            $schemaType = $mapping['schemaType'];
            if (!in_array($schemaType, self::VALID_SCHEMA_TYPES, true)) {
                $schemaType = 'Thing';
            }
            $fields = $mapping['fields'];

            $data = [];
            foreach ($fields as $schemaField => $contentField) {
                if (!in_array($contentField, self::ALLOWED_CONTENT_FIELDS, true)) {
                    continue;
                }
                if (isset($element[$contentField]) && '' !== $element[$contentField]) {
                    $data[$schemaField] = $this->sanitizeText((string) $element[$contentField]);
                }
            }

            // Product erfordert mind. offers/review/aggregateRating laut Google.
            // Wenn ein Custom-Mapping Product fuer einen CType konfiguriert,
            // aber keines der Properties mit-mapped: downgrade zu Thing —
            // invalides Product-Schema schadet SEO mehr als generisches Thing.
            if ('Product' === $schemaType
                && [] === array_intersect_key($data, ['offers' => true, 'review' => true, 'aggregateRating' => true])) {
                $schemaType = 'Thing';
            }

            if ([] !== $data) {
                $schemas[] = ['@type' => $schemaType] + $data;
            }
        }

        if (1 === count($schemas)) {
            $schema = $schemas[0];
            $type = $schema['@type'];
            unset($schema['@type']);

            return new JsonLdDocument($type, $schema);
        }

        return new JsonLdDocument('ItemList', [
            'itemListElement' => $schemas,
        ]);
    }

    public function getPriority(): int
    {
        return 30;
    }
}
