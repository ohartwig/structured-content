<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Domain\Contract\AuthoredPagesRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

// PersonSchemaBuilder::AUTHOR_PATH wird unten verwendet — bewusst ueber
// die Konstante statt hardcoded '/autor', damit beide Generatoren konsistent
// bleiben wenn der Pfad mal global geaendert wird.

/**
 * Emits Schema.org/ProfilePage for /autor/<slug> detail pages.
 *
 * Trigger: routing argument tx_contentblockscollection_person.person resolves
 * to a moselwal_person record (via PersonSchemaBuilder::findBySlug). When that
 * record exists, the generator wins over Article/WebPage and emits a
 * ProfilePage with the Person as mainEntity plus a hasPart array of recent
 * articles authored by that person (Reverse-Lookup over
 * pages.tx_structuredcontent_author_records).
 */
final class ProfilePageSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use PersonRoutingArgumentTrait;

    public function __construct(
        private readonly PersonSchemaBuilder $personBuilder,
        private readonly AuthoredPagesRepositoryInterface $authoredPagesRepository,
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
            return new JsonLdDocument('ProfilePage', []);
        }

        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);
        // PersistedAliasMapper translates the URL slug to the UID before our
        // middlewares see the route arguments — the slug for URL-Building
        // therefore has to come from the resolved row, not from $identifier
        // (which may already be the numeric UID).
        $slug = (string) ($row['moselwal_person_slug'] ?? '');

        // Die volle Person-Entity emittiert PersonPageSchemaGenerator parallel
        // im selben Graph — hier nur @id-Ref damit der Output nicht doppelt
        // serialisiert wird. Crawler resolven mainEntity.@id zum Person-Knoten.
        $authorUrl = '' !== $siteBase && '' !== $slug
            ? $siteBase . PersonSchemaBuilder::AUTHOR_PATH . '/' . $slug
            : '';
        // The Person is a site-wide entity: its @id carries no language prefix.
        $personRef = '' !== $entityBase && '' !== $slug
            ? ['@id' => $entityBase . PersonSchemaBuilder::AUTHOR_PATH . '/' . $slug . '#person']
            : null;

        $languageId = (int) ($pageData['sys_language_uid'] ?? 0);
        $articles = $this->authoredPagesRepository->findByAuthorUid((int) $row['uid'], $languageId, 50);

        $hasPart = [];
        foreach ($articles as $article) {
            $title = trim((string) ($article['title'] ?? ''));
            if ('' === $title) {
                continue;
            }
            $articleSlug = (string) ($article['slug'] ?? '');
            $url = $siteBase . $articleSlug;
            $entry = [
                '@type' => 'BlogPosting',
                'headline' => $this->sanitizeText($title),
                'url' => $url,
            ];
            $description = '' !== trim((string) ($article['abstract'] ?? ''))
                ? (string) $article['abstract']
                : (string) ($article['description'] ?? '');
            if ('' !== $description) {
                $entry['description'] = $this->sanitizeText($description);
            }
            $tstamp = (int) ($article['tstamp'] ?? 0);
            if ($tstamp > 0) {
                $entry['datePublished'] = date('c', $tstamp);
            }
            $hasPart[] = $entry;
        }

        $data = [];
        // Eigener @id mit #webpage-Fragment — damit der Graph-Builder den
        // ProfilePage-Knoten dieser Detail-URL nicht mit dem WebPage-Knoten
        // verwechselt, den WebPageSchemaGenerator parallel fuer dieselbe
        // Page-UID emittiert (er nutzt den Container-Slug, daher kein
        // @id-Clash). Pattern matched PersonPageSchemaGenerator (#person).
        if ('' !== $authorUrl) {
            $data['@id'] = $authorUrl . '#webpage';
        }
        if (null !== $personRef) {
            $data['mainEntity'] = $personRef;
        }
        // Name aus dem Row direkt — ohne den vollen Person-Build, weil mainEntity
        // jetzt nur @id-Ref ist und buildFromRow nicht mehr aufgerufen wird.
        $first = trim((string) ($row['moselwal_person_firstname'] ?? ''));
        $last = trim((string) ($row['moselwal_person_lastname'] ?? ''));
        $displayName = trim($first . ' ' . $last);
        $data['name'] = '' !== $displayName ? $this->sanitizeText($displayName) : $slug;
        if ('' !== $authorUrl) {
            $data['url'] = $authorUrl;
        }
        if ('' !== $siteBase) {
            $data['isPartOf'] = ['@id' => $entityBase . '#website'];
        }
        $language = trim((string) ($pageData['_language'] ?? ''));
        if ('' !== $language) {
            $data['inLanguage'] = $language;
        }
        $personTstamp = (int) ($row['tstamp'] ?? 0);
        if ($personTstamp > 0) {
            $data['dateModified'] = date('c', $personTstamp);
        }
        $personCrdate = (int) ($row['crdate'] ?? 0);
        if ($personCrdate > 0) {
            $data['datePublished'] = date('c', $personCrdate);
        }
        if ([] !== $hasPart) {
            $data['hasPart'] = $hasPart;
        }

        return new JsonLdDocument('ProfilePage', $data);
    }

    public function getPriority(): int
    {
        return 75;
    }
}
