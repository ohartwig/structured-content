<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class WebPageSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use ContextAndRelationshipsTrait;
    use PageLevelExtraSchemaTrait;
    use ServicePageDetectionTrait;

    /**
     * RoutingArguments-Keys, die einen Plugin-Detail-Request markieren.
     * Auf solchen Requests laeuft ein dedizierter Detail-Generator
     * (ProfilePage, PodcastEpisode, …) parallel und bedient die Detail-URL.
     * WebPage darf den Container-Schema-Type (z.B. ProfilePage auf der
     * /autor-Listing-Page) dann nicht durchreichen — sonst kollabiert
     * ein typ-identischer Knoten OHNE die Detail-Properties (mainEntity,
     * Person-Name) mit dem dedizierten Generator-Output. Synchron zu
     * PluginDetailIndexingListener.
     */
    private const PLUGIN_DETAIL_ROUTE_GROUPS = [
        'tx_contentblockscollection_person',
        'tx_contentblockscollection_podcast',
    ];

    public function __construct(
        private readonly SchemaTypeResolverService $schemaTypeResolver,
        private readonly ?ContextResolverInterface $contextResolver = null,
        private readonly ?RelationshipRepositoryInterface $relationshipRepository = null,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        // Spec: Jede Seite hat eine WebPage-Entity (auch Article-Pages — die
        // BlogPosting-Entity verweist via mainEntityOfPage auf die WebPage,
        // ohne WebPage waere die Referenz tot). Excludiert nur die Root-Page,
        // dort uebernimmt WebSite die Rolle.
        $uid = (int) ($pageData['uid'] ?? 0);
        $rootPageId = (int) ($pageData['_rootPageId'] ?? -1);

        return $uid > 0 && $uid !== $rootPageId;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        // Subtype-Detection (CollectionPage, AboutPage, ProfilePage etc.) nur
        // wenn der Resolver was webpage-family-typisches liefert. Sonst plain
        // 'WebPage' — Article-Pages bekommen WebPage als Wrapper, BlogPosting
        // wird parallel von ArticleSchemaGenerator erzeugt.
        //
        // Auf Plugin-Detail-URLs (Person, Podcast) wird der Container-Schema-
        // Type IGNORIERT: der dedizierte Detail-Generator emittiert dort den
        // ProfilePage/PodcastEpisode-Knoten mit eigenem @id + Detail-Properties.
        // Wuerde WebPage hier denselben Container-Schema-Type durchreichen,
        // gaebe es zwei Knoten mit gleichem @type aber widerspruechlichen
        // Daten (Container-Name vs Detail-Name).
        $resolved = $this->isPluginDetailRequest($pageData)
            ? null
            : $this->schemaTypeResolver->resolve($pageData, $contentElements);
        $family = null !== $resolved ? $this->schemaTypeResolver->getFamily($resolved) : 'webpage';
        $schemaType = ('webpage' === $family && null !== $resolved) ? $resolved : 'WebPage';

        // Service-Pages typisieren sich als ItemPage (Schema.org-Subtype fuer
        // Item-Detail-Pages) — semantisch praeziser als plain WebPage.
        // Editor-Override (tx_structuredcontent_schema_type) gewinnt weiter
        // weil der Resolver dann einen webpage-family-Subtype liefert (oben).
        $isServicePage = 'WebPage' === $schemaType && $this->isServicePage($pageData, $contentElements);
        if ($isServicePage) {
            $schemaType = 'ItemPage';
        }

        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);
        $url = (string) ($pageData['canonical_link'] ?? '');
        if ('' === $url && '' !== $siteBase) {
            $url = $siteBase . ((string) ($pageData['slug'] ?? ''));
        }

        $data = [];
        // @id mit #webpage-Fragment damit Article.mainEntityOfPage eine
        // dedizierte WebPage-Entity referenziert (statt die URL doppelt zu
        // vergeben — einmal als WebPage.@id, einmal als Article.@id wenn
        // beide auf $url zeigen → Crawler-Ambiguity). Pattern matched
        // PersonPageSchemaGenerator (#person) und DefinedTerm (#term).
        if ('' !== $url) {
            $data['@id'] = $url . '#webpage';
        }
        $data['name'] = $this->sanitizeText((string) ($pageData['title'] ?? ''));
        $data['description'] = $this->sanitizeText((string) ($pageData['description'] ?? $pageData['abstract'] ?? ''));
        if ('' !== $url) {
            $data['url'] = $url;
        }

        // isPartOf: WebPage gehoert zur WebSite. Spec-Pflicht.
        // WICHTIG: about NICHT als Default auf #organization setzen — laut
        // Schema.org-Spec sind `about`-Subjects die thematischen Subjects
        // der Page (TYPO3, Kubernetes, …), die Organization gehoert zu
        // `publisher`/`provider`. PageLevelExtraSchemaTrait fuegt about
        // hinzu wenn der Editor manuelle Subjects oder Glossar-Refs
        // pflegt — sonst bleibt das Feld weg.
        if ('' !== $siteBase) {
            $data['isPartOf'] = ['@id' => $entityBase . '#website'];
        }

        // ItemPage.mainEntity → Service.@id (parallel emittiert vom
        // ServiceSchemaGenerator). Spec-Cross-Ref: ItemPage beschreibt die
        // Page-Struktur, Service ist das Subject. Crawler verstehen die
        // Trennung dadurch sauber.
        if ($isServicePage && '' !== $url) {
            $data['mainEntity'] = ['@id' => $url . '#service'];
        }

        // Sprache als BCP47 (Spec: de-DE statt nur de).
        $language = trim((string) ($pageData['_language'] ?? ''));
        if ('' !== $language) {
            $data['inLanguage'] = $language;
        }

        if (isset($pageData['crdate']) && (int) $pageData['crdate'] > 0) {
            $data['datePublished'] = date('c', (int) $pageData['crdate']);
        }
        if (isset($pageData['tstamp']) && (int) $pageData['tstamp'] > 0) {
            $data['dateModified'] = date('c', (int) $pageData['tstamp']);
        }

        if ('CollectionPage' === $schemaType) {
            $itemList = $this->buildItemList($contentElements, $siteBase);
            if (null !== $itemList) {
                $data['mainEntity'] = $itemList;
            }
        }

        // Per-Page TCA-Felder: codeRepository, sameAs, about. applyPageLevelExtras
        // ueberschreibt das `about => #organization`-Default oben (Z. 75) wenn
        // der Editor about-Eintraege pflegt — thematische Subjects haben
        // Vorrang vor dem Organization-Fallback.
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

    public function getPriority(): int
    {
        return 45;
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function isPluginDetailRequest(array $pageData): bool
    {
        $routingArguments = $pageData['_routingArguments'] ?? [];
        if (!is_array($routingArguments)) {
            return false;
        }
        // A glossary term page is the listing page with a term argument; the
        // listing's CollectionPage type does not describe one definition. Only
        // `term` counts: the listing route carries controller and action too.
        $glossaryArgs = $routingArguments['tx_dpnglossary_glossary'] ?? null;
        if (is_array($glossaryArgs) && '' !== trim((string) ($glossaryArgs['term'] ?? ''))) {
            return true;
        }
        foreach (self::PLUGIN_DETAIL_ROUTE_GROUPS as $group) {
            $args = $routingArguments[$group] ?? null;
            if (!is_array($args) || [] === $args) {
                continue;
            }
            foreach ($args as $value) {
                if ('' !== trim((string) $value)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Build a minimal ItemList for CollectionPages from menu/list-style content
     * blocks. Returns null when nothing list-like is present.
     *
     * @param list<array<string, mixed>> $contentElements
     *
     * @return array<string, mixed>|null
     */
    private function buildItemList(array $contentElements, string $siteBase): ?array
    {
        $items = [];
        $position = 0;
        foreach ($contentElements as $element) {
            $ctype = strtolower((string) ($element['CType'] ?? ''));
            if (!str_contains($ctype, 'menu') && !str_contains($ctype, 'list')) {
                continue;
            }
            $header = $this->sanitizeText((string) ($element['header'] ?? ''));
            if ('' === $header) {
                continue;
            }
            ++$position;
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position,
                'name' => $header,
            ];
        }

        if ([] === $items) {
            return null;
        }

        return [
            '@type' => 'ItemList',
            'itemListElement' => $items,
        ];
    }
}
