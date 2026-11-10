<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\Middleware;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Schema\LanguageMarker;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The content elements of a page as the frontend renders them in one
 * language, for JsonLdInjectorMiddleware to hand to the schema generators.
 *
 * The selection mirrors ContentObjectRenderer::getRecords() with the language
 * restriction of ContentObjectRenderer::getLanguageRestriction(), which is
 * what `CONTENT` / `styles.content.get` use for tt_content:
 *
 * - With overlays (fallbackType `fallback` and `strict`): rows in the default
 *   language and in all languages (-1) are selected and overlaid with their
 *   translation. Rows created directly in the requested language without a
 *   default-language counterpart (free mode, `l18n_parent = 0`) are selected
 *   as well, except for the overlay type OVERLAYS_ON, which TYPO3 reserves
 *   for "never show floating records".
 * - Without overlays (fallbackType `free`): only rows in the requested
 *   language and in all languages are selected, connected or not.
 *
 * PageRepository::getLanguageOverlay() then does what it does in the
 * frontend: it replaces the content of a default row with its translation
 * (the row keeps the default `uid` and carries the translation's uid in
 * `_LOCALIZED_UID`), drops an untranslated row under `strict`, keeps it under
 * `fallback`, and passes floating and all-language rows through unchanged.
 *
 * A connected translation is therefore never selected next to its default
 * row: the floating condition requires `l18n_parent = 0`, and without
 * overlays the default rows are not selected at all. Each element is still
 * keyed by the record it shows, so one record can never reach the generators
 * twice.
 *
 * The order is the frontend's within each column: `sorting`. Rows of all
 * columns come in one list, as before.
 */
final readonly class PageContentCollector
{
    private const TABLE = 'tt_content';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function findRenderedContent(int $pageUid, LanguageAspect $languageAspect): array
    {
        if ($pageUid <= 0) {
            return [];
        }

        $languageField = (string) ($GLOBALS['TCA'][self::TABLE]['ctrl']['languageField'] ?? 'sys_language_uid');
        $pointerField = (string) ($GLOBALS['TCA'][self::TABLE]['ctrl']['transOrigPointerField'] ?? 'l18n_parent');
        $contentId = $languageAspect->getContentId();

        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $expr = $qb->expr();

        if ($languageAspect->doOverlays()) {
            $languageConstraint = $expr->in(
                $languageField,
                [0, LanguageMarker::ALL_LANGUAGES],
            );
            if (LanguageAspect::OVERLAYS_ON !== $languageAspect->getOverlayType()) {
                $languageConstraint = $expr->or(
                    $languageConstraint,
                    $expr->and(
                        $expr->eq($pointerField, $qb->createNamedParameter(0, ParameterType::INTEGER)),
                        $expr->eq($languageField, $qb->createNamedParameter($contentId, ParameterType::INTEGER)),
                    ),
                );
            }
        } else {
            $languageConstraint = $expr->in(
                $languageField,
                [$contentId, LanguageMarker::ALL_LANGUAGES],
            );
        }

        $rows = $qb
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $expr->eq('pid', $qb->createNamedParameter($pageUid, ParameterType::INTEGER)),
                $languageConstraint,
            )
            ->orderBy('sorting', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $rows) {
            return [];
        }

        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        $elements = [];
        foreach ($rows as $row) {
            $pageRepository->versionOL(self::TABLE, $row, true);
            if (!is_array($row)) {
                continue;
            }
            $row = $pageRepository->getLanguageOverlay(self::TABLE, $row, $languageAspect);
            if (null === $row) {
                continue;
            }
            $shownUid = (int) ($row['_LOCALIZED_UID'] ?? $row['uid'] ?? 0);
            if (isset($elements[$shownUid])) {
                continue;
            }
            $elements[$shownUid] = $row;
        }

        return array_values($elements);
    }
}
