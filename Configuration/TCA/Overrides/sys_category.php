<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Add a slug field to sys_category so we can build clean filter URLs like
// /blog?cat=devsecops. TYPO3 core does not ship sys_category.slug, hence this
// override. The structured-content extension owns this column because the
// same value also feeds Schema.org articleSection mapping.
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTCAcolumns('sys_category', [
    'slug' => [
        'exclude' => true,
        'label' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:sys_category.slug',
        'description' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang.xlf:sys_category.slug.description',
        'config' => [
            'type' => 'slug',
            'generatorOptions' => [
                'fields' => ['title'],
                'fieldSeparator' => '-',
                'replacements' => ['/' => '-'],
            ],
            'fallbackCharacter' => '-',
            'eval' => 'unique',
        ],
    ],
]);

\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addToAllTCAtypes(
    'sys_category',
    'slug',
    '',
    'after:title',
);

// Upgrade the existing sys_category.description column from plain text to RTE.
// Editors get a CKEditor for rich category descriptions that render as a hero
// block above category-filtered listings (BlogCategoriesProcessor consumes
// the field and the moselwal-de-site MenuSubpages template renders it).
// No DB-schema migration required — description is already TEXT.
$GLOBALS['TCA']['sys_category']['columns']['description']['config']['enableRichtext'] = true;
$GLOBALS['TCA']['sys_category']['columns']['description']['config']['richtextConfiguration'] = 'default';
