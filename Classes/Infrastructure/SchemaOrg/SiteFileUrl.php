<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Absolute URL of a file (image, thumbnail) for a schema document.
 *
 * The generators receive the site LANGUAGE base (`https://example.org/en`),
 * which is right for page URLs and wrong for files: fileadmin and
 * typo3temp are not language-scoped. Joined with the language base, a
 * thumbnail became `/en/fileadmin/...`, which the site answers with a 301.
 * Google does not follow that redirect for video thumbnails and reported the
 * episode videos as "thumbnail not reachable".
 *
 * Relative paths are therefore resolved against the site ORIGIN (scheme,
 * host, port). Absolute http(s) URLs pass through unchanged.
 */
final class SiteFileUrl
{
    public static function resolve(string $url, string $siteBase): string
    {
        $url = trim($url);
        if ('' === $url || 1 === preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        $origin = self::origin($siteBase);
        if ('' === $origin) {
            return $url;
        }

        return $origin . '/' . ltrim($url, '/');
    }

    /** `https://example.org/en/` → `https://example.org`; '' when there is no host. */
    public static function origin(string $siteBase): string
    {
        $parts = parse_url(trim($siteBase));
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }

        return ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }
}
