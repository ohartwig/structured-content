<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Emits a TOP-LEVEL Schema.org/SoftwareApplication for SaaS-style pages that
 * carry a moselwal_packagesgrid Content Block.
 *
 * The PackagesGridProductSchemaGenerator already emits a Product with the
 * SaaS embedded under `isRelatedTo` for entity-graph cohesion. Google's
 * Rich-Result engine, however, only enumerates TOP-LEVEL entities for the
 * "Software-Apps" enhancement report — embedded SoftwareApplication nodes
 * never surface in GSC. This generator therefore emits a parallel top-level
 * SoftwareApplication that shares the AggregateOffer via `@id` reference to
 * `<pageUrl>#offers` (no offer duplication).
 *
 * AggregateRating is forwarded when at least one card carries
 * rating_value + review_count (same source as PackagesGridProductSchemaGenerator).
 */
final class PackagesGridSoftwareApplicationSchemaGenerator implements SchemaGeneratorInterface
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
        // Yield to the open-source SoftwareApplicationSchemaGenerator when the
        // page explicitly opted in via tx_structuredcontent_schema_type — that
        // generator already covers code-repository / programming-language data.
        $override = trim((string) ($pageData['tx_structuredcontent_schema_type'] ?? ''));
        if ('SoftwareApplication' === $override || 'SoftwareSourceCode' === $override) {
            return false;
        }

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
        $entityBase = EntityBase::fromPageData($pageData);
        $pageSlug = (string) ($pageData['slug'] ?? '/');
        $pageUrl = $siteBase . ('/' === $pageSlug ? '' : $pageSlug);

        $weightedRatingSum = 0.0;
        $reviewSum = 0;
        $imageUrl = null;
        $featureList = [];

        foreach ($contentElements as $element) {
            if (($element['CType'] ?? '') !== self::PACKAGES_GRID_CTYPE) {
                continue;
            }
            foreach ($this->loadCards((int) ($element['uid'] ?? 0)) as $card) {
                if (self::isEnterpriseCard($card)) {
                    continue;
                }
                if ((float) ($card['price'] ?? 0) <= 0) {
                    continue;
                }

                $rv = (float) ($card['rating_value'] ?? 0);
                $rc = (int) ($card['review_count'] ?? 0);
                if ($rv > 0 && $rc > 0) {
                    $weightedRatingSum += $rv * $rc;
                    $reviewSum += $rc;
                }

                if (null === $imageUrl) {
                    $imageUrl = $this->resolveCardImage((int) ($card['uid'] ?? 0), $siteBase);
                }

                $cardHeader = $this->sanitizeText((string) ($card['header'] ?? ''));
                if ('' !== $cardHeader && !in_array($cardHeader, $featureList, true)) {
                    $featureList[] = $cardHeader;
                }
            }
        }

        $applicationCategory = trim((string) ($pageData['tx_structuredcontent_application_category'] ?? ''));
        if ('' === $applicationCategory) {
            $applicationCategory = 'BusinessApplication';
        }

        $description = $this->sanitizeText((string) ($pageData['description'] ?? $pageData['abstract'] ?? ''));
        $data = [
            '@id' => $pageUrl . '#software',
            'name' => $this->sanitizeText((string) ($pageData['title'] ?? '')),
            'url' => $pageUrl,
            'applicationCategory' => $applicationCategory,
            'operatingSystem' => 'Web',
            'offers' => [
                '@id' => $pageUrl . '#offers',
            ],
            'publisher' => [
                '@type' => 'Organization',
                '@id' => $entityBase . '#organization',
            ],
        ];
        if ('' !== $description) {
            $data['description'] = $description;
        }

        if (null !== $imageUrl) {
            $data['image'] = $imageUrl;
            // Google Software-App Rich Result also accepts `screenshot` —
            // reusing the card image keeps the data model honest without
            // forcing editors to upload separate marketing screenshots.
            $data['screenshot'] = $imageUrl;
        }

        if ([] !== $featureList) {
            $data['featureList'] = $featureList;
        }

        if ($reviewSum > 0) {
            $data['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format($weightedRatingSum / $reviewSum, 1, '.', ''),
                'reviewCount' => $reviewSum,
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }

        return new JsonLdDocument('SoftwareApplication', $data);
    }

    public function getPriority(): int
    {
        // Emit just below PackagesGridProductSchemaGenerator (priority 50)
        // so Product appears first in the JSON-LD block, then the parallel
        // SoftwareApplication. Order is cosmetic — Google evaluates all
        // top-level entities equally.
        return 49;
    }

    /**
     * Resolves the first sys_file_reference attached to the card's `image`
     * field and returns an absolute URL Google can fetch. Mirrors the logic
     * in PackagesGridProductSchemaGenerator so both top-level entities can
     * surface a screenshot without re-querying.
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
        $first = $references[array_key_first($references)];
        $publicUrl = $first->getPublicUrl();
        if (null === $publicUrl) {
            return null;
        }
        if (str_starts_with($publicUrl, 'http://') || str_starts_with($publicUrl, 'https://')) {
            return $publicUrl;
        }

        return rtrim($siteBase, '/') . '/' . ltrim($publicUrl, '/');
    }
}
