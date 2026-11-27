<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Infrastructure\SchemaOrg\SanitizesSchemaTrait;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Builds Schema.org/Person JSON-LD fragments from moselwal_person records
 * (RecordType moselwal/person from content-blocks-collection).
 *
 * Used by ArticleSchemaGenerator, SoftwareApplicationSchemaGenerator,
 * PersonSchemaGenerator and ReviewSchemaGenerator to keep the Person
 * mapping in one place.
 */
final class PersonSchemaBuilder implements SingletonInterface
{
    use SanitizesSchemaTrait;

    /**
     * Path of the Person entity's identifier below the site's entity base:
     * `<entityBase>/autor/<slug>#person`.
     *
     * This is an identifier, not a link that has to resolve in every
     * language: the Person is one entity per site, and every page in every
     * language references it by the same @id. It is the author page URL of
     * the default language. The URL of the author page in the language being
     * rendered is the ProfilePage's `url` and comes from the site router (see
     * ProfilePageSchemaGenerator).
     */
    public const AUTHOR_PATH = '/autor';

    private const TABLE = 'moselwal_person';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ?FileRepository $fileRepository = null,
    ) {}

    /**
     * Build a Person object from a single moselwal_person UID.
     *
     * @return array<string, mixed>|null null if record not found / empty
     */
    public function buildFromPersonUid(int $uid, string $siteBase = ''): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $row = $this->fetchPerson($uid);
        if (null === $row) {
            return null;
        }

        return $this->buildFromRow($row, $siteBase);
    }

    /**
     * Build Person objects from a list of UIDs (e.g. group field result).
     *
     * @param array<int, int|string> $uids a group field arrives as a CSV, so these
     *                                       can be strings
     *
     * @return list<array<string, mixed>>
     */
    public function buildFromPersonUids(array $uids, string $siteBase = ''): array
    {
        $persons = [];
        foreach ($uids as $uid) {
            $person = $this->buildFromPersonUid((int) $uid, $siteBase);
            if (null !== $person) {
                $persons[] = $person;
            }
        }

        return $persons;
    }

    /**
     * Resolve a comma-separated UID list (TYPO3 group field internal format)
     * into an integer array. Accepts strings like "5,12,3" or arrays.
     *
     * @return int[]
     */
    public function parseUidList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value), static fn(int $v): bool => $v > 0));
        }
        if (is_int($value)) {
            return $value > 0 ? [$value] : [];
        }
        if (!is_string($value) || '' === $value) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn(string $part): int => (int) trim($part), explode(',', $value)),
            static fn(int $v): bool => $v > 0,
        ));
    }

    /**
     * Build a minimal Person object from a free-text author name + optional email.
     * Used as fallback for legacy pages.author / pages.author_email fields.
     *
     * @return array<string, mixed>
     */
    public function buildFromAuthorTextField(string $name, ?string $email = null): array
    {
        $person = [
            '@type' => 'Person',
            'name' => $this->sanitizeText($name),
        ];
        if (null !== $email && '' !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $person['email'] = $email;
        }

        return $person;
    }

    /**
     * Build a Person object from a moselwal_person DB row.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    public function buildFromRow(array $row, string $siteBase = ''): ?array
    {
        $first = trim((string) ($row['moselwal_person_firstname'] ?? ''));
        $last = trim((string) ($row['moselwal_person_lastname'] ?? ''));
        $prefix = trim((string) ($row['moselwal_person_honorific_prefix'] ?? ''));
        $suffix = trim((string) ($row['moselwal_person_honorific_suffix'] ?? ''));

        $fullName = $this->composeFullName($row);
        if ('' === $fullName) {
            return null;
        }

        $person = [
            '@type' => 'Person',
            'name' => $this->sanitizeText($fullName),
        ];

        // Stable @id pro Domain: <siteBase>/autor/<slug>#person — kanonische
        // URL der Author-Detail-Page + #person-Fragment fuer die Schema.org-
        // Entity. Erlaubt Cross-Refs aus BlogPosting.author, PodcastEpisode.
        // actor etc. via @id statt Inline-Duplikation. Spec: jeder Author ist
        // eine stabile Entitaet pro Domain.
        $slug = trim((string) ($row['moselwal_person_slug'] ?? ''));
        $authorUrl = '';
        if ('' !== $siteBase && '' !== $slug) {
            $authorUrl = rtrim($siteBase, '/') . self::AUTHOR_PATH . '/' . $slug;
            $person['@id'] = $authorUrl . '#person';
        }

        if ('' !== $first) {
            $person['givenName'] = $this->sanitizeText($first);
        }
        if ('' !== $last) {
            $person['familyName'] = $this->sanitizeText($last);
        }
        if ('' !== $prefix) {
            $person['honorificPrefix'] = $this->sanitizeText($prefix);
        }
        if ('' !== $suffix) {
            $person['honorificSuffix'] = $this->sanitizeText($suffix);
        }

        $jobTitle = trim((string) ($row['moselwal_person_job_title'] ?? ''));
        if ('' !== $jobTitle) {
            $person['jobTitle'] = $this->sanitizeText($jobTitle);
        }

        $bio = trim((string) ($row['moselwal_person_bio'] ?? ''));
        if ('' !== $bio) {
            $person['description'] = $this->sanitizeText($bio);
        }

        $email = trim((string) ($row['moselwal_person_email'] ?? ''));
        if ('' !== $email && false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $person['email'] = $email;
        }

        $telephone = trim((string) ($row['moselwal_person_telephone'] ?? ''));
        if ('' !== $telephone) {
            $person['telephone'] = $telephone;
        }

        // url-Feld: bevorzugt die kanonische Author-Detail-Page (intern),
        // weil Schema.org dort die volle Person-Entity wiederfindet. Eine
        // im Record gepflegte externe URL (moselwal_person_url) wird in
        // sameAs umgehaengt — Person.url soll EINE primaere URL sein,
        // mehrere externe Identitaeten gehoeren zu sameAs.
        $externalUrl = trim((string) ($row['moselwal_person_url'] ?? ''));
        $externalUrlValid = '' !== $externalUrl
            && (str_starts_with($externalUrl, 'http://') || str_starts_with($externalUrl, 'https://'));
        if ('' !== $authorUrl) {
            $person['url'] = $authorUrl;
        } elseif ($externalUrlValid) {
            $person['url'] = $externalUrl;
        }

        // Portrait from the record's image field. Without a reference the
        // property is left out. A URL string rather than an ImageObject:
        // Schema.org Person accepts both, and the string is enough for the
        // knowledge-graph use case.
        $imageUrl = $this->resolvePersonImageUrl($row, $siteBase);
        if ('' !== $imageUrl) {
            $person['image'] = $imageUrl;
        }

        $worksForLines = $this->splitLines((string) ($row['moselwal_person_works_for'] ?? ''));
        if ([] !== $worksForLines) {
            $orgs = array_map(
                fn(string $name): array => ['@type' => 'Organization', 'name' => $this->sanitizeText($name)],
                $worksForLines,
            );
            $person['worksFor'] = 1 === count($orgs) ? $orgs[0] : $orgs;
        } elseif ('' !== $siteBase) {
            $person['worksFor'] = ['@id' => rtrim($siteBase, '/') . '#organization'];
        }

        // sameAs lines may be plain URLs ("https://…") or carry the optional
        // "Label|URL" syntax used by SocialLinksViewHelper for richer display.
        // Schema.org sameAs only takes URLs — strip the label, drop emails
        // and unrecognised values silently. Plain emails belong to `email`.
        $rawLines = $this->splitLines((string) ($row['moselwal_person_same_as'] ?? ''));
        $sameAs = [];
        // External-URL aus moselwal_person_url wandert hierhin wenn die
        // kanonische Author-Page als `url` greift (siehe oben).
        if ('' !== $authorUrl && $externalUrlValid) {
            $sameAs[] = $externalUrl;
        }
        foreach ($rawLines as $line) {
            $candidate = $line;
            if (str_contains($candidate, '|')) {
                $parts = explode('|', $candidate, 2);
                $candidate = trim($parts[1] ?? '');
            }
            if ('' === $candidate) {
                continue;
            }
            if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
                $sameAs[] = $candidate;
            }
        }
        if ([] !== $sameAs) {
            $person['sameAs'] = array_values(array_unique($sameAs));
        }

        $knowsAbout = $this->splitLines((string) ($row['moselwal_person_knows_about'] ?? ''));
        if ([] !== $knowsAbout) {
            $person['knowsAbout'] = $knowsAbout;
        }

        $knowsLanguage = trim((string) ($row['moselwal_person_knows_language'] ?? ''));
        if ('' !== $knowsLanguage) {
            $langs = array_values(array_filter(
                array_map('trim', explode(',', $knowsLanguage)),
                static fn(string $l): bool => '' !== $l,
            ));
            if (1 === count($langs)) {
                $person['knowsLanguage'] = $langs[0];
            } elseif ([] !== $langs) {
                $person['knowsLanguage'] = $langs;
            }
        }

        $alumniOf = $this->splitLines((string) ($row['moselwal_person_alumni_of'] ?? ''));
        if ([] !== $alumniOf) {
            $person['alumniOf'] = array_map(
                static fn(string $name): array => ['@type' => 'Organization', 'name' => $name],
                $alumniOf,
            );
        }

        return $person;
    }

    /**
     * Public counterpart to the internal lookup, exposed so that detail-page
     * generators (e.g. ProfilePageSchemaGenerator) can re-use the same fetch
     * with sane restrictions instead of duplicating the query.
     *
     * @return array<string, mixed>|null
     */
    public function fetchPersonRow(int $uid): ?array
    {
        return $this->fetchPerson($uid);
    }

    /**
     * Resolve a moselwal_person record by its slug (URL identifier), in the
     * language of the current request.
     *
     * A translation usually carries the slug of its default-language record,
     * so the lookup is made on the default-language records (and records for
     * all languages) and the result is overlaid, as the frontend does for any
     * record. Only when no such record has the slug is a translation in the
     * current language with that slug taken, and its default-language record
     * overlaid in turn.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ('' === $slug) {
            return null;
        }
        $languageField = self::languageField();

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'moselwal_person_slug',
                    $queryBuilder->createNamedParameter($slug),
                ),
            );
        if ('' !== $languageField) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->in(
                    $languageField,
                    $queryBuilder->createNamedParameter([0, -1], ArrayParameterType::INTEGER),
                ),
            );
        }
        $row = $queryBuilder
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (false !== $row) {
            return $this->localize($row);
        }

        $parentUid = $this->findParentOfTranslationBySlug($slug);

        return $parentUid > 0 ? $this->fetchPerson($parentUid) : null;
    }

    /**
     * Resolve a moselwal_person record by an identifier that may be either
     * the URL slug or the numeric UID. The site routing aspect
     * `PersistedAliasMapper` (configured in moselwal-de config.yaml for the
     * `/autor/{person}` route) resolves the slug to the persistent UID
     * BEFORE the request reaches our middlewares — so what arrives in
     * `$pageData['_routingArguments']['tx_contentblockscollection_person']
     * ['person']` is the numeric UID, not the original slug. Accepting both
     * forms here lets the call site stay agnostic of the routing mode.
     *
     * @return array<string, mixed>|null
     */
    public function findByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ('' === $identifier) {
            return null;
        }

        if (ctype_digit($identifier)) {
            return $this->fetchPerson((int) $identifier);
        }

        return $this->findBySlug($identifier);
    }

    /**
     * The record with this uid, overlaid with its translation into the
     * language of the current request.
     *
     * @return array<string, mixed>|null
     */
    private function fetchPerson(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $this->localize($row);
    }

    /**
     * The default-language uid of a translation in the current language that
     * carries the slug, 0 if there is none.
     */
    private function findParentOfTranslationBySlug(string $slug): int
    {
        $languageField = self::languageField();
        $pointerField = (string) ($GLOBALS['TCA'][self::TABLE]['ctrl']['transOrigPointerField'] ?? '');
        $languageId = self::currentLanguage()->getContentId();
        if ('' === $languageField || '' === $pointerField || $languageId <= 0) {
            return 0;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $parent = $queryBuilder
            ->select($pointerField)
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('moselwal_person_slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->gt($pointerField, $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($parent) ? (int) $parent : 0;
    }

    /**
     * Overlays a default-language row with its translation into the language
     * of the current request — PageRepository::getLanguageOverlay(), the
     * frontend's own overlay, so the site language's fallback chain and
     * overlay mode apply. Without a translation the default-language row is
     * kept (also for a strict language, which would hide it): a person is
     * shown in the default language rather than not at all.
     *
     * The slug stays the default-language one. It is part of the Person's
     * @id, and that identifier is the same on every page in every language;
     * a translation that carries a slug of its own must not split the person
     * into one entity per language.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function localize(array $row): array
    {
        if ('' === self::languageField()) {
            return $row;
        }
        $language = self::currentLanguage();
        if ($language->getContentId() <= 0) {
            return $row;
        }

        $overlaid = GeneralUtility::makeInstance(PageRepository::class)
            ->getLanguageOverlay(self::TABLE, $row, $language);
        if (null === $overlaid) {
            return $row;
        }
        if (array_key_exists('moselwal_person_slug', $row)) {
            $overlaid['moselwal_person_slug'] = $row['moselwal_person_slug'];
        }

        return $overlaid;
    }

    /**
     * The language field of the person table, '' when the table is not
     * translatable (or its TCA is not loaded).
     */
    private static function languageField(): string
    {
        return (string) ($GLOBALS['TCA'][self::TABLE]['ctrl']['languageField'] ?? '');
    }

    /**
     * The language the frontend renders the request in. Read from the context
     * on every call: the builder is a shared service and outlives a request.
     */
    private static function currentLanguage(): LanguageAspect
    {
        return GeneralUtility::makeInstance(Context::class)->getAspect('language');
    }

    /**
     * Liefert eine minimale Person fuer Cross-Refs (Article.author,
     * PodcastEpisode.actor, …): NUR `@type` + `@id` ohne Inline-Duplikation
     * der Person-Daten. Crawler die im selben Graph oder ueber die Author-
     * Detail-URL die volle Entity finden, resolven die Identitaet ueber @id.
     *
     * Returns null wenn der Record keinen Slug hat (kein stabiles @id baubar) —
     * Caller faellt dann auf buildFromPersonUid() zurueck (inline Person).
     *
     * @return array<string, mixed>|null
     */
    public function buildPersonReference(int $uid, string $siteBase): ?array
    {
        if ($uid <= 0 || '' === $siteBase) {
            return null;
        }
        $row = $this->fetchPerson($uid);
        if (null === $row) {
            return null;
        }
        $slug = trim((string) ($row['moselwal_person_slug'] ?? ''));
        if ('' === $slug) {
            return null;
        }

        $ref = [
            '@type' => 'Person',
            '@id' => rtrim($siteBase, '/') . self::AUTHOR_PATH . '/' . $slug . '#person',
        ];

        // Inline-`name` zusaetzlich zur @id-Referenz: Die volle Person-Entity
        // steht nur auf /autor/<slug>. Cross-Page-@id-Aufloesung ist bei
        // Suchmaschinen und LLM-Crawlern NICHT garantiert — ohne den Namen
        // sieht ein Per-Page-Parser (inkl. Google Rich Results Test) einen
        // Author ohne Namen, was die Article-Author-Anforderung verfehlt.
        // Der Row ist ohnehin schon geladen, der Name kostet keine Query.
        $name = $this->composeFullName($row);
        if ('' !== $name) {
            $ref['name'] = $this->sanitizeText($name);
        }

        return $ref;
    }

    /**
     * Whether a free-text name denotes this person: the full display name or
     * "first last", compared case-insensitively.
     *
     * @param array<string, mixed> $row
     */
    public function isNamed(array $row, string $name): bool
    {
        $name = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
        if ('' === $name) {
            return false;
        }
        $first = trim((string) ($row['moselwal_person_firstname'] ?? ''));
        $last = trim((string) ($row['moselwal_person_lastname'] ?? ''));
        $candidates = [$this->composeFullName($row), trim($first . ' ' . $last)];
        foreach ($candidates as $candidate) {
            if ('' !== $candidate && mb_strtolower($candidate) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Setzt den Anzeigenamen aus den Namensfeldern zusammen.
     *
     * @param array<string, mixed> $row
     */
    private function composeFullName(array $row): string
    {
        $prefix = trim((string) ($row['moselwal_person_honorific_prefix'] ?? ''));
        $first = trim((string) ($row['moselwal_person_firstname'] ?? ''));
        $last = trim((string) ($row['moselwal_person_lastname'] ?? ''));
        $suffix = trim((string) ($row['moselwal_person_honorific_suffix'] ?? ''));

        $full = implode(' ', array_filter([$prefix, $first, $last], static fn(string $p): bool => '' !== $p));
        if ('' !== $suffix && '' !== $full) {
            $full .= ', ' . $suffix;
        }

        return trim($full);
    }

    /**
     * Absolute URL of the person's portrait, '' without one.
     *
     * The field is taken from the TCA: the person record type declares it as
     * `image`, which Content Blocks stores as `moselwal_person_image` (the
     * table prefix is added to every field). Every file field named `image`
     * or ending in `_image` qualifies, so a record type registered without
     * the prefix keeps working. Looking for `image` alone found nothing on
     * any site and left every Person without an image.
     *
     * The file references of a translation hang on the translation's uid;
     * without any there, the default-language record's portrait is used. The
     * URL comes from FAL, so a storage other than fileadmin (S3, a CDN) yields
     * its own public URL; a root-relative URL is resolved against the site.
     *
     * @param array<string, mixed> $row
     */
    private function resolvePersonImageUrl(array $row, string $siteBase): string
    {
        $fields = self::imageFields();
        if ([] === $fields) {
            return '';
        }
        $uids = array_values(array_unique(array_filter(
            [(int) ($row['_LOCALIZED_UID'] ?? 0), (int) ($row['uid'] ?? 0)],
            static fn(int $uid): bool => $uid > 0,
        )));

        try {
            $fileRepository = $this->fileRepository ?? GeneralUtility::makeInstance(FileRepository::class);
            foreach ($uids as $uid) {
                foreach ($fields as $field) {
                    $references = $fileRepository->findByRelation(self::TABLE, $field, $uid);
                    if ([] === $references) {
                        continue;
                    }
                    $publicUrl = trim((string) $references[0]->getPublicUrl());
                    if ('' === $publicUrl) {
                        continue;
                    }
                    if (1 !== preg_match('#^(https?:)?//#i', $publicUrl)) {
                        $publicUrl = '/' . ltrim($publicUrl, '/');
                    }

                    return SiteFileUrl::resolve($publicUrl, $siteBase);
                }
            }
        } catch (\Throwable) {
            // A missing file or storage costs the image, not the Person.
            return '';
        }

        return '';
    }

    /**
     * The file fields of the person table that hold its portrait.
     *
     * @return list<string>
     */
    private static function imageFields(): array
    {
        $columns = $GLOBALS['TCA'][self::TABLE]['columns'] ?? null;
        if (!is_array($columns)) {
            return [];
        }
        $fields = [];
        foreach ($columns as $name => $column) {
            $name = (string) $name;
            if ('image' !== $name && !str_ends_with($name, '_image')) {
                continue;
            }
            $config = is_array($column) && is_array($column['config'] ?? null) ? $column['config'] : [];
            $isFileField = 'file' === ($config['type'] ?? null)
                || ('inline' === ($config['type'] ?? null) && 'sys_file_reference' === ($config['foreign_table'] ?? null));
            if ($isFileField) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function splitLines(string $value): array
    {
        if ('' === $value) {
            return [];
        }
        $lines = preg_split('/\r?\n/', $value);
        if (false === $lines) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn(string $l): bool => '' !== $l,
        ));
    }
}
