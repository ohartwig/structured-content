<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ServiceSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use ContextAndRelationshipsTrait;
    use PackagesGridCardLoaderTrait;
    use PageLevelExtraSchemaTrait;
    use ServicePageDetectionTrait;

    /** @var list<string> */
    private const CTYPE_PATTERNS = ['service', 'solution'];

    private const PACKAGES_GRID_CTYPE = 'moselwal_packagesgrid';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
        private readonly ?ContextResolverInterface $contextResolver = null,
        private readonly ?RelationshipRepositoryInterface $relationshipRepository = null,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        // Detection-Heuristik wandert in ServicePageDetectionTrait —
        // WebPageSchemaGenerator nutzt sie ebenfalls, damit das WebPage-
        // Schema sich auf Service-Pages als ItemPage typisieren + mainEntity
        // auf die Service-@id setzen kann (cross-ref).
        return $this->isServicePage($pageData, $contentElements);
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);
        $settings = $pageData['_siteSettings'] ?? [];
        $pageSlug = (string) ($pageData['slug'] ?? '');
        // ??-Operator schluckt nur null, nicht leerer-String. canonical_link
        // ist im pages-TCA als String mit Default '' definiert → zweistufige
        // Pruefung, sonst landen Service.@id / mainEntityOfPage als nackte
        // Fragmente (#service / #webpage) ohne absolute URL.
        $pageUrl = (string) ($pageData['canonical_link'] ?? '');
        if ('' === $pageUrl) {
            $pageUrl = $siteBase . $pageSlug;
        }

        $serviceElement = $this->findServiceElement($contentElements);

        $data = [
            // Stabile @id mit #service-Fragment — Pattern matched #webpage
            // (Article, WebPage), #person (PersonPage), #term (DefinedTerm).
            // Erlaubt Cross-Refs aus der parallel emittierten ItemPage-Entity
            // via mainEntity.@id.
            '@id' => $pageUrl . '#service',
            'name' => $this->sanitizeText((string) ($pageData['title'] ?? '')),
            'description' => $this->sanitizeText((string) ($pageData['description'] ?? $pageData['abstract'] ?? '')),
            'url' => $pageUrl,
            'provider' => [
                '@type' => 'Organization',
                '@id' => $entityBase . '#organization',
            ],
            // Verbindung zur WebPage-Entity (ItemPage-Subtype auf Service-
            // Pages). Cross-Ref: ItemPage.mainEntity → Service.@id, Service.
            // mainEntityOfPage → ItemPage.@id. Crawler/Knowledge-Graph
            // verstehen so die Page-vs-Subject-Trennung.
            'mainEntityOfPage' => ['@id' => $pageUrl . '#webpage'],
        ];

        if (null !== $serviceElement) {
            $body = $this->sanitizeText((string) ($serviceElement['bodytext'] ?? ''));
            if ('' !== $body && '' === $data['description']) {
                $data['description'] = mb_substr($body, 0, 500);
            }
        }

        // Per-page serviceType / category from explicit TCA fields. Editor
        // sets these so we don't fall back to scraping section headers.
        $serviceType = trim((string) ($pageData['tx_structuredcontent_service_type'] ?? ''));
        if ('' !== $serviceType) {
            $data['serviceType'] = $serviceType;
        }
        $category = trim((string) ($pageData['tx_structuredcontent_service_category'] ?? ''));
        if ('' !== $category) {
            $data['category'] = $category;
        }

        $areaServed = trim((string) (
            $settings['structuredContent']['schema']['areaServed']
            ?? ''
        ));
        if ('' !== $areaServed) {
            $areas = array_values(array_filter(
                array_map('trim', explode(',', $areaServed)),
                static fn(string $a): bool => '' !== $a,
            ));
            $data['areaServed'] = 1 === count($areas) ? $areas[0] : $areas;
        }

        // Build hasOfferCatalog + offers from packages-grid cards on the page.
        $catalog = $this->buildOfferCatalog($contentElements, $pageUrl);
        if (null !== $catalog) {
            $data['hasOfferCatalog'] = $catalog;
        }

        $aggregateOffer = $this->buildAggregateOffer($contentElements, $pageUrl);
        if (null !== $aggregateOffer) {
            $data['offers'] = $aggregateOffer;
        }

        // Per-Page Felder: sameAs + about (Schema.org-Subjects). codeRepository
        // wird in canHandle bewusst ausgeschlossen (Z. 54) — Service mit
        // Repository wird stattdessen als SoftwareApplication emittiert.
        $data = $this->applyPageLevelExtras($data, $pageData);

        $data = $this->enrichWithContextAndRelationships(
            data: $data,
            pageData: $pageData,
            contextResolver: $this->contextResolver,
            relationshipRepository: $this->relationshipRepository,
        );

        return new JsonLdDocument('Service', array_filter(
            $data,
            static fn($v): bool => '' !== $v && [] !== $v,
        ));
    }

    public function getPriority(): int
    {
        return 55;
    }

    /**
     * @param list<array<string, mixed>> $contentElements
     *
     * @return array<string, mixed>|null
     */
    private function findServiceElement(array $contentElements): ?array
    {
        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            foreach (self::CTYPE_PATTERNS as $pattern) {
                if (str_contains($cType, $pattern)) {
                    return $element;
                }
            }
        }

        return null;
    }

    /**
     * Build a Schema.org OfferCatalog from packages-grid cards on the page.
     * Each non-Enterprise card with a positive price becomes one Offer entry.
     *
     * @param list<array<string, mixed>> $contentElements
     *
     * @return array<string, mixed>|null
     */
    private function buildOfferCatalog(array $contentElements, string $pageUrl): ?array
    {
        $items = [];
        $position = 0;

        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') !== self::PACKAGES_GRID_CTYPE) {
                continue;
            }
            foreach ($this->loadCards((int) ($element['uid'] ?? 0)) as $card) {
                $price = (float) ($card['price'] ?? 0);
                $uid = (int) ($card['uid'] ?? 0);
                $header = $this->sanitizeText((string) ($card['header'] ?? ''));
                if ('' === $header) {
                    continue;
                }

                $headerSlug = self::slugify($header);
                $slug = $uid > 0 ? $headerSlug . '-' . $uid : $headerSlug;
                $offerId = $pageUrl . '#offer-' . $slug;

                $offer = [
                    '@type' => 'Offer',
                    '@id' => $offerId,
                    'name' => $header,
                ];

                if ($price > 0) {
                    $offer['price'] = number_format($price, 2, '.', '');
                    $currency = strtoupper($this->sanitizeText((string) ($card['price_currency'] ?? 'EUR')));
                    $offer['priceCurrency'] = $currency;
                }

                $bodytext = $this->sanitizeText((string) ($card['bodytext'] ?? ''));
                if ('' !== $bodytext) {
                    $offer['description'] = mb_substr($bodytext, 0, 500);
                }

                ++$position;
                $items[] = [
                    '@type' => 'OfferCatalog',
                    'position' => $position,
                    'itemListElement' => [$offer],
                ];
            }
        }

        if ([] === $items) {
            return null;
        }

        return [
            '@type' => 'OfferCatalog',
            'name' => 'Tarife & Bausteine',
            'itemListElement' => $items,
        ];
    }

    /**
     * Aggregate all non-Enterprise card prices into a single AggregateOffer
     * with lowPrice / highPrice. Returns null if no priced cards exist.
     *
     * @param list<array<string, mixed>> $contentElements
     *
     * @return array<string, mixed>|null
     */
    private function buildAggregateOffer(array $contentElements, string $pageUrl): ?array
    {
        $prices = [];
        $currency = 'EUR';

        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') !== self::PACKAGES_GRID_CTYPE) {
                continue;
            }
            foreach ($this->loadCards((int) ($element['uid'] ?? 0)) as $card) {
                if (self::isEnterpriseCard($card)) {
                    continue;
                }
                $price = (float) ($card['price'] ?? 0);
                if ($price <= 0) {
                    continue;
                }
                $prices[] = $price;
                $cardCurrency = strtoupper($this->sanitizeText((string) ($card['price_currency'] ?? 'EUR')));
                if ('' !== $cardCurrency) {
                    $currency = $cardCurrency;
                }
            }
        }

        if ([] === $prices) {
            return null;
        }

        return [
            '@type' => 'AggregateOffer',
            '@id' => $pageUrl . '#service-offers',
            'priceCurrency' => $currency,
            'lowPrice' => number_format(min($prices), 2, '.', ''),
            'highPrice' => number_format(max($prices), 2, '.', ''),
            'offerCount' => count($prices),
        ];
    }
}
