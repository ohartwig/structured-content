<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * JSON-LD generator for the packages-grid Content Block.
 *
 * Emits one Schema.org/Product entity with an AggregateOffer over all
 * fix-priced cards (S/M/L SaaS tiers) and an embedded SoftwareApplication
 * via `isRelatedTo` so the SaaS semantics travel inside the same @id graph
 * instead of as a competing root entity.
 *
 * Cards whose `price_suffix` starts with "ab " or "from " (case-insensitive)
 * are treated as Enterprise/Custom services and emitted by the separate
 * PackagesGridEnterpriseServiceSchemaGenerator as Schema.org/Service —
 * keeping AggregateOffer.lowPrice/highPrice meaningful and matching the
 * different price-discovery intent.
 *
 * Sanitisation goes through SanitizesSchemaTrait (paragraph-aware
 * strip_tags) — same path as every other generator in this package.
 */
final class PackagesGridProductSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use PackagesGridCardLoaderTrait;

    private const PACKAGES_GRID_CTYPE = 'moselwal_packagesgrid';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
        private readonly FileRepository $fileRepository,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') !== self::PACKAGES_GRID_CTYPE) {
                continue;
            }
            foreach ($this->loadCards((int) ($element['uid'] ?? 0)) as $card) {
                if (!self::isEnterpriseCard($card) && (float) ($card['price'] ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $pageSlug = (string) ($pageData['slug'] ?? '/');
        $pageUrl = $this->buildPageUrl($siteBase, $pageSlug);
        $pageTitle = $this->sanitizeText((string) ($pageData['title'] ?? ''));

        // Aggregate cards across every packages-grid CB on the page so that
        // pages with multiple grids (e.g. "Full-Service" + "Self-Hosted")
        // emit a single Product whose AggregateOffer covers all fix-priced
        // tiers — otherwise canHandle() can pick a non-Enterprise card from
        // grid B while generate() iterates only grid A and ships an empty
        // Product stub.
        $ttHeader = '';
        $ttBodytext = '';
        $cards = [];
        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') !== self::PACKAGES_GRID_CTYPE) {
                continue;
            }
            $cards = array_merge($cards, $this->loadCards((int) ($element['uid'] ?? 0)));
            if ('' === $ttHeader) {
                $ttHeader = $this->sanitizeText((string) ($element['header'] ?? ''));
            }
            if ('' === $ttBodytext) {
                $ttBodytext = $this->sanitizeText((string) ($element['bodytext'] ?? ''));
            }
        }

        $offers = [];
        $offerPrices = [];
        $priceCurrency = 'EUR';
        $brand = '';
        $imageUrl = null;
        $weightedRatingSum = 0.0;
        $reviewSum = 0;

        foreach ($cards as $card) {
            if (self::isEnterpriseCard($card)) {
                continue;
            }
            $price = (float) ($card['price'] ?? 0);
            if ($price <= 0) {
                continue;
            }

            // Card uid suffixes the slug so two cards with the same header
            // (e.g. "S — Starter" on the Full-Service grid and on the
            // Self-Hosted grid) don't collide on a shared @id inside the
            // AggregateOffer.
            $uid = (int) ($card['uid'] ?? 0);
            $headerSlug = self::slugify((string) ($card['header'] ?? ''));
            $slug = $uid > 0 ? $headerSlug . '-' . $uid : $headerSlug;
            $cardCurrency = strtoupper($this->sanitizeText((string) ($card['price_currency'] ?? 'EUR')));
            $priceCurrency = $cardCurrency;
            $availability = $this->sanitizeText((string) ($card['availability'] ?? 'InStock'));

            $offer = [
                '@type' => 'Offer',
                '@id' => $pageUrl . '#' . $slug,
                'name' => $this->sanitizeText((string) ($card['header'] ?? '')),
                'price' => number_format($price, 2, '.', ''),
                'priceCurrency' => $cardCurrency,
                'availability' => 'https://schema.org/' . $availability,
                'url' => $pageUrl . '#' . $slug,
            ];

            // Schema.org UnitPriceSpecification fuer Subscription-Produkte:
            // Bei monthly/yearly bekommt der Offer eine priceSpecification mit
            // referenceQuantity (unitCode MON/ANN nach UN/CEFACT). Bei oneTime
            // und unspecified bleibt der Offer mit nur dem flachen price.
            // Vorbild: https://stackoverflow.com/a/27035954
            $billingPeriod = (string) ($card['billing_period'] ?? '');
            $priceSpec = self::buildPriceSpecification($billingPeriod, $price, $cardCurrency);
            if (null !== $priceSpec) {
                $offer['priceSpecification'] = $priceSpec;
            }

            // Schema.org/Offer doesn't carry additionalProperty directly —
            // SoftwareApplication does. Per-tier features (extracted from
            // bodytext <li>Name: Value</li> pairs) hang off Offer.itemOffered
            // so each Offer in the AggregateOffer keeps its own feature set.
            $features = $this->extractFeatureProperties((string) ($card['bodytext'] ?? ''));
            if ([] !== $features) {
                $offer['itemOffered'] = [
                    '@type' => 'SoftwareApplication',
                    '@id' => $pageUrl . '#' . $slug . '-software',
                    'name' => $this->sanitizeText((string) ($card['header'] ?? '')),
                    'applicationCategory' => 'BusinessApplication',
                    'operatingSystem' => 'Web',
                    'additionalProperty' => $features,
                ];
            }

            $offers[] = $offer;
            $offerPrices[] = $price;

            if ('' === $brand) {
                $brand = $this->sanitizeText((string) ($card['brand'] ?? ''));
            }
            if (null === $imageUrl) {
                $imageUrl = $this->resolveCardImage((int) ($card['uid'] ?? 0), $siteBase);
            }

            $rv = (float) ($card['rating_value'] ?? 0);
            $rc = (int) ($card['review_count'] ?? 0);
            if ($rv > 0 && $rc > 0) {
                $weightedRatingSum += $rv * $rc;
                $reviewSum += $rc;
            }
        }

        $productName = '' !== $ttHeader
            ? trim($pageTitle . ' — ' . $ttHeader)
            : $pageTitle;
        $productDescription = '' !== $ttBodytext
            ? $ttBodytext
            : $this->sanitizeText((string) ($pageData['description'] ?? ''));

        // canHandle() guarantees at least one fix-priced non-Enterprise card,
        // so $offerPrices is non-empty when we reach generate(). The explicit
        // check satisfies PHPStan and guards against future refactors that
        // might invoke generate() without the canHandle gate.
        if ([] === $offerPrices) {
            return new JsonLdDocument('Product', []);
        }

        $aggregateOffer = [
            '@type' => 'AggregateOffer',
            '@id' => $pageUrl . '#offers',
            'priceCurrency' => $priceCurrency,
            'lowPrice' => number_format(min($offerPrices), 2, '.', ''),
            'highPrice' => number_format(max($offerPrices), 2, '.', ''),
            'offerCount' => (string) count($offerPrices),
            'offers' => $offers,
        ];

        $data = [
            '@id' => $pageUrl . '#platform',
            'name' => $productName,
            'description' => $productDescription,
        ];

        if (null !== $imageUrl) {
            $data['image'] = $imageUrl;
        }

        if ('' !== $brand) {
            $data['brand'] = [
                '@type' => 'Brand',
                'name' => $brand,
            ];
        }

        $data['offers'] = $aggregateOffer;

        // SoftwareApplication is linked, not nested as a competing root —
        // it shares the offers via @id reference, avoiding duplicate Offer
        // graphs when crawlers walk the entity tree.
        $data['isRelatedTo'] = [
            '@type' => 'SoftwareApplication',
            '@id' => $pageUrl . '#software',
            'name' => $productName,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'softwareVersion' => 'latest',
            'offers' => [
                '@id' => $pageUrl . '#offers',
            ],
        ];

        if ($reviewSum > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format($weightedRatingSum / $reviewSum, 1, '.', ''),
                'reviewCount' => $reviewSum,
            ];
        }

        return new JsonLdDocument('Product', $data);
    }

    public function getPriority(): int
    {
        return 50;
    }

    /**
     * Baut Schema.org/UnitPriceSpecification fuer Subscription-Produkte.
     *
     * Mappt billing_period (`monthly`/`yearly`) auf UN/CEFACT unitCodes
     * (`MON`/`ANN`). Bei `oneTime` oder leerem Wert: kein priceSpecification
     * — Offer.price genuegt fuer einmalige Zahlungen.
     *
     * @return array<string, mixed>|null
     */
    private static function buildPriceSpecification(string $billingPeriod, float $price, string $currency): ?array
    {
        $unitCode = match ($billingPeriod) {
            'monthly' => 'MON',
            'yearly' => 'ANN',
            default => null,
        };
        if (null === $unitCode) {
            return null;
        }

        return [
            '@type' => 'UnitPriceSpecification',
            'price' => number_format($price, 2, '.', ''),
            'priceCurrency' => $currency,
            'referenceQuantity' => [
                '@type' => 'QuantitativeValue',
                'value' => '1',
                'unitCode' => $unitCode,
            ],
        ];
    }

    /**
     * Resolves the first sys_file_reference attached to the card's `image`
     * field and returns an absolute URL Google can fetch.
     */
    private function resolveCardImage(int $cardUid, string $siteBase): ?string
    {
        if (0 === $cardUid) {
            return null;
        }

        try {
            $references = $this->fileRepository->findByRelation(self::CARD_TABLE, 'moselwal_card_image', $cardUid);
        } catch (\Throwable) {
            return null;
        }

        if ([] === $references) {
            return null;
        }

        $publicUrl = (string) $references[0]->getPublicUrl();
        if ('' === $publicUrl) {
            return null;
        }

        if (1 === preg_match('#^https?://#i', $publicUrl)) {
            return $publicUrl;
        }
        if ('' === $siteBase) {
            return $publicUrl;
        }

        return $siteBase . '/' . ltrim($publicUrl, '/');
    }

    private function buildPageUrl(string $siteBase, string $pageSlug): string
    {
        if ('' === $pageSlug || '/' === $pageSlug) {
            return $siteBase;
        }

        return rtrim($siteBase, '/') . '/' . ltrim($pageSlug, '/');
    }
}
