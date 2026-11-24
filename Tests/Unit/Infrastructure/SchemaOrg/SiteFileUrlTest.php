<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\SiteFileUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Files are resolved against the site origin, never against the language
 * base: `/en/fileadmin/...` is a redirect, and Google does not follow it for
 * video thumbnails.
 */
final class SiteFileUrlTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function cases(): iterable
    {
        yield 'relative path on a language base' => ['fileadmin/a.png', 'https://example.com/en/', 'https://example.com/fileadmin/a.png'];
        yield 'root-relative path on a language base' => ['/fileadmin/a.png', 'https://example.com/en', 'https://example.com/fileadmin/a.png'];
        yield 'default language base' => ['fileadmin/a.png', 'https://example.com/', 'https://example.com/fileadmin/a.png'];
        yield 'port is kept' => ['typo3temp/a.png', 'https://example.com:8443/de/', 'https://example.com:8443/typo3temp/a.png'];
        yield 'absolute URL passes through' => ['https://cdn.example.net/a.png', 'https://example.com/en/', 'https://cdn.example.net/a.png'];
        yield 'protocol-relative URL gets https' => ['//cdn.example.net/a.png', 'https://example.com/en/', 'https://cdn.example.net/a.png'];
        yield 'no host in the base leaves the path' => ['fileadmin/a.png', '/', 'fileadmin/a.png'];
        yield 'empty stays empty' => ['', 'https://example.com/en/', ''];
    }

    #[Test]
    #[DataProvider('cases')]
    public function resolvesFilesAgainstTheSiteOrigin(string $url, string $siteBase, string $expected): void
    {
        self::assertSame($expected, SiteFileUrl::resolve($url, $siteBase));
    }
}
