<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Emits Schema.org Person (single) or ItemList of Persons for Team-Content-Blocks.
 *
 * Reads moselwal_person records via the moselwal/person RecordType (Collection
 * field "persons" on the team CType). Falls back gracefully when no persons
 * are linked.
 */
final class PersonSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PersonSchemaBuilder $personBuilder,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        if (!$this->personRecordsAreAvailable()) {
            return false;
        }

        foreach ($contentElements as $element) {
            $cType = strtolower((string) ($element['CType'] ?? ''));
            if (str_contains($cType, 'team')) {
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
        $entityBase = EntityBase::fromPageData($pageData);

        $teamElementUids = [];
        $seenUids = [];
        foreach ($contentElements as $element) {
            $uid = (int) ($element['uid'] ?? 0);
            if ($uid > 0 && isset($seenUids[$uid])) {
                continue;
            }
            $seenUids[$uid] = true;

            $cType = strtolower((string) ($element['CType'] ?? ''));
            if (str_contains($cType, 'team')) {
                $teamElementUids[] = $uid;
            }
        }

        $languageId = (int) ($pageData['sys_language_uid'] ?? 0);

        $persons = [];
        foreach ($teamElementUids as $uid) {
            if (0 === $uid) {
                continue;
            }
            foreach ($this->getPersonRows($uid, $languageId) as $row) {
                $person = $this->personBuilder->buildFromRow($row, $entityBase);
                if (null !== $person) {
                    unset($person['@type']); // ItemList wraps with @type Person itself
                    $persons[] = $person;
                }
            }
        }

        if ([] === $persons) {
            return new JsonLdDocument('Person', []);
        }

        if (1 === count($persons)) {
            return new JsonLdDocument('Person', array_merge(['@type' => 'Person'], $persons[0]));
        }

        return new JsonLdDocument('ItemList', [
            'name' => $this->sanitizeText((string) ($pageData['title'] ?? 'Team')),
            'itemListElement' => array_map(
                static fn(array $person, int $index): array => [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'item' => array_merge(['@type' => 'Person'], $person),
                ],
                $persons,
                array_keys($persons),
            ),
        ]);
    }

    public function getPriority(): int
    {
        return 45;
    }

    /**
     * `moselwal_person` liefert der Content-Blocks-Record-Type aus
     * EXT:content_blocks_collection. Der CType-Match unten ist ein Teilstring
     * auf `team` — bringt ein Sitepackage einen eigenen Team-CType mit, ohne
     * dass die Collection installiert ist, beansprucht dieser Generator die
     * Seite und die Abfrage laeuft in einen SQL-Fehler auf einer Tabelle, die
     * es nicht gibt. Der reisst die Schema-Erzeugung der ganzen Seite mit,
     * nicht nur diesen einen Generator.
     */
    private function personRecordsAreAvailable(): bool
    {
        return isset($GLOBALS['TCA']['moselwal_person']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function getPersonRows(int $parentUid, int $languageId = 0): array
    {
        if (!$this->personRecordsAreAvailable()) {
            return [];
        }

        // Default-language rows first (canonical sorting + identity), then
        // overlay translations per l18n_parent. Same shape as the FAQ /
        // PackagesGrid fixes — pages running with site fallbackType=fallback
        // still get a populated schema with DE content where EN isn't pflegt.
        $qb = $this->connectionPool->getQueryBuilderForTable('moselwal_person');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $defaults = $qb
            ->select('*')
            ->from('moselwal_person')
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

        // TCA-driven Translation-Pointer-Field: moselwal_person nutzt `l10n_parent`
        // (Content-Blocks-Konvention), nicht das historische `l18n_parent`.
        $transField = (string) ($GLOBALS['TCA']['moselwal_person']['ctrl']['transOrigPointerField'] ?? 'l10n_parent');
        $defaultUids = array_map(static fn(array $r): int => (int) $r['uid'], $defaults);
        $tqb = $this->connectionPool->getQueryBuilderForTable('moselwal_person');
        $tqb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $translations = $tqb
            ->select('*')
            ->from('moselwal_person')
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

        $systemFields = [
            'uid', 'pid', 'sys_language_uid', 'l18n_parent', 'l10n_parent', 'l10n_source',
            'l18n_diffsource', 'sorting', 'tstamp', 'crdate', 'cruser_id', 'deleted', 'hidden',
            'starttime', 'endtime', 'fe_group', 'tablenames', 'foreign_table_parent_uid',
            'fieldname', 't3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage',
        ];

        foreach ($defaults as &$row) {
            $translation = $byParent[(int) $row['uid']] ?? null;
            if (null === $translation) {
                continue;
            }
            foreach ($translation as $key => $value) {
                if (in_array($key, $systemFields, true)) {
                    continue;
                }
                $row[$key] = $value;
            }
        }
        unset($row);

        return $defaults;
    }
}
