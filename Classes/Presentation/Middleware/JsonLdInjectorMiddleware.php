<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\Middleware;

use Koh\StructuredContent\Application\Service\GlossaryLinkExtractor;
use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Application\Service\SchemaOrgService;
use Koh\StructuredContent\Domain\Contract\FreshnessEnricherInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use Koh\StructuredContent\Presentation\Event\ContentEnrichedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\Exception\AspectNotFoundException;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Security\ContentSecurityPolicy\ConsumableNonce;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use Koh\StructuredContent\Domain\Contract\SchemaMappingConfigurableInterface;
use TYPO3\CMS\Frontend\Page\PageInformation;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class JsonLdInjectorMiddleware implements MiddlewareInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private readonly SchemaOrgService $schemaOrgService,
        private readonly FreshnessEnricherInterface $freshnessEnricher,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly FrontendInterface $cache,
        private readonly ConnectionPool $connectionPool,
        private readonly SchemaMappingConfigurableInterface $contentBlockSchemaMapper,
        private readonly GlossaryTermService $glossaryService,
        private readonly Context $context,
        private readonly GlossaryLinkExtractor $glossaryLinkExtractor = new GlossaryLinkExtractor(),
        private readonly ?PageContentCollector $pageContentCollector = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $response;
        }

        $settings = $site->getSettings();
        if (!(bool) $settings->get('structuredContent.enabled') || !(bool) $settings->get('structuredContent.schema.enabled')) {
            return $response;
        }

        $contentType = $response->getHeaderLine('Content-Type');
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $pageArguments = $request->getAttribute('routing');
        if (!$pageArguments instanceof PageArguments) {
            return $response;
        }

        $pageUid = $pageArguments->getPageId();
        if (0 === $pageUid) {
            return $response;
        }

        $siteIdentifier = $site->getIdentifier();
        $languageId = (string) ($request->getAttribute('language')?->getLanguageId() ?? 0);
        $routeArguments = $pageArguments->getRouteArguments();
        $routeHash = [] !== $routeArguments ? '_' . md5(json_encode($routeArguments, JSON_THROW_ON_ERROR)) : '';
        $cacheIdentifier = 'jsonld_' . $siteIdentifier . '_' . $languageId . '_' . $pageUid . $routeHash;
        // The identifier carries no workspace, visibility or user groups, so
        // the cache may only serve and store what an anonymous visitor of the
        // live site sees. Anything else is built for this request alone
        // (AUD-061-F01).
        $cacheable = $this->isPlainPublicRequest();
        $cachedJsonLd = $cacheable ? $this->cache->get($cacheIdentifier) : false;

        if (false !== $cachedJsonLd && is_string($cachedJsonLd)) {
            return $this->injectJsonLd($response, $cachedJsonLd, $request);
        }

        $pageInformation = $request->getAttribute('frontend.page.information');
        if (!$pageInformation instanceof PageInformation) {
            return $response;
        }

        $siteLanguage = $request->getAttribute('language');
        $pageData = $pageInformation->getPageRecord();
        $pageData['_siteSettings'] = $site->getSettings()->getAll();
        // Language-spezifische Base (z.B. https://example.com/ fuer EN
        // statt der DE-Site-Base https://example.org/). Schema.org-@ids
        // sollen unter dem TATSAECHLICHEN Hostname der gerenderten Page
        // ausgegeben werden, sonst zeigen Article/WebPage-Identitaeten
        // auf den falschen Server.
        $pageData['_siteBase'] = (string) (null !== $siteLanguage ? $siteLanguage->getBase() : $site->getBase());
        // Site-wide entities (Organization, WebSite, Person) exist once per
        // site, not once per language: their @id is built from the default
        // language base so every page in every language references the same
        // node. See EntityBase.
        $pageData['_entityBase'] = (string) $site->getDefaultLanguage()->getBase();
        $pageData['_rootPageId'] = $site->getRootPageId();
        $pageData['_rootline'] = $pageInformation->getRootLine();
        $pageData['_routingArguments'] = $routeArguments;
        if (null !== $siteLanguage) {
            // BCP47 hreflang (e.g. "de-DE", "en-GB") fuer Schema.org inLanguage
            // — Spec fordert Sprach-Region-Format, nicht nur "de" / "en".
            $pageData['_language'] = $siteLanguage->getHreflang();
            $pageData['_languageId'] = $siteLanguage->getLanguageId();
            // Site settings apply to every language. Texts that must differ
            // per language (the organization description) can be overridden
            // in the language's site configuration under `structuredContent`.
            $languageSchema = $siteLanguage->toArray()['structuredContent'] ?? [];
            $pageData['_languageSchema'] = is_array($languageSchema) ? $languageSchema : [];
        }
        $pageData['_aboutEntries'] = $this->loadAboutEntries($pageUid);
        // dpn-glossary integration: the parser links every term it finds in the
        // rendered content. The response body (after the parser ran inside
        // $handler->handle()) is scanned for those links; the slugs feed
        // PageLevelExtraSchemaTrait::buildAboutSubjects(). Without the
        // extension the service reports itself unavailable and nothing is
        // queried.
        $glossaryLocation = $this->glossaryLocation($site, $pageData, $cacheable);
        $pageData['_glossaryBasePath'] = $glossaryLocation['basePath'];
        $pageData['_glossaryRefs'] = $this->extractGlossaryReferencesFromHtml(
            (string) $response->getBody(),
            (int) ($pageData['_languageId'] ?? 0),
            $site,
            $glossaryLocation['basePath'],
            $glossaryLocation['pagePaths'],
        );
        $contentElements = $this->getContentElements($request);

        // Reading the setting stays here: the middleware holds the site, and
        // the mapper's contract deliberately does not know what a Site is.
        $mapping = $site->getSettings()->get('structuredContent.schemaMapping');
        if (is_array($mapping)) {
            $this->contentBlockSchemaMapper->setMappings($mapping);
        }

        $documents = $this->schemaOrgService->generateAll($pageData, $contentElements);
        foreach ($this->schemaOrgService->failures() as $failure) {
            $this->logger?->error('Schema generator {generator} failed and was skipped: {message}', [
                'generator' => $failure['generator'],
                'message' => $failure['exception']->getMessage(),
                'exception' => $failure['exception'],
            ]);
        }

        // Drop documents whose data payload is empty — emitting
        // `<script>{"@type":"Foo"}</script>` is worse than emitting nothing,
        // it tells crawlers that a typed entity exists but has no required
        // properties.
        $documents = array_values(array_filter(
            $documents,
            static fn(JsonLdDocument $doc): bool => [] !== $doc->data,
        ));

        if ([] === $documents) {
            return $response;
        }

        $freshnessSignal = $this->freshnessEnricher->enrich($pageData);
        $freshnessProperties = $freshnessSignal->toSchemaProperties();

        // Only schema types that derive from CreativeWork carry datePublished
        // / dateModified properties in Schema.org. Annotating BreadcrumbList,
        // ItemList, Product, Service, Organization etc. with date fields is
        // a spec violation and gets flagged by the Rich Result test.
        $freshnessSchemaTypes = [
            'Article', 'BlogPosting', 'NewsArticle', 'TechArticle',
            'PodcastEpisode', 'PodcastSeries', 'WebPage', 'AboutPage',
            'FAQPage', 'CollectionPage', 'CreativeWork',
        ];

        $enrichedDocuments = [];
        foreach ($documents as $document) {
            $enrichedData = in_array($document->schemaType, $freshnessSchemaTypes, true)
                ? array_merge($document->data, $freshnessProperties)
                : $document->data;
            $enrichedDocuments[] = new JsonLdDocument($document->schemaType, $enrichedData);
        }
        $documents = $enrichedDocuments;

        $event = new ContentEnrichedEvent($documents, $pageData, $pageUid, $contentElements);
        $this->eventDispatcher->dispatch($event);
        $documents = $event->getDocuments();

        $jsonLdOutput = $this->buildGraphScriptTag($documents);

        // Tagged with the page: TYPO3 flushes the "pages" cache group by
        // pageId_<uid> whenever a record on the page is saved or published,
        // and the DataHandler hook flushes the same tag for schema records.
        if ($cacheable) {
            $this->cache->set($cacheIdentifier, $jsonLdOutput, ['pageId_' . $pageUid]);
        }

        return $this->injectJsonLd($response, $jsonLdOutput, $request);
    }

    /**
     * Whether this request sees exactly what an anonymous visitor of the live
     * site sees: the live workspace, no preview, no hidden, scheduled or
     * deleted records, and no frontend user or groups beyond the anonymous
     * ones (0, -1). The content-element queries use FrontendRestrictionContainer,
     * which reads all of these from the same Context; TYPO3's own page cache
     * is likewise switched off for a preview.
     */
    private function isPlainPublicRequest(): bool
    {
        if (0 !== (int) $this->context->getPropertyFromAspect('workspace', 'id', 0)) {
            return false;
        }

        // The preview aspect is set by the frontend rendering chain; without it
        // there is no preview.
        if ($this->context->hasAspect('frontend.preview')
            && true === $this->context->getPropertyFromAspect('frontend.preview', 'isPreview', false)) {
            return false;
        }

        foreach (['includeHiddenPages', 'includeHiddenContent', 'includeScheduledRecords', 'includeDeletedRecords'] as $property) {
            if (true === $this->context->getPropertyFromAspect('visibility', $property, false)) {
                return false;
            }
        }

        if (true === $this->context->getPropertyFromAspect('frontend.user', 'isLoggedIn', false)) {
            return false;
        }

        $groupIds = $this->context->getPropertyFromAspect('frontend.user', 'groupIds', [0, -1]);
        if (!is_array($groupIds)) {
            return false;
        }

        return [] === array_diff(array_map(intval(...), $groupIds), [0, -1]);
    }

    /**
     * Builds a single `<script type="application/ld+json">` tag containing
     * one `@graph` array with all entity nodes. Spec-konform — Parser
     * koennen Cross-Refs via `@id` zuverlaessiger aufloesen wenn alle
     * Entities in einem Graph leben statt in mehreren separaten Blocks.
     *
     * @param list<JsonLdDocument> $documents
     */
    private function buildGraphScriptTag(array $documents): string
    {
        if ([] === $documents) {
            return '';
        }

        $nodes = [];
        foreach ($documents as $document) {
            // Each generator's data may already include @id, @type-overrides
            // or other JSON-LD reserved keys — we let the generator decide.
            // schemaType is the default @type unless data explicitly sets one.
            $node = $document->data;

            // Multi-Entity-Documents: wenn der Generator `data: ['@graph' =>
            // [entity1, entity2, ...]]` zurueckgibt, flach-klopfen in den
            // Top-Level-@graph. So koennen Generatoren wie ItemList mehrere
            // Top-Level-Entities emittieren ohne sie in einen ueberfluessigen
            // Wrapper-Knoten zu packen ({"@type":"ItemList","@graph":...}-
            // anti-pattern → 2 saubere ItemList-Knoten parallel).
            if (isset($node['@graph']) && is_array($node['@graph'])
                && [] === array_diff(array_keys($node), ['@graph'])) {
                foreach ($node['@graph'] as $subNode) {
                    if (is_array($subNode)) {
                        $nodes[] = $subNode;
                    }
                }

                continue;
            }

            if (!array_key_exists('@type', $node)) {
                $node = array_merge(['@type' => $document->schemaType], $node);
            }
            $nodes[] = $node;
        }

        $graph = [
            '@context' => 'https://schema.org',
            '@graph' => $nodes,
        ];

        $json = json_encode(
            $graph,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP,
        );
        if (false === $json) {
            return '';
        }
        // XSS hardening: prevent script-tag breakout
        $json = str_replace('</', '<\/', $json);

        return '<script type="application/ld+json">' . $json . '</script>' . "\n";
    }

    private function injectJsonLd(ResponseInterface $response, string $jsonLd, ServerRequestInterface $request): ResponseInterface
    {
        $body = (string) $response->getBody();
        $position = strpos($body, '</head>');

        if (false === $position) {
            return $response;
        }

        // Add CSP nonce to JSON-LD script tags (TYPO3 14 nonce support)
        $nonce = $request->getAttribute('nonce');
        if ($nonce instanceof ConsumableNonce) {
            $jsonLd = str_replace(
                '<script type="application/ld+json">',
                '<script type="application/ld+json" nonce="' . htmlspecialchars($nonce->consume(), ENT_QUOTES, 'UTF-8') . '">',
                $jsonLd,
            );
        }

        $modifiedBody = substr($body, 0, $position) . $jsonLd . substr($body, $position);

        return new HtmlResponse($modifiedBody, $response->getStatusCode(), $response->getHeaders());
    }

    /**
     * The content elements the page renders in the requested language: see
     * PageContentCollector.
     *
     * Before, only default-language rows were read and overlaid. Elements
     * created directly in a translation (free mode, `l18n_parent = 0`) and
     * elements for all languages never reached a generator, so a translated
     * page whose content was partly or wholly free mode emitted no schema
     * for it.
     *
     * @return list<array<string, mixed>>
     */
    private function getContentElements(ServerRequestInterface $request): array
    {
        $pageArguments = $request->getAttribute('routing');
        if (!$pageArguments instanceof PageArguments) {
            return [];
        }

        $pageUid = $pageArguments->getPageId();
        if (0 === $pageUid) {
            return [];
        }

        return ($this->pageContentCollector ?? new PageContentCollector($this->connectionPool))
            ->findRenderedContent($pageUid, $this->languageAspect($request));
    }

    /**
     * The language aspect the frontend rendered the page with. The context
     * carries it once the page is resolved, including a content language
     * that differs from the requested one; the site language's fallbackType
     * is the fallback when the context belongs to another language.
     */
    private function languageAspect(ServerRequestInterface $request): LanguageAspect
    {
        $siteLanguage = $request->getAttribute('language');
        $languageId = $siteLanguage instanceof SiteLanguage ? $siteLanguage->getLanguageId() : 0;

        try {
            $aspect = $this->context->getAspect('language');
            if ($aspect->getId() === $languageId) {
                return $aspect;
            }
        } catch (AspectNotFoundException) {
            // Built from the site language below.
        }

        return $siteLanguage instanceof SiteLanguage
            ? LanguageAspectFactory::createFromSiteLanguage($siteLanguage)
            : new LanguageAspect();
    }

    /**
     * Slugs of the glossary terms the rendered page links to.
     *
     * The dpn-glossary parser writes `<a href="…" title="…" class="dpnglossary
     * link">`. The href is `#<slug>` while the parser has no detail page and
     * the term's detail URL (`/glossar/<slug>`, `/en/glossary/<slug>`, possibly
     * absolute) once it has one. GlossaryLinkExtractor accepts both, in any
     * attribute order; only slugs of existing terms are kept, which also rules
     * out foreign anchors that happen to carry the classes.
     *
     * All slugs are looked up in one query (GlossaryTermService::findTermsBySlugs()).
     *
     * @param list<string>|null $glossaryPagePaths null: not known yet, built here if the page links a term
     *
     * @return list<string>
     */
    private function extractGlossaryReferencesFromHtml(string $body, int $languageId, Site $site, string $glossaryBasePath, ?array $glossaryPagePaths): array
    {
        if (!$this->glossaryService->isAvailable() || '' === $body || !str_contains($body, 'dpnglossary')) {
            return [];
        }

        $slugs = $this->glossaryLinkExtractor->extractSlugs(
            $body,
            $glossaryPagePaths ?? $this->glossaryPagePaths($site, $glossaryBasePath),
            $this->siteHosts($site),
        );
        $terms = $this->glossaryService->findTermsBySlugs($slugs, $languageId);

        return array_values(array_filter(
            $slugs,
            static fn(string $slug): bool => isset($terms[$slug]),
        ));
    }

    /**
     * Where the glossary page lives: its slug in the page's language (the
     * base of every DefinedTerm @id) and its URL path in every language of the
     * site (to recognise term links). Both depend on the site, the language
     * and the glossary page alone, not on the page being rendered, yet were
     * resolved again for every page: one or two queries for the slug and a
     * page lookup per language for the router.
     *
     * For a plain public request they are kept in the schema cache, tagged
     * with the glossary page: editing that page (its slug, its translation,
     * its visibility) flushes `pageId_<uid>` like it flushes the page cache.
     * The site configuration is part of the key, so a changed language base or
     * route enhancer starts a new entry. Previews, workspaces and logged-in
     * visitors may see a different glossary page and resolve it themselves,
     * exactly as before — as does a request without a glossary page or
     * without dpn_glossary, which costs no query anyway.
     *
     * Outside the cache the page paths stay null and, as before, are built
     * only when the page links a term. A cache miss builds them right away:
     * once per site language and cache lifetime, for every later page.
     *
     * @param array<string, mixed> $pageData
     *
     * @return array{basePath: string, pagePaths: list<string>|null}
     */
    private function glossaryLocation(Site $site, array $pageData, bool $cacheable): array
    {
        $glossaryPageUid = (int) ($site->getSettings()->get('dpn-glossary.glossaryPage') ?? 0);
        if (!$cacheable || $glossaryPageUid <= 0 || !$this->glossaryService->isAvailable()) {
            return ['basePath' => $this->glossaryService->resolveGlossaryBasePath($pageData), 'pagePaths' => null];
        }

        // serialize(), not json_encode(): a key must never be the reason a
        // page fails, and the site configuration is not guaranteed to be UTF-8.
        $cacheIdentifier = 'glossary_location_' . hash('sha256', serialize([
            $site->getIdentifier(),
            (int) ($pageData['_languageId'] ?? 0),
            $glossaryPageUid,
            $site->getConfiguration(),
        ]));
        $cached = $this->cache->get($cacheIdentifier);
        $cachedPaths = is_array($cached) ? $cached['pagePaths'] ?? null : null;
        if (is_array($cached) && is_string($cached['basePath'] ?? null) && self::isListOfStrings($cachedPaths)) {
            return ['basePath' => $cached['basePath'], 'pagePaths' => $cachedPaths];
        }

        $basePath = $this->glossaryService->resolveGlossaryBasePath($pageData);
        $location = ['basePath' => $basePath, 'pagePaths' => $this->glossaryPagePaths($site, $basePath)];
        $this->cache->set($cacheIdentifier, $location, ['pageId_' . $glossaryPageUid]);

        return $location;
    }

    /**
     * URL path of the glossary page in every language of the site. The path is
     * the translated page slug below the language base ("/glossar",
     * "/en/glossary"), so it comes from the site router. When the router cannot
     * build it (no glossary page configured, page not translated) the default
     * slug below the language base stands in.
     *
     * @return list<string>
     */
    private function glossaryPagePaths(Site $site, string $glossaryBasePath): array
    {
        $glossaryPageUid = (int) ($site->getSettings()->get('dpn-glossary.glossaryPage') ?? 0);
        $paths = [];
        foreach ($site->getLanguages() as $language) {
            $path = null;
            if ($glossaryPageUid > 0) {
                try {
                    $path = $site->getRouter()->generateUri($glossaryPageUid, ['_language' => $language])->getPath();
                } catch (\Throwable) {
                    $path = null;
                }
            }
            $path ??= rtrim($language->getBase()->getPath(), '/') . '/' . ltrim($glossaryBasePath, '/');
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isListOfStrings(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && $value === array_filter($value, is_string(...));
    }

    /**
     * Hosts of all language bases — an absolute term link has to point to
     * this site.
     *
     * @return list<string>
     */
    private function siteHosts(Site $site): array
    {
        $hosts = [];
        foreach ($site->getLanguages() as $language) {
            $host = $language->getBase()->getHost();
            if ('' !== $host) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Laedt die Per-Page about-Entries aus der IRRE-Tabelle
     * tx_structuredcontent_about_entry. Eine zusaetzliche Query pro Seite,
     * deren Result pro Generator-Run wiederverwendet wird (vs. 3 Queries
     * wenn jeder Generator selber laedt).
     *
     * @return list<array<string, mixed>>
     */
    private function loadAboutEntries(int $pageUid): array
    {
        if ($pageUid <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('tx_structuredcontent_about_entry');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $result = $qb
            ->select('schema_type', 'name', 'same_as', 'sorting')
            ->from('tx_structuredcontent_about_entry')
            ->where(
                $qb->expr()->eq('parent_table', $qb->createNamedParameter('pages')),
                $qb->expr()->eq('parent_field', $qb->createNamedParameter('tx_structuredcontent_about')),
                $qb->expr()->eq('parent_uid', $qb->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery();

        return $result->fetchAllAssociative();
    }
}
