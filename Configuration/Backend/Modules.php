<?php

declare(strict_types=1);

use Koh\StructuredContent\Presentation\Controller\RelationshipController;

return [
    'content_structuredcontent' => [
        'parent' => 'web',
        'position' => ['after' => '*'],
        'access' => 'admin',
        'iconIdentifier' => 'structured-content-module',
        'labels' => 'LLL:EXT:structured_content/Resources/Private/Language/locallang_mod.xlf',
        'routes' => [
            '_default' => [
                'target' => RelationshipController::class . '::listAction',
            ],
            'add' => [
                'target' => RelationshipController::class . '::addAction',
            ],
        ],
    ],
];
