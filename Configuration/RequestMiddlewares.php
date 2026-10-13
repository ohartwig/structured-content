<?php

declare(strict_types=1);

return [
    'frontend' => [
        'koh/structured-content-llms-txt' => [
            'target' => \Koh\StructuredContent\Presentation\Middleware\LlmsTxtMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
            'after' => [
                'typo3/cms-frontend/site',
            ],
        ],
        'koh/structured-content-jsonld-injector' => [
            'target' => \Koh\StructuredContent\Presentation\Middleware\JsonLdInjectorMiddleware::class,
            'before' => [
                'typo3/cms-frontend/content-length-headers',
            ],
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
        // Runs after prepare-tsfe-rendering so frontend.page.information is
        // populated, but before content-length-headers — which means
        // short-circuiting (returning a markdown response without calling
        // $handler) skips the entire downstream HTML rendering chain.
        'koh/structured-content-markdown-negotiation' => [
            'target' => \Koh\StructuredContent\Presentation\Middleware\MarkdownContentNegotiationMiddleware::class,
            'before' => [
                'typo3/cms-frontend/content-length-headers',
            ],
            'after' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
