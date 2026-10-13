<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;

defined('TYPO3') or die();

$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['structured_content_schema'] ??= [
    'backend' => SimpleFileBackend::class,
    'options' => [
        'defaultLifetime' => 86400,
    ],
    'groups' => ['pages'],
];

// DataHandlerHook: feuert ContextChangedEvent bei ai_context_*-Aenderungen
// auf pages/tt_content + invalidiert den Page-/Schema-Cache wenn ein
// Schema-Sub-Record (tx_structuredcontent_about_entry) gespeichert wird.
// Ohne diese Registration wird die Hook-Class nie aufgerufen — Editor-
// about-Subjects erscheinen erst nach 24h-TTL-Ablauf des File-Caches.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['structured_content']
    = \Koh\StructuredContent\Infrastructure\Hooks\DataHandlerHook::class;
