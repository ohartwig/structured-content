<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use TYPO3\CMS\Core\Http\Uri;

/**
 * Absolute URL of a file (image, thumbnail) for a schema document.
 *
 * Files are not language-scoped. The generators get the LANGUAGE base as
 * `_siteBase` (`https://example.org/en/`), which is right for page URLs and
 * wrong for files: joined with it, a thumbnail became `/en/fileadmin/...`,
 * which the site answers with a 301. Google does not follow that redirect for
 * video thumbnails and reported the episode videos as "thumbnail not
 * reachable".
 *
 * Files resolve against the SITE base instead (`Site::getBase()`, passed as
 * `_siteRootBase` by the middleware), the same configured base the page URLs
 * come from, so the result does not depend on the request host.
 */
final class SiteFileUrl
{
    /**
     * The base files resolve against: `_siteRootBase`, or — when a caller
     * does not provide it, or the site base has no host — the origin of the
     * language base.
     *
     * @param array<string, mixed> $pageData
     */
    public static function baseFromPageData(array $pageData): string
    {
        $root = $pageData['_siteRootBase'] ?? '';
        if (is_string($root) && '' !== (new Uri($root))->getHost()) {
            return $root;
        }

        $language = $pageData['_siteBase'] ?? '';
        if (!is_string($language) || '' === $language) {
            return '';
        }
        $uri = new Uri($language);

        return '' === $uri->getHost() ? '' : (string) $uri->withPath('/')->withQuery('')->withFragment('');
    }

    /**
     * Resolves `$url` against `$fileBase` (from baseFromPageData()). An
     * absolute http(s) URL passes through; a protocol-relative one gets https.
     * A root-relative path (`/fileadmin/...`, what FAL's getPublicUrl()
     * returns) replaces the base path; a relative one is appended to it.
     */
    public static function resolve(string $url, string $fileBase): string
    {
        $url = trim($url);
        if ('' === $url || 1 === preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        $base = new Uri($fileBase);
        if ('' === $base->getHost()) {
            return $url;
        }
        $path = str_starts_with($url, '/') ? $url : rtrim($base->getPath(), '/') . '/' . $url;

        return (string) $base->withPath($path)->withQuery('')->withFragment('');
    }
}
