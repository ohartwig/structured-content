<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;

defined('TYPO3') or die();

// The database backend is shared by every replica and supports tags. The
// file backend this used to be lived in each pod: a flush reached only the
// pod that handled it, and its flushByTag() did nothing, so a changed page
// kept serving its old JSON-LD for up to a day on the other pods. An
// installation can still point the cache at Redis/Valkey in its own config.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['structured_content_schema'] ??= [
    'backend' => Typo3DatabaseBackend::class,
    'options' => [
        'defaultLifetime' => 86400,
    ],
    'groups' => ['pages'],
];

// DataHandlerHook: feuert ContextChangedEvent bei ai_context_*-Aenderungen
// auf pages/tt_content + invalidiert den Page-/Schema-Cache wenn ein
// Schema-Sub-Record (tx_structuredcontent_about_entry) gespeichert wird.
// Ohne diese Registration wird die Hook-Class nie aufgerufen — Editor-
// about-Subjects erscheinen erst nach 24h-TTL-Ablauf des Caches.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['structured_content']
    = \Koh\StructuredContent\Infrastructure\Hooks\DataHandlerHook::class;
