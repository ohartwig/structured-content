<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FaqSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            if (str_contains($cType, 'faq')) {
                return true;
            }
            if ('text' === $cType && $this->looksLikeFaq($element)) {
                return true;
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
        $mainEntity = [];
        $processedUids = [];

        foreach ($contentElements as $element) {
            $uid = (int) ($element['uid'] ?? 0);
            if ($uid > 0 && isset($processedUids[$uid])) {
                continue;
            }
            $processedUids[$uid] = true;

            $cType = strtolower((string) ($element['CType'] ?? ''));

            if (str_contains($cType, 'faq')) {
                $pairs = $this->extractFromCards((int) ($element['uid'] ?? 0));
            } elseif ($this->looksLikeFaq($element)) {
                $pairs = $this->extractQaPairsFromText($element);
            } else {
                continue;
            }

            foreach ($pairs as $pair) {
                $mainEntity[] = [
                    '@type' => 'Question',
                    'name' => $pair['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $pair['answer'],
                    ],
                ];
            }
        }

        // Spec: FAQPage MUSS mindestens eine Question im mainEntity haben.
        // canHandle() triggert per `looksLikeFaq()` schon bei einem Text-CE
        // mit ≥3 Fragezeichen (rhetorische Fragen im Article-Body z.B.),
        // aber extractQaPairsFromText() liefert dann oft keine echten Pairs.
        // Leeres FAQPage waere Spec-Violation und schwaecht Google Rich-
        // Results → kein Document statt leeres Document zurueckliefern.
        // Der Middleware-Filter array_filter([] !== $doc->data) entfernt es.
        if ([] === $mainEntity) {
            return new JsonLdDocument('FAQPage', []);
        }

        return new JsonLdDocument('FAQPage', [
            'name' => $this->sanitizeText($pageData['title'] ?? ''),
            'mainEntity' => $mainEntity,
        ]);
    }

    public function getPriority(): int
    {
        return 70;
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function extractFromCards(int $parentUid): array
    {
        if (0 === $parentUid) {
            return [];
        }

        $languageId = 0;
        try {
            $languageId = $this->context->getAspect('language')->getId();
        } catch (\Exception) {
            // No language aspect available → default to language 0
        }

        // Always load default-language cards first — they are the canonical set
        // and provide the sorting source-of-truth. For non-default languages we
        // then bulk-load translations and overlay them on a per-card basis.
        // This matches TYPO3's site-level "fallback to default" behavior so a
        // page that displays fallback FAQ content also emits a fallback Schema.
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('moselwal_card');
        $queryBuilder->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $defaultCards = $queryBuilder
            ->select('uid', 'sorting', 'moselwal_card_header', 'moselwal_card_bodytext')
            ->from('moselwal_card')
            ->where(
                $queryBuilder->expr()->eq(
                    'tablenames',
                    $queryBuilder->createNamedParameter('tt_content'),
                ),
                $queryBuilder->expr()->eq(
                    'foreign_table_parent_uid',
                    $queryBuilder->createNamedParameter($parentUid, ParameterType::INTEGER),
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter(0, ParameterType::INTEGER),
                ),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $defaultCards) {
            return [];
        }

        // Non-default request → bulk-load translations and overlay where present.
        // Translation records carry the answer text in the same prefixed columns
        // but in the requested language; sorting follows the default-language record.
        // TCA-driven Translation-Pointer-Field: moselwal_card nutzt `l10n_parent`
        // (Content-Blocks-Konvention), nicht das historische `l18n_parent`.
        if ($languageId > 0) {
            $transField = (string) ($GLOBALS['TCA']['moselwal_card']['ctrl']['transOrigPointerField'] ?? 'l10n_parent');
            $defaultUids = array_map(static fn(array $c): int => (int) $c['uid'], $defaultCards);
            $translationQuery = $this->connectionPool->getQueryBuilderForTable('moselwal_card');
            $translationQuery->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
            $translations = $translationQuery
                ->select($transField, 'moselwal_card_header', 'moselwal_card_bodytext')
                ->from('moselwal_card')
                ->where(
                    $translationQuery->expr()->eq(
                        'sys_language_uid',
                        $translationQuery->createNamedParameter($languageId, ParameterType::INTEGER),
                    ),
                    $translationQuery->expr()->in(
                        $transField,
                        $translationQuery->createNamedParameter($defaultUids, ArrayParameterType::INTEGER),
                    ),
                )
                ->executeQuery()
                ->fetchAllAssociative();

            $byParent = [];
            foreach ($translations as $t) {
                $byParent[(int) $t[$transField]] = $t;
            }
            foreach ($defaultCards as &$card) {
                $translation = $byParent[(int) $card['uid']] ?? null;
                if (null !== $translation) {
                    $card['moselwal_card_header'] = $translation['moselwal_card_header'];
                    $card['moselwal_card_bodytext'] = $translation['moselwal_card_bodytext'];
                }
            }
            unset($card);
        }

        $pairs = [];
        foreach ($defaultCards as $card) {
            $question = $this->sanitizeText((string) ($card['moselwal_card_header'] ?? ''));
            $answer = $this->sanitizeText((string) ($card['moselwal_card_bodytext'] ?? ''));
            if ('' !== $question && '' !== $answer) {
                $pairs[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $pairs;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function looksLikeFaq(array $element): bool
    {
        $header = strtolower((string) ($element['header'] ?? ''));
        $bodytext = strtolower((string) ($element['bodytext'] ?? ''));

        return str_contains($header, 'faq')
            || str_contains($header, 'fragen')
            || str_contains($header, 'q&a')
            || substr_count($bodytext, '?') >= 3;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return list<array{question: string, answer: string}>
     */
    private function extractQaPairsFromText(array $element): array
    {
        $pairs = [];
        $bodytext = $element['bodytext'] ?? '';
        $lines = preg_split('/\n+/', strip_tags($bodytext));

        if (false === $lines) {
            return [];
        }

        $currentQuestion = null;
        $currentAnswer = '';

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }

            if (str_ends_with($line, '?')) {
                if (null !== $currentQuestion && '' !== $currentAnswer) {
                    $pairs[] = [
                        'question' => $this->sanitizeText($currentQuestion),
                        'answer' => $this->sanitizeText($currentAnswer),
                    ];
                }
                $currentQuestion = $line;
                $currentAnswer = '';
            } elseif (null !== $currentQuestion) {
                $currentAnswer .= ' ' . $line;
            }
        }

        if (null !== $currentQuestion && '' !== $currentAnswer) {
            $pairs[] = [
                'question' => $this->sanitizeText($currentQuestion),
                'answer' => $this->sanitizeText($currentAnswer),
            ];
        }

        return $pairs;
    }
}
