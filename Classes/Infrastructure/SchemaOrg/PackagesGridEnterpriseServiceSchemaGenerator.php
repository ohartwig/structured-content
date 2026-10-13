<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Companion to PackagesGridProductSchemaGenerator.
 *
 * Cards in a packages-grid whose price_suffix starts with "ab " or "from "
 * (case-insensitive) carry a different intent signal: variable pricing,
 * consulting / custom scope, decision-by-conversation. Those don't fit an
 * AggregateOffer alongside the fix-priced SaaS tiers — Google's Rich Result
 * test treats them as a separate price-discovery flow, and editors expect
 * the Enterprise card to surface as a Service entity, not as a cheap-looking
 * Offer in the price-range readout.
 *
 * Emits one Schema.org/Service per Enterprise card. The Service uses
 * `offers.priceSpecification.minPrice` instead of a flat price, mirroring
 * the "ab"/"from" semantics of the displayed suffix.
 */
final class PackagesGridEnterpriseServiceSchemaGenerator implements SchemaGeneratorInterface
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
                if (self::isEnterpriseCard($card)) {
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

        $services = [];

        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') !== self::PACKAGES_GRID_CTYPE) {
                continue;
            }

            foreach ($this->loadCards((int) ($element['uid'] ?? 0)) as $card) {
                if (!self::isEnterpriseCard($card)) {
                    continue;
                }
                $service = $this->cardToService($card, $pageUrl, $siteBase);
                if (null !== $service) {
                    $services[] = $service;
                }
            }
        }

        if ([] === $services) {
            return new JsonLdDocument('ItemList', ['itemListElement' => []]);
        }

        if (1 === count($services)) {
            $first = $services[0];
            unset($first['@type']);

            return new JsonLdDocument('Service', $first);
        }

        return new JsonLdDocument('ItemList', ['itemListElement' => $services]);
    }

    public function getPriority(): int
    {
        // Same band as the Product generator — both run for the same CB and
        // describe complementary entities.
        return 50;
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return array<string, mixed>|null
     */
    private function cardToService(array $card, string $pageUrl, string $siteBase): ?array
    {
        $name = $this->sanitizeText((string) ($card['header'] ?? ''));
        if ('' === $name) {
            return null;
        }

        // Card uid distinguishes cards that share a header (e.g. two
        // "XL — Enterprise" cards on the Full-Service vs Self-Hosted grids
        // would otherwise collide on the same @id).
        $uid = (int) ($card['uid'] ?? 0);
        $headerSlug = self::slugify((string) ($card['header'] ?? ''));
        $slug = $uid > 0 ? $headerSlug . '-' . $uid : $headerSlug;
        $price = (float) ($card['price'] ?? 0);
        $currency = strtoupper($this->sanitizeText((string) ($card['price_currency'] ?? 'EUR')));

        $data = [
            '@type' => 'Service',
            '@id' => $pageUrl . '#' . $slug,
            'name' => $name,
            'description' => $this->sanitizeText((string) ($card['bodytext'] ?? '')),
        ];

        $imageUrl = $this->resolveCardImage((int) ($card['uid'] ?? 0), $siteBase);
        if (null !== $imageUrl) {
            $data['image'] = $imageUrl;
        }

        $brand = $this->sanitizeText((string) ($card['brand'] ?? ''));
        if ('' !== $brand) {
            $data['provider'] = [
                '@type' => 'Organization',
                'name' => $brand,
            ];
        }

        $sku = $this->sanitizeText((string) ($card['sku'] ?? ''));
        if ('' !== $sku) {
            $data['sku'] = $sku;
        }

        // Service carries additionalProperty directly per Schema.org — same
        // bodytext <li>Name: Value</li> extraction as the SaaS Product
        // generator, but inlined on the Service entity rather than on a
        // wrapped SoftwareApplication.
        $features = $this->extractFeatureProperties((string) ($card['bodytext'] ?? ''));
        if ([] !== $features) {
            $data['additionalProperty'] = $features;
        }

        if ($price > 0) {
            $data['offers'] = [
                '@type' => 'Offer',
                'url' => $pageUrl . '#' . $slug,
                'priceSpecification' => [
                    '@type' => 'PriceSpecification',
                    'priceCurrency' => $currency,
                    'minPrice' => number_format($price, 2, '.', ''),
                ],
            ];
        }

        $rating = (float) ($card['rating_value'] ?? 0);
        $reviewCount = (int) ($card['review_count'] ?? 0);
        if ($rating > 0 && $reviewCount > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format($rating, 1, '.', ''),
                'reviewCount' => $reviewCount,
            ];
        }

        return $data;
    }

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
