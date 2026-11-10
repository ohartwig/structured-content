<?php

declare(strict_types=1);

/*
 * The parts of the moselwal_card table (Content Blocks collection from
 * moselwal/content-blocks-collection) that the schema generators read:
 * language handling, sorting, enable fields and the inline relation columns.
 */
return [
    'ctrl' => [
        'title' => 'Card',
        'label' => 'moselwal_card_header',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'translationSource' => 'l10n_source',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'hideTable' => true,
    ],
    'columns' => [
        'foreign_table_parent_uid' => ['config' => ['type' => 'passthrough']],
        'tablenames' => ['config' => ['type' => 'passthrough']],
        'fieldname' => ['config' => ['type' => 'passthrough']],
        'moselwal_card_header' => ['label' => 'Header', 'config' => ['type' => 'input']],
        'moselwal_card_bodytext' => ['label' => 'Text', 'config' => ['type' => 'text']],
        'moselwal_card_cta_link' => ['label' => 'Link', 'config' => ['type' => 'input']],
        'moselwal_card_cta_label' => ['label' => 'Link label', 'config' => ['type' => 'input']],
        'moselwal_card_bodytext2' => ['label' => 'Text 2', 'config' => ['type' => 'text']],
        'moselwal_card_works_for' => ['label' => 'Works for', 'config' => ['type' => 'input']],
        'moselwal_card_rating_value' => ['label' => 'Rating', 'config' => ['type' => 'number', 'format' => 'decimal']],
        'moselwal_card_review_count' => ['label' => 'Review count', 'config' => ['type' => 'number']],
    ],
    'types' => [
        '0' => ['showitem' => 'moselwal_card_header, moselwal_card_bodytext'],
    ],
];
