<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

/**
 * Emits the **standalone Person-Entity** on Author-Detail-Pages
 * (/autor/<slug>) — parallel zur ProfilePage. Notwendig damit
 * Article/Podcast-Cross-Refs aus anderen Pages den Person-Knoten
 * im JSON-LD-Graph aufloesen koennen (via @id).
 *
 * Trigger: routing argument tx_contentblockscollection_person.person
 * resolves zu einem moselwal_person-Record. Identisch zum Trigger des
 * ProfilePageSchemaGenerator — beide Generatoren feuern auf der
 * Author-Page und liefern komplementaere Schema-Entities.
 */
final class PersonPageSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use PersonRoutingArgumentTrait;

    public function __construct(
        private readonly PersonSchemaBuilder $personBuilder,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $identifier = $this->resolvePersonSlug($pageData);
        if ('' === $identifier) {
            return false;
        }

        return null !== $this->personBuilder->findByIdentifier($identifier);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $identifier = $this->resolvePersonSlug($pageData);
        $row = $this->personBuilder->findByIdentifier($identifier);
        if (null === $row) {
            return new JsonLdDocument('Person', []);
        }

        $person = $this->personBuilder->buildFromRow($row, EntityBase::fromPageData($pageData));
        if (null === $person) {
            return new JsonLdDocument('Person', []);
        }

        // @type ist schon im person-Array (buildFromRow setzt es), aber
        // JsonLdDocument akzeptiert das — buildGraphScriptTag merged
        // saubere @type-Werte.
        unset($person['@type']);

        return new JsonLdDocument('Person', $person);
    }

    public function getPriority(): int
    {
        // Hoeher als ProfilePage (75): die standalone Person-Entity sollte
        // im Graph vor der ProfilePage stehen, damit mainEntity.@id direkt
        // aufgeloest werden kann.
        return 80;
    }
}
