<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\GlossaryTermRepositoryInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads the featdd/dpn-glossary tables.
 *
 * Each method guards on isAvailable() itself rather than trusting the caller.
 * The tables only exist when the extension is installed, and a forgotten guard
 * would surface as a SQL error on an unrelated page rather than as a missing
 * glossary — so the guard sits where the query is.
 */
final readonly class DoctrineGlossaryTermRepository implements GlossaryTermRepositoryInterface
{
    private const TERM_TABLE = 'tx_dpnglossary_domain_model_term';
    private const DESCRIPTION_TABLE = 'tx_dpnglossary_domain_model_description';
    private const SYNONYM_TABLE = 'tx_dpnglossary_domain_model_synonym';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function isAvailable(): bool
    {
        return ExtensionManagementUtility::isLoaded('dpn_glossary');
    }

    public function findPageSlug(int $pageUid): ?string
    {
        if (!$this->isAvailable() || $pageUid <= 0) {
            return null;
        }

        $qb = $this->queryBuilderFor('pages');
        $slug = $qb->select('slug')
            ->from('pages')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($slug) && '' !== $slug ? $slug : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTermBySlug(string $slug, int $languageId): ?array
    {
        if (!$this->isAvailable() || '' === $slug) {
            return null;
        }

        $qb = $this->queryBuilderFor(self::TERM_TABLE);
        $row = $qb->select('uid', 'name', 'url_segment', 'tooltiptext', 'meta_description', 'seo_title')
            ->from(self::TERM_TABLE)
            ->where(
                $qb->expr()->eq('url_segment', $qb->createNamedParameter($slug)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findTermByUid(int $uid, int $languageId): ?array
    {
        if (!$this->isAvailable() || $uid <= 0) {
            return null;
        }

        $qb = $this->queryBuilderFor(self::TERM_TABLE);
        $row = $qb->select('uid', 'name', 'url_segment', 'tooltiptext', 'meta_description', 'seo_title')
            ->from(self::TERM_TABLE)
            ->where(
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
                $qb->expr()->or(
                    $qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                    $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($uid, ParameterType::INTEGER)),
                ),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function findFirstDescription(int $termUid, int $languageId): ?string
    {
        if (!$this->isAvailable() || $termUid <= 0) {
            return null;
        }

        $qb = $this->queryBuilderFor(self::DESCRIPTION_TABLE);
        // dpn_glossary stores the explanation in `text` (`meaning` is the label).
        $value = $qb->select('text')
            ->from(self::DESCRIPTION_TABLE)
            ->where(
                $qb->expr()->eq('term', $qb->createNamedParameter($termUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    public function findSynonymNames(int $termUid, int $languageId): array
    {
        if (!$this->isAvailable() || $termUid <= 0) {
            return [];
        }

        $qb = $this->queryBuilderFor(self::SYNONYM_TABLE);
        $rows = $qb->select('name')
            ->from(self::SYNONYM_TABLE)
            ->where(
                $qb->expr()->eq('term', $qb->createNamedParameter($termUid, ParameterType::INTEGER)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static fn(array $row): string => (string) ($row['name'] ?? ''),
            $rows,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllTerms(int $languageId): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $qb = $this->queryBuilderFor(self::TERM_TABLE);
        $rows = $qb->select('uid', 'name', 'url_segment', 'tooltiptext')
            ->from(self::TERM_TABLE)
            ->where($qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageId, ParameterType::INTEGER)))
            ->orderBy('name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return $rows;
    }

    private function queryBuilderFor(string $table): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($table);
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        return $qb;
    }
}
