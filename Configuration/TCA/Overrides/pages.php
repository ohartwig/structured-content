<?php

declare(strict_types=1);

use Koh\StructuredContent\Infrastructure\TCA\ContextFields;

defined('TYPO3') or die();

ContextFields::addToTable('pages');

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_structuredcontent_schema_type' => [
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type',
        'config' => [
            'type' => 'select',
            'renderType' => 'selectSingle',
            'items' => [
                [
                    'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.auto',
                    'value' => '',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.auto.description',
                ],
                ['label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.group.article', 'value' => '--div--'],
                [
                    'label' => 'Article',
                    'value' => 'Article',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.Article.description',
                ],
                [
                    'label' => 'BlogPosting',
                    'value' => 'BlogPosting',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.BlogPosting.description',
                ],
                [
                    'label' => 'NewsArticle',
                    'value' => 'NewsArticle',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.NewsArticle.description',
                ],
                [
                    'label' => 'TechArticle',
                    'value' => 'TechArticle',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.TechArticle.description',
                ],
                [
                    'label' => 'ScholarlyArticle',
                    'value' => 'ScholarlyArticle',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.ScholarlyArticle.description',
                ],
                [
                    'label' => 'Report',
                    'value' => 'Report',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.Report.description',
                ],
                [
                    'label' => 'SocialMediaPosting',
                    'value' => 'SocialMediaPosting',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.SocialMediaPosting.description',
                ],
                ['label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.group.webpage', 'value' => '--div--'],
                [
                    'label' => 'WebPage',
                    'value' => 'WebPage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.WebPage.description',
                ],
                [
                    'label' => 'AboutPage',
                    'value' => 'AboutPage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.AboutPage.description',
                ],
                [
                    'label' => 'ContactPage',
                    'value' => 'ContactPage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.ContactPage.description',
                ],
                [
                    'label' => 'CollectionPage',
                    'value' => 'CollectionPage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.CollectionPage.description',
                ],
                [
                    'label' => 'ItemPage',
                    'value' => 'ItemPage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.ItemPage.description',
                ],
                [
                    'label' => 'ProfilePage',
                    'value' => 'ProfilePage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.ProfilePage.description',
                ],
                [
                    'label' => 'QAPage',
                    'value' => 'QAPage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.QAPage.description',
                ],
                ['label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.group.software', 'value' => '--div--'],
                [
                    'label' => 'SoftwareApplication',
                    'value' => 'SoftwareApplication',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.SoftwareApplication.description',
                ],
                [
                    'label' => 'SoftwareSourceCode',
                    'value' => 'SoftwareSourceCode',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.SoftwareSourceCode.description',
                ],
                ['label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.group.other', 'value' => '--div--'],
                [
                    'label' => 'FAQPage',
                    'value' => 'FAQPage',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.FAQPage.description',
                ],
                [
                    'label' => 'Product',
                    'value' => 'Product',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.Product.description',
                ],
                [
                    'label' => 'HowTo',
                    'value' => 'HowTo',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.HowTo.description',
                ],
                [
                    'label' => 'Organization',
                    'value' => 'Organization',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.Organization.description',
                ],
                [
                    'label' => 'LocalBusiness',
                    'value' => 'LocalBusiness',
                    'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.schema_type.LocalBusiness.description',
                ],
            ],
            'default' => '',
        ],
    ],
]);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_structuredcontent_service_type' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.service_type',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.service_type.description',
        'displayCond' => 'FIELD:tx_structuredcontent_schema_type:IN:Service,Product,HowTo',
        'config' => [
            'type' => 'input',
            'size' => 40,
            'eval' => 'trim',
        ],
    ],
    'tx_structuredcontent_service_category' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.service_category',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.service_category.description',
        'displayCond' => 'FIELD:tx_structuredcontent_schema_type:IN:Service,Product,HowTo',
        'config' => [
            'type' => 'input',
            'size' => 40,
            'eval' => 'trim',
        ],
    ],
    'tx_structuredcontent_code_repository' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.code_repository',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.code_repository.description',
        'config' => [
            'type' => 'input',
            'size' => 60,
            'eval' => 'trim',
            'valuePicker' => [
                'items' => [],
            ],
        ],
    ],
    'tx_structuredcontent_programming_language' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.programming_language',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.programming_language.description',
        'displayCond' => 'FIELD:tx_structuredcontent_code_repository:REQ:true',
        'config' => [
            'type' => 'input',
            'size' => 40,
            'eval' => 'trim',
        ],
    ],
    'tx_structuredcontent_software_version' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.software_version',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.software_version.description',
        'displayCond' => 'FIELD:tx_structuredcontent_code_repository:REQ:true',
        'config' => [
            'type' => 'input',
            'size' => 20,
            'eval' => 'trim',
        ],
    ],
    'tx_structuredcontent_software_license' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.software_license',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.software_license.description',
        'displayCond' => 'FIELD:tx_structuredcontent_code_repository:REQ:true',
        'config' => [
            'type' => 'input',
            'size' => 30,
            'eval' => 'trim',
            'default' => 'MIT',
        ],
    ],
    'tx_structuredcontent_operating_system' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.operating_system',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.operating_system.description',
        'displayCond' => 'FIELD:tx_structuredcontent_code_repository:REQ:true',
        'config' => [
            'type' => 'input',
            'size' => 30,
            'eval' => 'trim',
        ],
    ],
]);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_structuredcontent_same_as' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.same_as',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.same_as.description',
        'config' => [
            'type' => 'text',
            'rows' => 4,
            'cols' => 60,
            // Eine URL pro Zeile — der Generator splittet bei Newline + trim.
            // Beispiele: extensions.typo3.org-Listing, packagist.org-Paketseite,
            // Wikipedia, Wikidata. NIE die eigene Page-URL (dafuer ist `url`).
        ],
    ],
    'tx_structuredcontent_about' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.about',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.about.description',
        'config' => [
            'type' => 'inline',
            'foreign_table' => 'tx_structuredcontent_about_entry',
            'foreign_field' => 'parent_uid',
            'foreign_table_field' => 'parent_table',
            'foreign_match_fields' => [
                'parent_field' => 'tx_structuredcontent_about',
            ],
            'foreign_sortby' => 'sorting',
            'minitems' => 0,
            'maxitems' => 30,
            'appearance' => [
                'collapseAll' => true,
                'enabledControls' => [
                    'sort' => true,
                    'dragdrop' => true,
                ],
                'useSortable' => true,
                'showSynchronizationLink' => false,
                'showAllLocalizationLink' => false,
                'showPossibleLocalizationRecords' => false,
            ],
        ],
    ],
]);

