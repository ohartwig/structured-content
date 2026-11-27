<?php

declare(strict_types=1);

/*
 * The author field, which structured_content only registers when
 * content_blocks_collection is installed — this fixture stands in for it.
 */
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_structuredcontent_author_records' => [
        'label' => 'Authors',
        'config' => [
            'type' => 'group',
            'allowed' => 'moselwal_person',
            'maxitems' => 10,
        ],
    ],
]);
