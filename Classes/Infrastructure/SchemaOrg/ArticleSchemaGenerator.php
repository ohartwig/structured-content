<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use Koh\StructuredContent\Domain\Contract\CategoryRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ArticleSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use ContextAndRelationshipsTrait;
    use PageLevelExtraSchemaTrait;

    /**
     * Page fields whose first file reference becomes the article image, in
     * order of preference.
     */
    private const array IMAGE_FIELDS = ['media', 'og_image'];

    public function __construct(
        private readonly SchemaTypeResolverService $schemaTypeResolver,
        private readonly PersonSchemaBuilder $personBuilder,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ?ContextResolverInterface $contextResolver = null,
        private readonly ?RelationshipRepositoryInterface $relationshipRepository = null,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $resolved = $this->schemaTypeResolver->resolve($pageData, $contentElements);
        if (null === $resolved) {
            return false;
        }

        return 'article' === $this->schemaTypeResolver->getFamily($resolved);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $schemaType = $this->schemaTypeResolver->resolve($pageData, $contentElements) ?? 'Article';

        $bodyParts = [];
        $totalWords = 0;
        foreach ($contentElements as $element) {
            // header + subheader + bodytext count towards the article word
            // count so the value matches the FE reading-time badge. Bodytext
            // is also kept as articleBody plain-text for Schema.org parsers
            // that consume it directly.
            foreach (['header', 'subheader', 'bodytext'] as $field) {
                $raw = (string) ($element[$field] ?? '');
                if ('' === $raw) {
                    continue;
                }
                if ('bodytext' === $field) {
                    $bodyParts[] = trim(strip_tags($raw));
                }
                $totalWords += self::countWords($raw);
            }
        }

        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);
        $pageUrl = $this->resolvePageUrl($pageData, $siteBase);

        $data = [];
        // Stable @id: <pageUrl>#blogposting — erlaubt Cross-Refs zur Article-
        // Entity aus anderen Nodes (z.B. einer FAQPage auf derselben Page).
        if ('' !== $pageUrl) {
            $data['@id'] = $pageUrl . '#blogposting';
        }
        $data['headline'] = $this->sanitizeText($pageData['title'] ?? '');
        // Nicht `??`: eine leere Spalte kommt aus TYPO3 als '' zurueck, nicht
        // als null, und dann greift der Coalesce nie — der Anriss war als
        // Ersatz gedacht und kam nie zum Zug.
        $description = trim((string) ($pageData['description'] ?? ''));
        if ('' === $description) {
            $description = trim((string) ($pageData['abstract'] ?? ''));
        }
        $data['description'] = $this->sanitizeText($description);

        // mainEntityOfPage: @id-Ref zur WebPage-Entity mit #webpage-Suffix
        // (passt zum WebPageSchemaGenerator-@id-Format) — verhindert die
        // ambiguous @id-Doppel-Vergabe URL=Article.@id=WebPage.@id.
        if ('' !== $pageUrl) {
            $data['mainEntityOfPage'] = ['@id' => $pageUrl . '#webpage'];
        }

        // isPartOf: Article ist Teil der WebSite. Spec-Empfehlung — schliesst
        // die Kette Article → WebSite → Organization.
        if ('' !== $siteBase) {
            $data['isPartOf'] = ['@id' => $entityBase . '#website'];
        }

        $authorUids = $this->personBuilder->parseUidList($pageData['tx_structuredcontent_author_records'] ?? null);
        if ([] !== $authorUids) {
            // Bevorzugt @id-Refs auf die Author-Detail-Page — die volle Person-
            // Entity emittiert PersonPageSchemaGenerator auf /autor/<slug>, hier
            // reicht der Cross-Ref. Slug-lose Authors (Legacy/External) fallen
            // auf inline buildFromPersonUid zurueck damit die Person sichtbar
            // bleibt (Spec: Article.author muss aufloesbar sein).
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
        // Without a maintained author record, the site's default author steps
        // in — unless the free-text author names somebody else.
        if (!isset($data['author'])) {
            $defaultAuthor = $this->resolveDefaultAuthor($pageData, $entityBase);
            if (null !== $defaultAuthor) {
                $data['author'] = $defaultAuthor;
            }
        }
        if (!isset($data['author']) && isset($pageData['author']) && '' !== $pageData['author']) {
            $data['author'] = $this->personBuilder->buildFromAuthorTextField(
                (string) $pageData['author'],
                isset($pageData['author_email']) ? (string) $pageData['author_email'] : null,
            );
        }

        if ('' !== $siteBase) {
            $data['publisher'] = ['@id' => $entityBase . '#organization'];
        }

        $categories = $this->categoryRepository->findByPageUid((int) ($pageData['uid'] ?? 0));
        if ([] !== $categories) {
            $names = array_values(array_filter(array_map(
                static fn(array $c): string => trim($c['title']),
                $categories,
            ), static fn(string $n): bool => '' !== $n));
            if ([] !== $names) {
                $data['articleSection'] = 1 === count($names) ? $names[0] : $names;
            }
        }

        if (isset($pageData['crdate']) && '' !== $pageData['crdate']) {
            $data['datePublished'] = date('c', (int) $pageData['crdate']);
        }

        if (isset($pageData['tstamp']) && '' !== $pageData['tstamp']) {
            $data['dateModified'] = date('c', (int) $pageData['tstamp']);
        }

        // Sprache als BCP47 (de-DE / en-GB) — Spec: konsistent pro Domain.
        $language = trim((string) ($pageData['_language'] ?? ''));
        if ('' !== $language) {
            $data['inLanguage'] = $language;
        }

        // articleBody zuletzt damit es nicht oben aufgepriorisierte Felder
        // ueberlagert wenn ein Generator das fuer Vorschau nutzt.
        $articleBody = implode("\n\n", $bodyParts);
        if ('' !== $articleBody) {
            $data['articleBody'] = $articleBody;
        }

        // wordCount + timeRequired (ISO-8601): Google's Article schema
        // honours wordCount; timeRequired is broadly understood by other
        // crawlers and matches the FE reading-time badge so editors see
        // consistent values across surfaces. WPM kept in sync with
        // sitepackage-base/PageReadingTimeProcessor (200).
        if ($totalWords > 0) {
            $data['wordCount'] = $totalWords;
            $data['timeRequired'] = 'PT' . max(1, (int) ceil($totalWords / 200)) . 'M';
        }

        // Hero-Image als ImageObject — Google's Image-Metadata-Requirements
        // (contentUrl pflicht, creator als Person/Organization, NICHT
        // SoftwareApplication). license/copyrightNotice/acquireLicensePage
        // sind optional aber empfohlen und kommen aus Site-Settings.
        $settings = is_array($pageData['_siteSettings'] ?? null) ? $pageData['_siteSettings'] : [];
        $schemaSettings = [];
        if (is_array($settings['structuredContent'] ?? null)
            && is_array($settings['structuredContent']['schema'] ?? null)) {
            $schemaSettings = $settings['structuredContent']['schema'];
        }
        $heroImage = $this->buildHeroImageObject(
            (int) ($pageData['uid'] ?? 0),
            $siteBase,
            $schemaSettings,
        );
        if (null !== $heroImage) {
            $data['image'] = $heroImage;
        }

        // Per-Page-Felder: codeRepository, sameAs, about (Schema.org-Subjects).
        // Bei Open-Source-Blogposts setzt der Editor z.B. github-Repo +
        // about=[SoftwareApplication TYPO3, Thing Kubernetes].
        $data = $this->applyPageLevelExtras($data, $pageData);

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

    /**
     * Reference to the site's default author, for articles without a
     * maintained author record.
     *
     * The person comes from `structuredContent.schema.defaultAuthorPersonSlug`,
     * falling back to `founderPersonSlug` (on a one-person site the founder is
     * the author). A free-text author (`pages.author`) that names somebody
     * else wins: the default only fills a gap, it never overrides a name an
     * editor typed.
     *
     * @param array<string, mixed> $pageData
     *
     * @return array<string, mixed>|null
     */
    private function resolveDefaultAuthor(array $pageData, string $entityBase): ?array
    {
        if ('' === $entityBase) {
            return null;
        }
        $settings = $pageData['_siteSettings'] ?? null;
        $schemaSettings = is_array($settings) && is_array($settings['structuredContent']['schema'] ?? null)
            ? $settings['structuredContent']['schema']
            : [];
        $slug = trim((string) ($schemaSettings['defaultAuthorPersonSlug'] ?? ''));
        if ('' === $slug) {
            $slug = trim((string) ($schemaSettings['founderPersonSlug'] ?? ''));
        }
        if ('' === $slug) {
            return null;
        }

        $row = $this->personBuilder->findBySlug($slug);
        if (null === $row) {
            return null;
        }
        $textAuthor = trim((string) ($pageData['author'] ?? ''));
        if ('' !== $textAuthor && !$this->personBuilder->isNamed($row, $textAuthor)) {
            return null;
        }

        return $this->personBuilder->buildPersonReference((int) ($row['uid'] ?? 0), $entityBase);
    }

    /**
     * Absolute URL of a file in the default storage (fileadmin), relative to
     * the site ROOT. The site base may carry a language path (/en), which
     * files do not have.
     */
    public static function fileUrl(string $siteBase, string $identifier): string
    {
        $identifier = trim($identifier);
        $parts = parse_url(trim($siteBase));
        if ('' === $identifier || !is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        $origin = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $origin . '/fileadmin' . ('/' === $identifier[0] ? $identifier : '/' . $identifier);
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function resolvePageUrl(array $pageData, string $siteBase): string
    {
        $canonical = trim((string) ($pageData['canonical_link'] ?? ''));
        if ('' !== $canonical && (str_starts_with($canonical, 'http://') || str_starts_with($canonical, 'https://'))) {
            return $canonical;
        }
        $slug = (string) ($pageData['slug'] ?? '');
        if ('' !== $siteBase && '' !== $slug) {
            return $siteBase . $slug;
        }

        return '';
    }

    public function getPriority(): int
    {
        return 50;
    }

    /**
     * Resolve the first `pages.media` reference (falling back to
     * `pages.og_image`) for the given page and emit
     * a Google-Image-Metadata-konformes ImageObject:
     *
     * - `contentUrl` (Pflicht laut Google) als absolute URL
     * - `creator` als `Organization` (NICHT `SoftwareApplication` — Google
     *   lehnt das für Image-creator ab), Name aus
     *   `structuredContent.schema.legalName`
     * - `creditText`: bei AI-Origin "AI-generated · <Model>" bzw.
     *   "AI-assisted · <Model>"; bei human: weglassen
     * - `license` / `acquireLicensePage` / `copyrightNotice` aus
     *   Site-Settings (`structuredContent.schema.imageLicenseUrl`,
     *   `imageAcquireLicensePage`, `imageCopyrightNotice`), wenn gesetzt
     *
     * Returns null nur wenn pages.media und pages.og_image leer sind — auch für human-tagged
     * Bilder wird das ImageObject emittet, damit Google die License-Info
     * findet.
     *
     * @param array<string, mixed> $schemaSettings
     *
     * @return array<string, mixed>|null
     */
    private function buildHeroImageObject(int $pageUid, string $siteBase, array $schemaSettings): ?array
    {
        if (0 === $pageUid) {
            return null;
        }
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        // The page's media come first; without them the social-sharing image
        // (og_image) is the image the editor chose to represent the page.
        $ref = false;
        foreach (self::IMAGE_FIELDS as $fieldName) {
            $qb = $connectionPool->getQueryBuilderForTable('sys_file_reference');
            $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
            $ref = $qb->select('sfr.uid_local')
                ->from('sys_file_reference', 'sfr')
                ->where(
                    $qb->expr()->eq('sfr.tablenames', $qb->createNamedParameter('pages')),
                    $qb->expr()->eq('sfr.fieldname', $qb->createNamedParameter($fieldName)),
                    $qb->expr()->eq('sfr.uid_foreign', $qb->createNamedParameter($pageUid, ParameterType::INTEGER)),
                )
                ->orderBy('sfr.sorting_foreign', 'ASC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();
            if (is_array($ref)) {
                break;
            }
        }
        if (!is_array($ref)) {
            return null;
        }
        $fileUid = (int) $ref['uid_local'];

        // sys_file.identifier ist der Storage-relative Pfad (z.B.
        // "/user_upload/foo.png"). fileadmin ist Storage 1 mit base="fileadmin/".
        $fqb = $connectionPool->getQueryBuilderForTable('sys_file');
        $fqb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $fileRow = $fqb->select('identifier')
            ->from('sys_file')
            ->where(
                $fqb->expr()->eq('uid', $fqb->createNamedParameter($fileUid, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        $identifier = is_array($fileRow) ? (string) ($fileRow['identifier'] ?? '') : '';

        $mqb = $connectionPool->getQueryBuilderForTable('sys_file_metadata');
        $mqb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $meta = $mqb->select('tx_provenance_origin', 'tx_provenance_ai_system')
            ->from('sys_file_metadata')
            ->where(
                $mqb->expr()->eq('file', $mqb->createNamedParameter($fileUid, ParameterType::INTEGER)),
                $mqb->expr()->eq('sys_language_uid', $mqb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        $origin = is_array($meta) ? (string) ($meta['tx_provenance_origin'] ?? 'human') : 'human';
        $aiSystem = is_array($meta) ? trim((string) ($meta['tx_provenance_ai_system'] ?? '')) : '';

        $image = [
            '@type' => 'ImageObject',
        ];

        // contentUrl is required by Google. Built from the site root, not the
        // language base: siteBase is the language base (e.g. /en), and files
        // are not language-scoped, so /en/fileadmin/... is a 404.
        $base = rtrim($siteBase, '/');
        $fileUrl = self::fileUrl($siteBase, $identifier);
        if ('' !== $fileUrl) {
            $image['contentUrl'] = $fileUrl;
        }

        // creator: Organization (NIE SoftwareApplication für Image-creator —
        // Google lehnt das ab). Name aus legalName-Setting.
        $legalName = trim((string) ($schemaSettings['legalName'] ?? ''));
        if ('' !== $legalName) {
            $creator = [
                '@type' => 'Organization',
                'name' => $legalName,
            ];
            if ('' !== $base) {
                $creator['url'] = $base . '/';
            }
            $image['creator'] = $creator;
        }

        // AI-Disclosure als creditText (Google-konform — Schema.org Property
        // für AI-Hinweise + EU-AI-Act Art. 50 maschinen-lesbar).
        if ('ai_generated' === $origin || 'ai_assisted' === $origin) {
            $prefix = 'ai_generated' === $origin ? 'AI-generated' : 'AI-assisted';
            $image['creditText'] = '' !== $aiSystem ? $prefix . ' · ' . $aiSystem : $prefix;
        }

        // Optional license-Felder aus Site-Settings.
        $license = trim((string) ($schemaSettings['imageLicenseUrl'] ?? ''));
        if ('' !== $license) {
            $image['license'] = $license;
        }
        $acquirePage = trim((string) ($schemaSettings['imageAcquireLicensePage'] ?? ''));
        if ('' !== $acquirePage) {
            $image['acquireLicensePage'] = $acquirePage;
        }
        $copyrightNotice = trim((string) ($schemaSettings['imageCopyrightNotice'] ?? ''));
        if ('' === $copyrightNotice && '' !== $legalName) {
            $copyrightNotice = '© ' . date('Y') . ' ' . $legalName;
        }
        if ('' !== $copyrightNotice) {
            $image['copyrightNotice'] = $copyrightNotice;
        }

        return $image;
    }

    /**
     * UTF-8-aware word counter. Mirrors
     * sitepackage-base/PageReadingTimeProcessor::wordCount() — keep both
     * impls byte-identical so the JSON-LD `wordCount` matches the FE
     * reading-time badge to the word.
     *
     * Block-level tag boundaries are first normalised to whitespace so
     * adjacent words don't glue when HTML is stripped.
     *
     * Opening tags are matched too, and that is deliberate: `br` was listed
     * from the start and never did anything, because a void element has no
     * closing form — `foo<br>bar` counted as one word. And the closing
     * bracket is now required, because with `>` optional the alternation
     * matched a tag-name PREFIX: `</pre>` matched as `</p` and left `re>`
     * standing as a word. Both bugs were live until 2026-08-15; they pull in
     * opposite directions, which is why neither produced an obviously wrong
     * number. The counterpart in sitepackage-base carries the same pattern
     * and the tests that pin it.
     */
    private static function countWords(string $html): int
    {
        if ('' === $html) {
            return 0;
        }
        $normalized = (string) preg_replace('/<\/?(p|div|li|h[1-6]|br)\b[^>]*>/i', ' ', $html);
        $plain = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim((string) preg_replace('/\s+/u', ' ', $plain));
        if ('' === $plain) {
            return 0;
        }

        return str_word_count($plain, 0, 'äöüÄÖÜßéèêàâáÉÈÊÀÂÁ');
    }
}
