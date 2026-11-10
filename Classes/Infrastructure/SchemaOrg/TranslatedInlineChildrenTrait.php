<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Inline children (Content Blocks collections such as moselwal_card or
 * moselwal_person) of a content element that is shown as a translation.
 *
 * The content elements reach the generators the way the frontend sees them:
 * default-language rows run through PageRepository::getLanguageOverlay(). A
 * row that found its translation keeps the default `uid` and carries the
 * translation's uid in `_LOCALIZED_UID`.
 *
 * Inline children are not overlaid along with their parent. A translated
 * element owns its own child records: their `foreign_table_parent_uid` is the
 * uid of the translation, in connected mode (children copied on localisation,
 * `l10n_parent` set) as well as in free mode (children created on the
 * translation, `l10n_parent = 0`). The core's RelationResolver, which Content
 * Blocks uses to render a collection, therefore resolves the children of a
 * translated element by `_LOCALIZED_UID` and never falls back to the
 * children of the default-language element. When the translation has no
 * children, the page shows none.
 *
 * An element created directly in a translation (free mode, `l18n_parent =
 * 0`) reaches the generators as its own row, with its own `uid` and its
 * language in `sys_language_uid` and no `_LOCALIZED_UID`; so does every
 * translation under fallbackType `free`. Its children are attached to that
 * `uid`, exactly like those of an overlaid translation are attached to
 * `_LOCALIZED_UID`. inlineParentUid() names the uid the children hang on in
 * either case.
 *
 * Reading the default-language children and overlaying them per
 * `l10n_parent` is only right for an element that is NOT translated and is
 * displayed in the default language (fallbackType `fallback`). For a
 * translated element it emitted the default-language texts, and it did so
 * silently: free-mode children have no `l10n_parent`, so nothing overlaid.
 *
 * Owners must declare ConnectionPool as the constructor property
 * `$connectionPool`.
 */
trait TranslatedInlineChildrenTrait
{
    /**
     * The children the frontend renders for a translated element, in their
     * sorting order — or null when the element is not a translation, in which
     * case the caller keeps its default-language path.
     *
     * Rows in a language other than the element's are dropped, as
     * PageRepository::getLanguageOverlay() does in the frontend; rows for all
     * languages (-1) stay.
     *
     * @param array<string, mixed> $element content element as overlaid for the requested language
     * @param list<string>         $columns columns to select; empty selects all
     *
     * @return list<array<string, mixed>>|null
     */
    private function loadChildrenOfTranslatedElement(string $table, array $element, array $columns = []): ?array
    {
        if (!self::isShownAsTranslation($element)) {
            return null;
        }
        $localizedUid = self::inlineParentUid($element);
        if ($localizedUid <= 0) {
            return null;
        }

        $languageField = (string) ($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        if ('' === $languageField) {
            $languageField = 'sys_language_uid';
        }
        $select = [] === $columns
            ? ['*']
            : array_values(array_unique([...$columns, $languageField]));

        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $rows = $qb
            ->select(...$select)
            ->from($table)
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('foreign_table_parent_uid', $qb->createNamedParameter($localizedUid, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $elementLanguage = (int) ($element['sys_language_uid'] ?? 0);
        $children = [];
        foreach ($rows as $row) {
            $language = (int) ($row[$languageField] ?? 0);
            if ($language > 0 && $language !== $elementLanguage) {
                continue;
            }
            $children[] = $row;
        }

        return $children;
    }

    /**
     * Whether the element is shown as a translation: overlaid onto its
     * default-language row (`_LOCALIZED_UID`), or a row in a translation
     * language of its own (free mode, or any translation under fallbackType
     * `free`).
     *
     * @param array<string, mixed> $element
     */
    private static function isShownAsTranslation(array $element): bool
    {
        return (int) ($element['_LOCALIZED_UID'] ?? 0) > 0
            || (int) ($element['sys_language_uid'] ?? 0) > 0;
    }

    /**
     * The uid the element's inline children are attached to
     * (`foreign_table_parent_uid`): the translation's uid for an overlaid
     * element, the element's own uid otherwise.
     *
     * @param array<string, mixed> $element
     */
    private static function inlineParentUid(array $element): int
    {
        $localizedUid = (int) ($element['_LOCALIZED_UID'] ?? 0);

        return $localizedUid > 0 ? $localizedUid : (int) ($element['uid'] ?? 0);
    }
}
