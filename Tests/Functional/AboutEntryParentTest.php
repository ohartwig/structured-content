<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Functional;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Service\WorkspaceService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * About entries are IRRE children of a page. Their link to the page lives in
 * parent_uid / parent_table / parent_field. DataHandler drops every field the
 * TCA does not declare, and a table without versioningWS cannot take part in a
 * workspace at all — either way the entry ends up with parent_uid = 0, an
 * orphan that no page shows and no schema generator reads.
 */
final class AboutEntryParentTest extends FunctionalTestCase
{
    private const TABLE = 'tx_structuredcontent_about_entry';
    private const FIELD = 'tx_structuredcontent_about';

    protected array $coreExtensionsToLoad = ['workspaces'];

    protected array $testExtensionsToLoad = ['koh/structured-content'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AboutEntryParent.csv');
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function inlineSaveInLiveLinksEveryEntryToItsPage(): void
    {
        $this->saveSubjectsInline(2, ['TYPO3', 'Kubernetes']);

        $rows = $this->entries();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertLinkedTo(2, $row);
        }
    }

    #[Test]
    public function directCreateKeepsTheParentFields(): void
    {
        // What WriteTable does: the entry is created on its own, with the link
        // passed as plain fields. Without TCA columns DataHandler discards them.
        $this->process([
            self::TABLE => [
                'NEW1' => [
                    'pid' => 2,
                    'parent_uid' => 2,
                    'parent_table' => 'pages',
                    'parent_field' => self::FIELD,
                    'schema_type' => 'Thing',
                    'name' => 'TYPO3',
                ],
            ],
        ]);

        $rows = $this->entries();
        self::assertCount(1, $rows);
        $this->assertLinkedTo(2, $rows[0]);
    }

    #[Test]
    public function tableIsWorkspaceCapable(): void
    {
        self::assertTrue((bool)($GLOBALS['TCA'][self::TABLE]['ctrl']['versioningWS'] ?? false));
        $columns = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable(self::TABLE)
            ->createSchemaManager()
            ->listTableColumns(self::TABLE);
        foreach (['t3ver_oid', 't3ver_wsid', 't3ver_state', 't3ver_stage'] as $column) {
            self::assertArrayHasKey($column, $columns, $column . ' is derived from versioningWS by the core');
        }
    }

    #[Test]
    public function inlineSaveInAWorkspaceKeepsTheLinkThroughPublishing(): void
    {
        $this->switchToWorkspace(1);
        $this->saveSubjectsInline(2, ['TYPO3', 'Kubernetes']);

        $workspaceRows = $this->entries(1);
        self::assertCount(2, $workspaceRows, 'the entries must exist as workspace versions');
        foreach ($workspaceRows as $row) {
            $this->assertLinkedTo(2, $row);
        }
        self::assertCount(0, $this->entries(0), 'nothing may reach live before publishing');

        $this->publishWorkspace(1);

        $liveRows = $this->entries(0);
        self::assertCount(2, $liveRows);
        foreach ($liveRows as $row) {
            $this->assertLinkedTo(2, $row);
        }
    }

    #[Test]
    public function inlineSaveOnATranslatedPageLinksToTheOverlay(): void
    {
        $this->process([
            'pages' => [3 => [self::FIELD => 'NEW1']],
            self::TABLE => [
                'NEW1' => ['pid' => 2, 'sys_language_uid' => 1, 'schema_type' => 'Thing', 'name' => 'Open source'],
            ],
        ]);

        $rows = $this->entries();
        self::assertCount(1, $rows);
        $this->assertLinkedTo(3, $rows[0]);
        self::assertSame(1, (int)$rows[0]['sys_language_uid']);
    }

    /**
     * @param list<string> $names
     */
    private function saveSubjectsInline(int $pageUid, array $names): void
    {
        $children = [];
        foreach ($names as $i => $name) {
            $children['NEW' . ($i + 1)] = ['pid' => $pageUid, 'schema_type' => 'Thing', 'name' => $name];
        }

        $this->process([
            'pages' => [$pageUid => [self::FIELD => implode(',', array_keys($children))]],
            self::TABLE => $children,
        ]);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $datamap
     */
    private function process(array $datamap): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($datamap, []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, implode(' | ', $dataHandler->errorLog));
    }

    private function publishWorkspace(int $workspaceId): void
    {
        $cmdmap = GeneralUtility::makeInstance(WorkspaceService::class)->getCmdArrayForPublishWS($workspaceId);
        self::assertNotSame([], $cmdmap);

        $this->switchToWorkspace(0);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $cmdmap);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, implode(' | ', $dataHandler->errorLog));
    }

    private function switchToWorkspace(int $workspaceId): void
    {
        $GLOBALS['BE_USER']->setWorkspace($workspaceId);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect($workspaceId));
    }

    /**
     * Entries that are not deleted. With a workspace id, only rows of that
     * workspace; without one, all of them.
     *
     * @return list<array<string, mixed>>
     */
    private function entries(?int $workspaceId = null): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('*')->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('deleted', 0))
            ->orderBy('uid');
        if ($workspaceId !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq(
                't3ver_wsid',
                $queryBuilder->createNamedParameter($workspaceId, Connection::PARAM_INT),
            ));
        }

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertLinkedTo(int $pageUid, array $row): void
    {
        self::assertSame($pageUid, (int)$row['parent_uid'], 'parent_uid of entry ' . $row['uid']);
        self::assertSame('pages', $row['parent_table']);
        self::assertSame(self::FIELD, $row['parent_field']);
    }
}
