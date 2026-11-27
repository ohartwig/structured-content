<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\Middleware;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The URL of a page with its route arguments in a given language, as the
 * site router builds it.
 *
 * Plugin detail URLs (an author page, a podcast episode) are the page's URL
 * plus route arguments. The generators have no router and used to put the
 * URL together from fixed path segments below the language base, which gave
 * `/en/autor/<slug>` where the English author page is `/en/author/<slug>`.
 * The router applies the translated page slug and the route enhancer's path.
 */
final class RouteUrlBuilder
{
    /**
     * '' for a page without route arguments — its URL is the page slug — and
     * whenever the router cannot build one.
     *
     * @param array<string, mixed> $routeArguments
     */
    public function build(Site $site, ?SiteLanguage $language, int $pageUid, array $routeArguments): string
    {
        if ($pageUid <= 0 || [] === $routeArguments) {
            return '';
        }

        $parameters = $routeArguments;
        if (null !== $language) {
            $parameters['_language'] = $language;
        }

        try {
            return (string) $site->getRouter()->generateUri($pageUid, $parameters);
        } catch (\Throwable) {
            // Structured data is an addition to the page: without a URL the
            // generators fall back to their own.
            return '';
        }
    }
}
