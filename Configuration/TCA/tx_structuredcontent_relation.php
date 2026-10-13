<?php

declare(strict_types=1);

use Koh\StructuredContent\Domain\Enum\RelationType;

defined('TYPO3') or die();

$relationTypeItems = [];
foreach (RelationType::cases() as $type) {
    $relationTypeItems[] = ['label' => $type->label(), 'value' => $type->value];
}

return [
    'ctrl' => [
        'title' => 'Content Relation',
        'label' => 'source_table',
        'label_alt' => 'source_uid,target_table,target_uid',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'iconfile' => 'EXT:structured_content/Resources/Public/Icons/relation.svg',
        'searchFields' => 'source_table,target_table,relation_type',
    ],
    'types' => [
        '0' => [
            'showitem' => 'source_table, source_uid, target_table, target_uid, relation_type, direction, confidence, created_by, description',
        ],
    ],
    'columns' => [
        'source_table' => [
            'label' => 'Source Table',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'required' => true,
            ],
        ],
        'source_uid' => [
            'label' => 'Source UID',
            'config' => [
                'type' => 'number',
                'required' => true,
            ],
        ],
        'target_table' => [
            'label' => 'Target Table',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 255,
                'required' => true,
            ],
        ],
        'target_uid' => [
            'label' => 'Target UID',
            'config' => [
                'type' => 'number',
                'required' => true,
            ],
        ],
        'relation_type' => [
            'label' => 'Relation Type',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $relationTypeItems,
                'required' => true,
            ],
        ],
        'direction' => [
            'label' => 'Direction',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    ['label' => 'Unidirectional', 'value' => 'unidirectional'],
                    ['label' => 'Bidirectional', 'value' => 'bidirectional'],
                ],
                'default' => 'unidirectional',
            ],
        ],
        'confidence' => [
            'label' => 'Confidence',
            'config' => [
                'type' => 'number',
                'format' => 'decimal',
                'default' => 1.0,
                'range' => [
                    'lower' => 0.0,
                    'upper' => 1.0,
                ],
            ],
        ],
        'created_by' => [
            'label' => 'Created By',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'max' => 100,
                'default' => 'manual',
            ],
        ],
        'description' => [
            'label' => 'Description',
            'config' => [
                'type' => 'text',
                'rows' => 3,
                'cols' => 40,
            ],
        ],
    ],
];
