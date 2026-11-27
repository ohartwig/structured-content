<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Domain\Contract\AuthoredPagesRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\ClaimsPageEntityInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

/**
 * Emits Schema.org/ProfilePage for author detail pages (`/autor/<slug>`,
 * `/en/author/<slug>`).
 *
 * Trigger: routing argument tx_contentblockscollection_person.person resolves
 * to a moselwal_person record (via PersonSchemaBuilder::findBySlug). When that
 * record exists, the generator wins over Article/WebPage — it claims the page
 * entity (ClaimsPageEntityInterface), so SchemaOrgService drops their nodes
 * for this URL — and emits a ProfilePage with the Person as mainEntity plus a
 * hasPart array of the person's newest articles (reverse lookup over
 * pages.tx_structuredcontent_author_records).
 */
final class ProfilePageSchemaGenerator implements ClaimsPageEntityInterface
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

        // The full Person entity is emitted by PersonPageSchemaGenerator in the
        // same graph; mainEntity is only its @id. The Person is a site-wide
        // entity: its @id carries no language prefix and is the same on every
        // page in every language.
        $personRef = '' !== $entityBase && '' !== $slug
            ? ['@id' => $entityBase . PersonSchemaBuilder::AUTHOR_PATH . '/' . $slug . '#person']
            : null;
        // The ProfilePage is the page being rendered, so its URL is that
        // page's URL in the language being rendered (`/en/author/<slug>`), not
        // the Person's identifier path below the language base, which
        // produced `/en/autor/<slug>` — a 404.
        $profileUrl = $this->resolveProfileUrl($pageData, $siteBase, $slug);

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
            // Published at crdate, the date the article's own BlogPosting node
            // carries (ArticleSchemaGenerator) and the order of the list.
            // Modified at the editor-maintained "last updated" date, else the
            // record's tstamp. tstamp as datePublished made every edit look
            // like a publication.
            $published = (int) ($article['crdate'] ?? 0);
            if ($published > 0) {
                $entry['datePublished'] = date('c', $published);
            }
            $modified = (int) ($article['lastUpdated'] ?? 0);
            if ($modified <= 0) {
                $modified = (int) ($article['tstamp'] ?? 0);
            }
            if ($modified > 0) {
                $entry['dateModified'] = date('c', $modified);
            }
            $hasPart[] = $entry;
        }

        $data = [];
        // The page entity of this URL: `<url>#webpage`, the pattern every
        // page entity follows. The competing WebPage and Article nodes of the
        // container page are dropped (ClaimsPageEntityInterface).
        if ('' !== $profileUrl) {
            $data['@id'] = $profileUrl . '#webpage';
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
        if ('' !== $profileUrl) {
            $data['url'] = $profileUrl;
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

    /**
     * The URL of the author page being rendered: the URL the site router
     * builds for the page and its route arguments in the current language
     * (`_routeUrl`, set by the middleware), so the translated page slug and
     * the route enhancer's path apply. Without it — a caller outside the
     * middleware — the page's own slug below the language base stands in,
     * followed by the person's slug, which is the shape of the `/{person}`
     * route the plugin ships with.
     *
     * @param array<string, mixed> $pageData
     */
    private function resolveProfileUrl(array $pageData, string $siteBase, string $slug): string
    {
        $routeUrl = trim((string) ($pageData['_routeUrl'] ?? ''));
        if (1 === preg_match('#^https?://#i', $routeUrl)) {
            return $routeUrl;
        }
        if ('' === $siteBase || '' === $slug) {
            return '';
        }
        $pageSlug = rtrim((string) ($pageData['slug'] ?? ''), '/');

        return $siteBase . ('' === $pageSlug ? '' : '/' . ltrim($pageSlug, '/')) . '/' . $slug;
    }

    public function getPriority(): int
    {
        return 75;
    }
}
