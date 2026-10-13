<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

/**
 * Emits Schema.org/SoftwareApplication (or SoftwareSourceCode) for
 * open-source pages — typically /open-source/<slug>.
 *
 * Picks up the dedicated TCA fields:
 *   - tx_structuredcontent_code_repository  → codeRepository
 *   - tx_structuredcontent_programming_language → programmingLanguage
 *   - tx_structuredcontent_software_version → softwareVersion
 *   - tx_structuredcontent_software_license → license (URL or SPDX id)
 *   - tx_structuredcontent_operating_system → operatingSystem
 *
 * Activated either by manual override
 * (pages.tx_structuredcontent_schema_type = 'SoftwareApplication') or by
 * slug pattern /open-source/<sub>.
 */
final class SoftwareApplicationSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use ContextAndRelationshipsTrait;

    public function __construct(
        private readonly PersonSchemaBuilder $personBuilder,
        private readonly ?ContextResolverInterface $contextResolver = null,
        private readonly ?RelationshipRepositoryInterface $relationshipRepository = null,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $isRootPage = (int) ($pageData['uid'] ?? 0) === (int) ($pageData['_rootPageId'] ?? -1);
        if ($isRootPage) {
            return false;
        }

        // Manual override wins.
        $override = trim((string) ($pageData['tx_structuredcontent_schema_type'] ?? ''));
        if ('SoftwareApplication' === $override || 'SoftwareSourceCode' === $override) {
            return true;
        }
        if ('' !== $override && 'SoftwareApplication' !== $override && 'SoftwareSourceCode' !== $override) {
            return false;
        }

        // Slug detection — /open-source/<sub>
        $slug = strtolower((string) ($pageData['slug'] ?? ''));
        if (str_starts_with($slug, '/open-source/') || str_starts_with($slug, '/opensource/')) {
            return true;
        }

        // Pages that have a code repository defined are software regardless.
        $repo = trim((string) ($pageData['tx_structuredcontent_code_repository'] ?? ''));

        return '' !== $repo;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $override = trim((string) ($pageData['tx_structuredcontent_schema_type'] ?? ''));
        $schemaType = 'SoftwareSourceCode' === $override ? 'SoftwareSourceCode' : 'SoftwareApplication';

        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);
        $url = (string) ($pageData['canonical_link'] ?? '');
        if ('' === $url && '' !== $siteBase) {
            $url = $siteBase . ((string) ($pageData['slug'] ?? ''));
        }

        $data = [
            'name' => $this->sanitizeText((string) ($pageData['title'] ?? '')),
            'description' => $this->sanitizeText((string) ($pageData['description'] ?? $pageData['abstract'] ?? '')),
            'url' => $url,
        ];

        $repo = trim((string) ($pageData['tx_structuredcontent_code_repository'] ?? ''));
        if ('' !== $repo) {
            $data['codeRepository'] = $repo;
        }

        $lang = trim((string) ($pageData['tx_structuredcontent_programming_language'] ?? ''));
        if ('' !== $lang) {
            // Allow comma-separated list (e.g. "PHP, JavaScript")
            $langs = array_values(array_filter(
                array_map('trim', explode(',', $lang)),
                static fn(string $l): bool => '' !== $l,
            ));
            $data['programmingLanguage'] = 1 === count($langs) ? $langs[0] : $langs;
        }

        $version = trim((string) ($pageData['tx_structuredcontent_software_version'] ?? ''));
        if ('' !== $version) {
            $data['softwareVersion'] = $version;
        }

        $license = trim((string) ($pageData['tx_structuredcontent_software_license'] ?? ''));
        if ('' !== $license) {
            // SPDX id like "MIT" → derive license URL; URL passes through.
            $data['license'] = $this->normaliseLicense($license);
        }

        $os = trim((string) ($pageData['tx_structuredcontent_operating_system'] ?? ''));
        if ('' !== $os) {
            $data['operatingSystem'] = $os;
        }

        $authorUids = $this->personBuilder->parseUidList($pageData['tx_structuredcontent_author_records'] ?? null);
        if ([] !== $authorUids) {
            // @id-Ref auf die Author-Detail-Page bevorzugt — vermeidet
            // Inline-Duplikation in jedem Article/Software-Knoten.
            $persons = [];
            foreach ($authorUids as $uid) {
                $ref = $this->personBuilder->buildPersonReference($uid, $entityBase);
                if (null !== $ref) {
                    $persons[] = $ref;

                    continue;
                }
                $inline = $this->personBuilder->buildFromPersonUid($uid, $entityBase);
                if (null !== $inline) {
                    $persons[] = $inline;
                }
            }
            if ([] !== $persons) {
                $data['author'] = 1 === count($persons) ? $persons[0] : $persons;
            }
        }
        if (!isset($data['author']) && isset($pageData['author']) && '' !== $pageData['author']) {
            $data['author'] = $this->personBuilder->buildFromAuthorTextField(
                (string) $pageData['author'],
                isset($pageData['author_email']) ? (string) $pageData['author_email'] : null,
            );
        }

        if (isset($pageData['crdate']) && (int) $pageData['crdate'] > 0) {
            $data['datePublished'] = date('c', (int) $pageData['crdate']);
        }
        if (isset($pageData['tstamp']) && (int) $pageData['tstamp'] > 0) {
            $data['dateModified'] = date('c', (int) $pageData['tstamp']);
        }

        if ('' !== $siteBase) {
            $data['publisher'] = [
                '@type' => 'Organization',
                '@id' => $entityBase . '#organization',
            ];
        }

        $data = $this->enrichWithContextAndRelationships(
            data: $data,
            pageData: $pageData,
            contextResolver: $this->contextResolver,
            relationshipRepository: $this->relationshipRepository,
        );

        return new JsonLdDocument($schemaType, array_filter(
            $data,
            static fn($v): bool => '' !== $v && [] !== $v,
        ));
    }

    public function getPriority(): int
    {
        return 52;
    }

    private function normaliseLicense(string $license): string
    {
        if (str_starts_with($license, 'http://') || str_starts_with($license, 'https://')) {
            return $license;
        }

        // SPDX shorthand — point to the canonical SPDX URL.
        return 'https://spdx.org/licenses/' . $license . '.html';
    }
}
