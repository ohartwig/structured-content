<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Shared card-loading helpers for the two packages-grid generators.
 *
 * Owners must declare ConnectionPool and Context as constructor properties.
 */
trait PackagesGridCardLoaderTrait
{
    private const CARD_TABLE = 'moselwal_card';
    private const ENTERPRISE_SUFFIX_PREFIXES = ['ab', 'from'];

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCards(int $contentUid): array
    {
        if (0 === $contentUid) {
            return [];
        }

        $languageId = 0;
        try {
            $languageId = $this->context->getAspect('language')->getId();
        } catch (\Exception) {
            // No language aspect → default to language 0
        }

        // Load default-language cards first as canonical set (sorting + uid
        // source-of-truth). Translation overlay happens in a second pass so
        // pages running with site fallbackType=fallback still emit a
        // populated schema (DE-content stands in where EN-card isn't pflegt).
        $qb = $this->connectionPool->getQueryBuilderForTable(self::CARD_TABLE);
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $defaults = $qb->select('*')
            ->from(self::CARD_TABLE)
            ->where(
                $qb->expr()->eq('tablenames', $qb->createNamedParameter('tt_content')),
                $qb->expr()->eq('foreign_table_parent_uid', $qb->createNamedParameter($contentUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ([] === $defaults || 0 === $languageId) {
            return array_map(self::stripCardPrefix(...), $defaults);
        }

        // Bulk-load translations, overlay per identity. CARD_TABLE ist
        // moselwal_card (Content-Blocks-Konvention `l10n_parent`).
        $transField = (string) ($GLOBALS['TCA'][self::CARD_TABLE]['ctrl']['transOrigPointerField'] ?? 'l10n_parent');
        $defaultUids = array_map(static fn(array $r): int => (int) $r['uid'], $defaults);
        $tqb = $this->connectionPool->getQueryBuilderForTable(self::CARD_TABLE);
        $tqb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $translations = $tqb->select('*')
            ->from(self::CARD_TABLE)
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
            // Overlay all content fields — keep system fields from the default
            // row (uid, sorting, relation pair, language metadata) so downstream
            // ordering and identity stay stable.
            $row = self::overlayContentFields($row, $translation);
        }
        unset($row);

        return array_map(self::stripCardPrefix(...), $defaults);
    }

    /** @var list<string> */
    private const SYSTEM_FIELDS = [
        'uid', 'pid', 'sys_language_uid', 'l18n_parent', 'l10n_parent', 'l10n_source',
        'l18n_diffsource', 'sorting', 'tstamp', 'crdate', 'cruser_id', 'deleted', 'hidden',
        'starttime', 'endtime', 'fe_group', 'tablenames', 'foreign_table_parent_uid',
        'fieldname', 't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage',
    ];

    /**
     * Returns the default row with all non-system fields from the translation
     * overlaid on top. Keeps uid/sorting/relation identity stable for downstream
     * sorting and dedupe.
     *
     * @param array<string, mixed> $defaultRow
     * @param array<string, mixed> $translationRow
     *
     * @return array<string, mixed>
     */
    private static function overlayContentFields(array $defaultRow, array $translationRow): array
    {
        foreach ($translationRow as $key => $value) {
            if (in_array($key, self::SYSTEM_FIELDS, true)) {
                continue;
            }
            $defaultRow[$key] = $value;
        }

        return $defaultRow;
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return array<string, mixed>
     */
    private static function stripCardPrefix(array $card): array
    {
        $stripped = [];
        foreach ($card as $key => $value) {
            $newKey = str_starts_with($key, 'moselwal_card_') ? substr($key, 14) : $key;
            $stripped[$newKey] = $value;
        }

        return $stripped;
    }

    /**
     * A card belongs to the Enterprise/Custom track when its price_suffix
     * starts with "ab " or "from " (case-insensitive). That mirrors the
     * common DACH price-display convention "ab 5.900 €/Monat" / "from
     * 5,900 €/month" — the price is a starting point, the deal is a
     * service contract, not a checkout-able offer.
     *
     * @param array<string, mixed> $card
     */
    private static function isEnterpriseCard(array $card): bool
    {
        $suffix = strtolower(trim((string) ($card['price_suffix'] ?? '')));
        if ('' === $suffix) {
            return false;
        }
        foreach (self::ENTERPRISE_SUFFIX_PREFIXES as $prefix) {
            if ($suffix === $prefix || str_starts_with($suffix, $prefix . ' ')) {
                return true;
            }
        }

        return false;
    }

    private static function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'é', 'è', 'ê', 'à', 'â'],
            ['ae', 'oe', 'ue', 'ss', 'e', 'e', 'e', 'a', 'a'],
            $text,
        );
        $text = (string) preg_replace('/[^a-z0-9\-]/', '-', $text);
        $text = (string) preg_replace('/-{2,}/', '-', $text);

        return trim($text, '-');
    }

    /**
     * Pulls Schema.org PropertyValue entries out of a card's RTE bodytext.
     *
     * Convention: each <li> whose text contains a colon is split into
     * name : value (first colon wins). Items without a colon are skipped —
     * Google's structured data treats nameless PropertyValues as noise, and
     * a plain bullet ("1 Site, < 500 Pages") doesn't expose a property
     * name, so it stays in the description prose instead.
     *
     * Examples that match:
     *   <li>AI Workflows: 1–3</li>           → {name: 'AI Workflows', value: '1–3'}
     *   <li>Sprachen: 1</li>                  → {name: 'Sprachen',     value: '1'}
     *
     * Examples that intentionally don't match:
     *   <li>1 Site, < 500 Pages</li>          (no colon, no property name)
     *   <li>Standard Content Blocks</li>      (no colon, pure inclusion)
     *
     * @return list<array<string, string>>
     */
    private function extractFeatureProperties(string $bodytext): array
    {
        if ('' === $bodytext) {
            return [];
        }

        $matched = preg_match_all('#<li[^>]*>(.+?)</li>#is', $bodytext, $matches);
        if (false === $matched || 0 === $matched) {
            return [];
        }

        $properties = [];
        foreach ($matches[1] as $rawItem) {
            $text = $this->sanitizeText($rawItem);
            if ('' === $text || !str_contains($text, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $text, 2);
            $name = trim($name);
            $value = trim($value);
            if ('' === $name || '' === $value) {
                continue;
            }

            $properties[] = [
                '@type' => 'PropertyValue',
                'name' => $name,
                'value' => $value,
            ];
        }

        return $properties;
    }
}
