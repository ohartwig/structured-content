<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Emits Schema.org Review (single) or AggregateRating + Review[] for
 * Testimonial/Review content blocks. Reads moselwal_card records that are
 * attached to a content element with CType containing "testimonial" or
 * "review".
 *
 * Triggered by:
 *  - Manual override pages.tx_structuredcontent_schema_type = "Review"
 *  - OR any content element with CType matching the patterns above
 *
 * Emits:
 *  - Single review → @type Review
 *  - Multiple reviews → @type AggregateRating with reviewCount/ratingValue
 *    plus a list of Review objects under "review"
 */
final class ReviewSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    private const CTYPE_PATTERNS = ['testimonial', 'review'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $override = trim((string) ($pageData['tx_structuredcontent_schema_type'] ?? ''));
        if ('Review' === $override) {
            return true;
        }

        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            foreach (self::CTYPE_PATTERNS as $pattern) {
                if (str_contains($cType, $pattern)) {
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
        $entityBase = EntityBase::fromPageData($pageData);

        $reviewElementUids = [];
        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            foreach (self::CTYPE_PATTERNS as $pattern) {
                if (str_contains($cType, $pattern)) {
                    $reviewElementUids[] = (int) ($element['uid'] ?? 0);
                    break;
                }
            }
        }
        $reviewElementUids = array_values(array_unique(array_filter(
            $reviewElementUids,
            static fn (int $uid): bool => $uid > 0,
        )));

        $languageId = (int) ($pageData['sys_language_uid'] ?? 0);
        $reviews = [];
        $ratingSum = 0.0;
        $ratingCount = 0;

        foreach ($reviewElementUids as $uid) {
            foreach ($this->fetchReviewCards($uid, $languageId) as $card) {
                $review = $this->buildReview($card, $entityBase);
                if (null === $review) {
                    continue;
                }
                $reviews[] = $review;

                $ratingValue = (float) ($card['moselwal_card_rating_value'] ?? 0);
                if ($ratingValue > 0) {
                    $ratingSum += $ratingValue;
                    ++$ratingCount;
                }
            }
        }

        if ([] === $reviews) {
            return new JsonLdDocument('Review', []);
        }

        if (1 === count($reviews)) {
            return new JsonLdDocument('Review', $reviews[0]);
        }

        $aggregate = [
            '@type' => 'AggregateRating',
            'reviewCount' => count($reviews),
            'itemReviewed' => '' !== $entityBase
                ? ['@type' => 'Organization', '@id' => $entityBase . '#organization']
                : ['@type' => 'Organization', 'name' => $this->sanitizeText((string) ($pageData['title'] ?? ''))],
        ];
        if ($ratingCount > 0) {
            $aggregate['ratingValue'] = round($ratingSum / $ratingCount, 2);
            $aggregate['bestRating'] = 5;
            $aggregate['worstRating'] = 1;
        }
        $aggregate['review'] = $reviews;

        return new JsonLdDocument('AggregateRating', $aggregate);
    }

    public function getPriority(): int
    {
        return 68;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchReviewCards(int $parentUid, int $languageId = 0): array
    {
        // Default-language cards first (canonical set + sorting source), then
        // overlay translations per l18n_parent. Same pattern as FAQ +
        // PackagesGrid + Person — keeps schema populated under site-level
        // fallbackType=fallback even when EN-translations are partial/absent.
        $contentCols = [
            'moselwal_card_header',
            'moselwal_card_bodytext',
            'moselwal_card_bodytext2',
            'moselwal_card_works_for',
            'moselwal_card_rating_value',
            'moselwal_card_review_count',
        ];

        $qb = $this->connectionPool->getQueryBuilderForTable('moselwal_card');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $defaults = $qb
            ->select('uid', 'tstamp', 'crdate', 'sorting', ...$contentCols)
            ->from('moselwal_card')
            ->where(
                $qb->expr()->eq('foreign_table_parent_uid', $qb->createNamedParameter($parentUid, ParameterType::INTEGER)),
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $defaults || 0 === $languageId) {
            return $defaults;
        }

        // TCA-driven: moselwal_card nutzt `l10n_parent` (CB-Konvention).
        $transField = (string) ($GLOBALS['TCA']['moselwal_card']['ctrl']['transOrigPointerField'] ?? 'l10n_parent');
        $defaultUids = array_map(static fn(array $r): int => (int) $r['uid'], $defaults);
        $tqb = $this->connectionPool->getQueryBuilderForTable('moselwal_card');
        $tqb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $translations = $tqb
            ->select($transField, ...$contentCols)
            ->from('moselwal_card')
            ->where(
                $tqb->expr()->eq('sys_language_uid', $tqb->createNamedParameter($languageId, ParameterType::INTEGER)),
                $tqb->expr()->in($transField, $tqb->createNamedParameter($defaultUids, ArrayParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $byParent = [];
        foreach ($translations as $t) {
            $byParent[(int) $t[$transField]] = $t;
        }

        foreach ($defaults as &$row) {
            $translation = $byParent[(int) $row['uid']] ?? null;
            if (null === $translation) {
                continue;
            }
            foreach ($contentCols as $col) {
                if (array_key_exists($col, $translation)) {
                    $row[$col] = $translation[$col];
                }
            }
        }
        unset($row);

        return $defaults;
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return array<string, mixed>|null
     */
    private function buildReview(array $card, string $entityBase): ?array
    {
        $authorName = $this->sanitizeText((string) ($card['moselwal_card_header'] ?? ''));
        $body = $this->sanitizeText((string) ($card['moselwal_card_bodytext'] ?? ''));
        if ('' === $authorName && '' === $body) {
            return null;
        }

        $review = ['@type' => 'Review'];

        if ('' !== $authorName) {
            $author = ['@type' => 'Person', 'name' => $authorName];
            $worksFor = trim((string) ($card['moselwal_card_works_for'] ?? ''));
            if ('' !== $worksFor) {
                $author['worksFor'] = [
                    '@type' => 'Organization',
                    'name' => $this->sanitizeText($worksFor),
                ];
            }
            $review['author'] = $author;
        }

        if ('' !== $body) {
            $review['reviewBody'] = $body;
        }

        $rating = (float) ($card['moselwal_card_rating_value'] ?? 0);
        if ($rating > 0) {
            $review['reviewRating'] = [
                '@type' => 'Rating',
                'ratingValue' => $rating,
                'bestRating' => 5,
                'worstRating' => 1,
            ];
        }

        $crdate = (int) ($card['crdate'] ?? 0);
        if ($crdate > 0) {
            $review['datePublished'] = date('c', $crdate);
        }

        if ('' !== $entityBase) {
            $review['itemReviewed'] = ['@type' => 'Organization', '@id' => $entityBase . '#organization'];
        }

        return $review;
    }
}
