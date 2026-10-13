<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Hooks;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Application\Event\ContextChangedEvent;
use Koh\StructuredContent\Application\Service\ContextResolver;
use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DataHandlerHook
{
    private const CONTEXT_FIELDS = [
        'ai_context_audience',
        'ai_context_tone',
        'ai_context_channels',
    ];

    /**
     * Sub-Record-Tabellen, deren Inserts/Updates die JSON-LD-Generierung
     * der zugehoerigen Parent-Page beeinflussen. Beim Save muss der
     * structured_content_schema-Cache + der Page-Cache der Parent-Page
     * invalidiert werden — sonst wird das alte JSON-LD aus dem File-Cache
     * (24h TTL) geliefert obwohl Editor neue about-Subjects gepflegt hat.
     */
    private const SCHEMA_SUBRECORD_TABLES = [
        'tx_structuredcontent_about_entry',
    ];

    // TYPO3 instanziert DataHandler-Hooks via GeneralUtility::makeInstance()
    // OHNE Constructor-Args. Pflicht-Args fuehrten zu ArgumentCountError
    // bei jedem Datamap-Operation (Editor-Save, MCP-BulkUpdate, …) →
    // Backend kaputt. Pattern: leerer Constructor + Lazy-Resolve in
    // Getter-Methoden via GeneralUtility::makeInstance().
    private ?ContextResolverInterface $contextResolver = null;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private ?CacheManager $cacheManager = null;
    private ?ConnectionPool $connectionPool = null;

    public function __construct() {}

    private function getContextResolver(): ContextResolverInterface
    {
        return $this->contextResolver ??= GeneralUtility::makeInstance(ContextResolver::class);
    }

    private function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher ??= GeneralUtility::makeInstance(EventDispatcherInterface::class);
    }

    private function getCacheManager(): CacheManager
    {
        return $this->cacheManager ??= GeneralUtility::makeInstance(CacheManager::class);
    }

    private function getConnectionPool(): ConnectionPool
    {
        return $this->connectionPool ??= GeneralUtility::makeInstance(ConnectionPool::class);
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(
        string $status,
        string $table,
        string|int $id,
        array $fieldArray,
        DataHandler $dataHandler,
    ): void {
        $uid = is_string($id) && str_starts_with($id, 'NEW')
            ? $dataHandler->substNEWwithIDs[$id] ?? 0
            : (int) $id;

        // Schema-Subrecord-Save (tx_structuredcontent_about_entry, …)
        // invalidiert immer den structured_content_schema-Cache der
        // Parent-Page + den pages-Cache → JSON-LD wird beim naechsten
        // Request neu gerendert mit den frischen Subjects. Ohne diesen
        // Hook persistiert das alte JSON-LD bis zum 24h-TTL-Ablauf,
        // selbst wenn Editor schon neue about-Entries gepflegt hat.
        if (in_array($table, self::SCHEMA_SUBRECORD_TABLES, true) && $uid > 0) {
            $this->flushSchemaCacheForSubrecord($table, $uid, $fieldArray);
        }

        if (!$this->hasContextFieldChanges(fieldArray: $fieldArray)) {
            return;
        }

        if (!in_array($table, ['pages', 'tt_content'], true)) {
            return;
        }

        if (0 === $uid) {
            return;
        }

        $oldRecord = $this->buildOldRecord(fieldArray: $fieldArray, dataHandler: $dataHandler, table: $table, uid: $uid);
        $newRecord = array_merge($oldRecord, $fieldArray);

        $resolver = $this->getContextResolver();
        $oldContext = $resolver->resolve(table: $table, record: $oldRecord);
        $newContext = $resolver->resolve(table: $table, record: $newRecord);

        $this->getEventDispatcher()->dispatch(new ContextChangedEvent(
            table: $table,
            uid: $uid,
            oldContext: $oldContext,
            newContext: $newContext,
        ));
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    private function hasContextFieldChanges(array $fieldArray): bool
    {
        foreach (self::CONTEXT_FIELDS as $field) {
            if (array_key_exists($field, $fieldArray)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $fieldArray
     *
     * @return array<string, mixed>
     */
    private function buildOldRecord(array $fieldArray, DataHandler $dataHandler, string $table, int $uid): array
    {
        $record = [];

        foreach (self::CONTEXT_FIELDS as $field) {
            $record[$field] = '';
        }

        $record['uid'] = $uid;
        $record['pid'] = 0;

        return $record;
    }

    /**
     * Flusht den Parent-Page-Cache (Page + structured_content_schema-File-
     * Cache) wenn ein Schema-Subrecord gespeichert wurde. Parent-UID wird
     * entweder aus dem fieldArray gelesen (neue Records) oder aus dem
     * DB-Record nachgeladen (Updates ohne parent_uid im Feldarray).
     *
     * @param array<string, mixed> $fieldArray
     */
    private function flushSchemaCacheForSubrecord(string $table, int $uid, array $fieldArray): void
    {
        $parentUid = (int) ($fieldArray['parent_uid'] ?? 0);
        if (0 === $parentUid) {
            // Update ohne parent_uid im Save-Payload — Record neu laden.
            $qb = $this->getConnectionPool()->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $parentUid = (int) $qb->select('parent_uid')
                ->from($table)
                ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, ParameterType::INTEGER)))
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchOne();
        }
        if (0 === $parentUid) {
            return;
        }

        // Cache-Tag-basierter Flush — TYPO3 setzt beim Page-Render den
        // pageId_<uid>-Tag auf alle gecachten Eintraege der Page.
        $cacheManager = $this->getCacheManager();
        try {
            $cacheManager->getCache('pages')->flushByTag('pageId_' . $parentUid);
        } catch (\Throwable) {
            // Cache nicht verfuegbar — silent skip.
        }
        try {
            $cacheManager->getCache('structured_content_schema')->flushByTag('pageId_' . $parentUid);
        } catch (\Throwable) {
            // Cache nicht verfuegbar — silent skip.
        }
    }
}