// Editorial selection for the featured section of llms.txt.
// `l10n_mode: exclude`: the flag belongs to the page, not to a language
// version — LlmsTxtGeneratorService reads it from the default-language row,
// and every language features the same pages. In a translation the field is
// therefore shown read-only.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
    'tx_structuredcontent_llms_featured' => [
        'exclude' => true,
        'l10n_mode' => 'exclude',
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.llms_featured',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.llms_featured.description',
        'config' => [
            'type' => 'check',
            'renderType' => 'checkboxToggle',
            'default' => 0,
        ],
    ],
]);

// Das Author-Records-Feld zeigt auf die Tabelle `moselwal_person` — die liefert
// der Content-Blocks-Record-Type aus EXT:content_blocks_collection. Ohne diese
// Extension gibt es kein TCA-Schema fuer die Tabelle, und FormEngine wirft beim
// Aufloesen von `allowed` eine UndefinedSchemaException (#1661540376) — und zwar
// beim Oeffnen JEDER Seiteneigenschaft, nicht nur beim Anfassen des Feldes.
// `isLoaded()` statt `isset($GLOBALS['TCA']['moselwal_person'])`, weil Content
// Blocks sein TCA nicht garantiert vor `Configuration/TCA/Overrides` aufbaut.
if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('content_blocks_collection')) {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('pages', [
        'tx_structuredcontent_author_records' => [
            'exclude' => true,
            'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.author_records',
            'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:pages.author_records.description',
            'config' => [
                'type' => 'group',
                'allowed' => 'moselwal_person',
                'maxitems' => 10,
                'minitems' => 0,
                'fieldControl' => [
                    'editPopup' => ['disabled' => false],
                    'addRecord' => ['disabled' => false],
                ],
            ],
        ],
    ]);
}

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'pages',
    'tx_structuredcontent_schema_type, tx_structuredcontent_service_type, tx_structuredcontent_service_category, tx_structuredcontent_code_repository, tx_structuredcontent_programming_language, tx_structuredcontent_software_version, tx_structuredcontent_software_license, tx_structuredcontent_operating_system, tx_structuredcontent_same_as, tx_structuredcontent_about, tx_structuredcontent_llms_featured',
    '',
    'after:ai_context_channels',
);

// Author-Records-Feld direkt unter dem Core-Author-Textfeld im Metadata-Tab
// platzieren, damit Redaktion die Person-Verknuepfung neben den Legacy-Feldern
// findet (statt im AI-Tab versteckt).
if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('content_blocks_collection')) {
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
        'pages',
        'tx_structuredcontent_author_records',
        '',
        'after:author_email',
    );
}
