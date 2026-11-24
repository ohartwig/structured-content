<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\SiteFileUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Files resolve against the site base, never against the language base:
 * `/en/fileadmin/...` is a redirect, and Google does not follow it for video
 * thumbnails.
 */
final class SiteFileUrlTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function bases(): iterable
    {
        yield 'site base wins over the language base' => [
            ['_siteRootBase' => 'https://example.com/', '_siteBase' => 'https://example.com/en/'],
            'https://example.com/',
        ];
        yield 'without the site base: origin of the language base' => [
            ['_siteBase' => 'https://example.com/en/'],
            'https://example.com/',
        ];
        yield 'site base without a host: origin of the language base' => [
            ['_siteRootBase' => '/', '_siteBase' => 'https://example.com:8443/en/'],
            'https://example.com:8443/',
        ];
        yield 'nothing usable' => [['_siteBase' => '/en/'], ''];
    }

    /** @param array<string, mixed> $pageData */
    #[Test]
    #[DataProvider('bases')]
    public function theFileBaseIsTheSiteBase(array $pageData, string $expected): void
    {
        self::assertSame($expected, SiteFileUrl::baseFromPageData($pageData));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function urls(): iterable
    {
        yield 'relative path' => ['fileadmin/a.png', 'https://example.com/', 'https://example.com/fileadmin/a.png'];
        yield 'root-relative path' => ['/fileadmin/a.png', 'https://example.com/', 'https://example.com/fileadmin/a.png'];
        yield 'relative path under a site in a subdirectory' => ['fileadmin/a.png', 'https://example.com/site/', 'https://example.com/site/fileadmin/a.png'];
        yield 'root-relative path is not prefixed again' => ['/site/fileadmin/a.png', 'https://example.com/site/', 'https://example.com/site/fileadmin/a.png'];
        yield 'absolute URL passes through' => ['https://cdn.example.net/a.png', 'https://example.com/', 'https://cdn.example.net/a.png'];
        yield 'protocol-relative URL gets https' => ['//cdn.example.net/a.png', 'https://example.com/', 'https://cdn.example.net/a.png'];
        yield 'no base leaves the path' => ['fileadmin/a.png', '', 'fileadmin/a.png'];
        yield 'empty stays empty' => ['', 'https://example.com/', ''];
    }

    #[Test]
    #[DataProvider('urls')]
    public function resolvesFilesAgainstTheBase(string $url, string $fileBase, string $expected): void
    {
        self::assertSame($expected, SiteFileUrl::resolve($url, $fileBase));
    }
}
