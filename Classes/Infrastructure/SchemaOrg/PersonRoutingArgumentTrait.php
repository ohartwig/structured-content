<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Resolves the active moselwal/person slug from the routing arguments
 * the Extbase routing-enhancer attaches to the page data array. Mirrors
 * the pattern in PodcastFeedCacheReaderTrait so a future detail-page
 * generator only needs to use this trait.
 */
trait PersonRoutingArgumentTrait
{
    /**
     * @param array<string, mixed> $pageData
     */
    private function resolvePersonSlug(array $pageData): string
    {
        $routingArguments = $pageData['_routingArguments'] ?? [];
        if (!is_array($routingArguments)) {
            return '';
        }
        $personArgs = $routingArguments['tx_contentblockscollection_person'] ?? null;
        if (!is_array($personArgs)) {
            return '';
        }
        $slug = (string) ($personArgs['person'] ?? '');

        return trim($slug);
    }
}
