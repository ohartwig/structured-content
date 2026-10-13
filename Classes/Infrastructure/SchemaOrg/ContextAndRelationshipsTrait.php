<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Enum\RelationType;

/**
 * Adds AI-context (audience/tone/channels) and content-relationship fields
 * to a Schema.org JSON-LD data array.
 *
 * Used by Article- and WebPage-family generators to surface structured-content
 * annotations alongside the generated document.
 */
trait ContextAndRelationshipsTrait
{
    /** @var array<string, string> RelationType value → Schema.org property */
    /** Relation type → Schema.org property. A type with no entry here is skipped. */
    private const RELATION_SCHEMA_MAP = [
        'replaces' => 'isBasedOn',
        'belongs_to' => 'isPartOf',
        'related_to' => 'relatedLink',
        'explains' => 'about',
        'requires' => 'requires',
        'contradicts' => 'relatedLink',
        'alternative_to' => 'relatedLink',
    ];

    /** @var array<string, string> Internal audience value → Schema.org BusinessAudience.audienceType */
    private const AUDIENCE_LABEL_MAP = [
        'customer' => 'Customer',
        'partner' => 'Partner',
        'internal' => 'Internal Staff',
        'developer' => 'Developer',
    ];

    /**
     * Enrich a generated JSON-LD data array with audience/tone/channels and
     * relationship references. Returns the array unchanged when the
     * structured-content services are not available.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $pageData
     *
     * @return array<string, mixed>
     */
    private function enrichWithContextAndRelationships(
        array $data,
        array $pageData,
        ?ContextResolverInterface $contextResolver,
        ?RelationshipRepositoryInterface $relationshipRepository,
    ): array {
        $pageUid = (int) ($pageData['uid'] ?? 0);
        if ($pageUid <= 0) {
            return $data;
        }

        if (null !== $contextResolver) {
            $context = $contextResolver->resolveFromPageTree(pageUid: $pageUid);

            if ('' !== $context->audience) {
                $data['audience'] = [
                    '@type' => 'BusinessAudience',
                    'audienceType' => self::AUDIENCE_LABEL_MAP[$context->audience] ?? ucfirst($context->audience),
                ];
            }

            $additionalProperty = [];
            if ('' !== $context->tone) {
                $additionalProperty[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'tone',
                    'value' => $context->tone,
                ];
            }
            if ([] !== $context->channels) {
                $additionalProperty[] = [
                    '@type' => 'PropertyValue',
                    'name' => 'channels',
                    'value' => implode(',', $context->channels),
                ];
            }
            if ([] !== $additionalProperty) {
                $data['additionalProperty'] = $additionalProperty;
            }
        }

        if (null !== $relationshipRepository) {
            $relations = $relationshipRepository->findBySource(
                table: 'pages',
                uid: $pageUid,
                type: null,
            );

            $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');

            foreach ($relations as $relation) {
                // Der Typ wird bewusst geweitet: die Map deckt heute jeden
                // Enum-Fall ab, und genau deshalb haelt die Analyse den
                // Fallback fuer tot. Er ist es nicht — er ist das, was einen
                // spaeter ergaenzten Relationstyp ueberspringen laesst, statt
                // die Schema-Erzeugung der Seite mit einem undefinierten
                // Schluessel abbrechen zu lassen.
                $schemaProperty = $this->relationSchemaMap()[$relation->relationType->value] ?? null;
                if (null === $schemaProperty) {
                    continue;
                }

                $reference = [
                    '@type' => 'WebPage',
                    '@id' => $this->relationshipReferenceId($relation, $siteBase),
                ];

                if (isset($data[$schemaProperty])) {
                    $existing = $data[$schemaProperty];
                    if (is_array($existing) && array_is_list($existing)) {
                        $data[$schemaProperty][] = $reference;
                    } else {
                        $data[$schemaProperty] = [$existing, $reference];
                    }
                } else {
                    $data[$schemaProperty] = $reference;
                }
            }
        }

        return $data;
    }

    private function relationshipReferenceId(
        \Koh\StructuredContent\Domain\Model\ContentRelation $relation,
        string $siteBase,
    ): string {
        if ('pages' === $relation->targetTable && '' !== $siteBase) {
            return sprintf('%s/?id=%d', $siteBase, $relation->targetUid);
        }

        return sprintf('%s:%d', $relation->targetTable, $relation->targetUid);
    }

    /**
     * Die Map als `array<string, string>` statt als Literal-Shape.
     *
     * Sie deckt heute jeden Enum-Fall ab, und genau deshalb haelt die Analyse
     * den Fallback an der Aufrufstelle fuer tot. Er ist es nicht: er ist das,
     * was einen spaeter ergaenzten Relationstyp ueberspringen laesst, statt
     * die Schema-Erzeugung der Seite an einem undefinierten Schluessel
     * abbrechen zu lassen.
     *
     * @return array<string, string>
     */
    private function relationSchemaMap(): array
    {
        return self::RELATION_SCHEMA_MAP;
    }

}
