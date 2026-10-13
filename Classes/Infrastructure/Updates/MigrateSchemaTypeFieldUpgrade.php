<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Updates;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Copies values from the legacy `tx_semanticdelivery_schema_type` field
 * to the new `tx_structuredcontent_schema_type` field, then clears the
 * legacy column so the database schema can drop it.
 *
 * Idempotent — re-running the wizard after migration is a no-op.
 */
#[UpgradeWizard('migrateSchemaTypeField')]
final class MigrateSchemaTypeFieldUpgrade implements UpgradeWizardInterface
{
    public function getTitle(): string
    {
        return 'Migrate schema type field from semantic-delivery to structured-content';
    }

    public function getDescription(): string
    {
        return 'Copies pages.tx_semanticdelivery_schema_type values to '
            . 'pages.tx_structuredcontent_schema_type. Clears the legacy '
            . 'field so its column can be dropped on the next schema update.';
    }

    public function updateNecessary(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        $columnNames = $connection->getSchemaInformation()->listTableColumnNames('pages');
        if (!in_array('tx_semanticdelivery_schema_type', $columnNames, true)) {
            return false;
        }

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $count = $queryBuilder
            ->count('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->neq(
                    'tx_semanticdelivery_schema_type',
                    $queryBuilder->createNamedParameter('', ParameterType::STRING),
                ),
            )
            ->executeQuery()
            ->fetchOne();

        return (int) $count > 0;
    }

    public function executeUpdate(): bool
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages');

        $queryBuilder = $connection->createQueryBuilder();
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('uid', 'tx_semanticdelivery_schema_type')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->neq(
                    'tx_semanticdelivery_schema_type',
                    $queryBuilder->createNamedParameter('', ParameterType::STRING),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $connection->update(
                'pages',
                [
                    'tx_structuredcontent_schema_type' => (string) $row['tx_semanticdelivery_schema_type'],
                    'tx_semanticdelivery_schema_type' => '',
                ],
                ['uid' => (int) $row['uid']],
                [
                    'tx_structuredcontent_schema_type' => ParameterType::STRING,
                    'tx_semanticdelivery_schema_type' => ParameterType::STRING,
                ],
            );
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }
}
