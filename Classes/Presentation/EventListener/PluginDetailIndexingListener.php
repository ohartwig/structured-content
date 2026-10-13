<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Frontend\Event\AfterPageAndLanguageIsResolvedEvent;

/**
 * Makes plugin-detail URLs indexable although their container page is set
 * to `no_index = 1`.
 *
 * Plugin-detail URLs like `/autor/<slug>` or
 * `/podcasts/<series>/episode/<slug>` share the pageUid of the container page
 * that hosts the Extbase plugin, and with it the container's page record. The
 * container itself is empty and correctly carries `no_index = 1`; the detail
 * URLs hold the actual content and must be indexable.
 *
 * `no_index` is read by more than the robots meta tag: EXT:seo's
 * CanonicalGenerator and HrefLangGenerator both bail out on it. Rewriting the
 * robots meta in the rendered HTML (the former RobotsMetaOverrideMiddleware)
 * therefore left detail pages without canonical and hreflang. Clearing the
 * flag on the request's page record before rendering lets core emit robots
 * `index`, canonical and hreflang itself.
 *
 * Only the request-scoped PageInformation is touched, never the database row.
 * Page-cache correctness is unaffected: detail URLs carry their plugin
 * arguments in the page cache identifier, so the container page without
 * arguments keeps its own `noindex` cache entry.
 */
#[AsEventListener('koh/structured-content/plugin-detail-indexing')]
final readonly class PluginDetailIndexingListener
{
    /**
     * Map of `routeArguments` group name → argument key whose non-empty value
     * marks a plugin-detail route. New plugin-detail patterns extend the list
     * here (keep in sync with WebPageSchemaGenerator::PLUGIN_DETAIL_ROUTE_GROUPS).
     */
    private const array PLUGIN_DETAIL_ARGUMENT_GROUPS = [
        'tx_contentblockscollection_person' => 'person',
        'tx_contentblockscollection_podcast' => 'episode',
    ];

    public function __invoke(AfterPageAndLanguageIsResolvedEvent $event): void
    {
        $pageArguments = $event->getRequest()->getAttribute('routing');
        if (!$pageArguments instanceof PageArguments) {
            return;
        }

        if (!self::isPluginDetailRoute($pageArguments->getRouteArguments())) {
            return;
        }

        $pageInformation = $event->getPageInformation();
        $pageRecord = $pageInformation->getPageRecord();
        if (1 !== (int) ($pageRecord['no_index'] ?? 0)) {
            return;
        }

        $pageRecord['no_index'] = 0;
        $pageInformation->setPageRecord($pageRecord);
    }

    /**
     * @param array<string, mixed> $routeArguments
     */
    private static function isPluginDetailRoute(array $routeArguments): bool
    {
        foreach (self::PLUGIN_DETAIL_ARGUMENT_GROUPS as $group => $argument) {
            $args = $routeArguments[$group] ?? null;
            if (!is_array($args)) {
                continue;
            }
            if ('' !== trim((string) ($args[$argument] ?? ''))) {
                return true;
            }
        }

        return false;
    }
}
