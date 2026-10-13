<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Infrastructure\SchemaOrg\SanitizesSchemaTrait;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
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
     * Route-Prefix der Author-Detail-Pages. Definiert in den Site-Route-
     * Enhancers (config/sites/<site>/config.yaml — Person plugin), hier als
     * Konstante damit Generators und Builder konsistente @id/url bauen
     * koennen ohne pro-Site-Setting (alle Sites nutzen aktuell /autor).
     */
    public const AUTHOR_PATH = '/autor';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
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

        // Person-Portrait aus dem moselwal_person.image-FAL-Reference. Wenn
        // keine Reference gepflegt, fehlt das Feld. URL-String statt
        // ImageObject — kompakter, Schema.org-Person akzeptiert beides.
        $imageUrl = $this->resolvePersonImageUrl((int) ($row['uid'] ?? 0), $siteBase);
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
     * Resolve a moselwal_person record by its slug (URL identifier).
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ('' === $slug) {
            return null;
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('moselwal_person');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $row = $queryBuilder
            ->select('*')
            ->from('moselwal_person')
            ->where(
                $queryBuilder->expr()->eq(
                    'moselwal_person_slug',
                    $queryBuilder->createNamedParameter($slug),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $row;
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
     * @return array<string, mixed>|null
     */
    private function fetchPerson(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('moselwal_person');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $row = $queryBuilder
            ->select('*')
            ->from('moselwal_person')
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $row;
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
     * FAL-Resolution fuer das moselwal_person.image-Feld. Returns absolute
     * URL als String — Schema.org-Person akzeptiert image sowohl als URL
     * als auch ImageObject; String ist kompakter und reicht fuer den
     * Knowledge-Graph-Use-Case.
     */
    private function resolvePersonImageUrl(int $personUid, string $siteBase): string
    {
        if ($personUid <= 0) {
            return '';
        }
        $qb = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $ref = $qb->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('moselwal_person')),
                $qb->expr()->eq('fieldname', $qb->createNamedParameter('image')),
                $qb->expr()->eq('uid_foreign', $qb->createNamedParameter($personUid, ParameterType::INTEGER)),
            )
            ->orderBy('sorting_foreign', 'ASC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if (!is_array($ref)) {
            return '';
        }

        $fqb = $this->connectionPool->getQueryBuilderForTable('sys_file');
        $fqb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $identifier = $fqb->select('identifier')
            ->from('sys_file')
            ->where(
                $fqb->expr()->eq('uid', $fqb->createNamedParameter((int) $ref['uid_local'], ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        if (!is_string($identifier) || '' === $identifier) {
            return '';
        }

        // Storage 1 = fileadmin/ (TYPO3-Default). identifier ist storage-
        // relativ (z.B. "/user_upload/team/portrait.jpg") → absolute Web-URL.
        return rtrim($siteBase, '/') . '/fileadmin' . $identifier;
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
