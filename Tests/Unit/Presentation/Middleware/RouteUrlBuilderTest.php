<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Presentation\Middleware;

use Koh\StructuredContent\Presentation\Middleware\RouteUrlBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\RouterInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The URL of a plugin detail page in the rendered language comes from the
 * site router, with the route arguments and the language.
 */
final class RouteUrlBuilderTest extends TestCase
{
    /** @var list<array{mixed, array<string, mixed>}> */
    private array $generated = [];

    private const ARGUMENTS = ['tx_contentblockscollection_person' => ['person' => 'ada-lovelace']];

    private function site(?\Throwable $failure = null): Site
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generateUri')->willReturnCallback(function (mixed $route, array $parameters = []) use ($failure): Uri {
            $this->generated[] = [$route, $parameters];
            if (null !== $failure) {
                throw $failure;
            }

            return new Uri('https://example.com/en/author/ada-lovelace');
        });
        $site = $this->createStub(Site::class);
        $site->method('getRouter')->willReturn($router);

        return $site;
    }

    private function english(): SiteLanguage
    {
        return new SiteLanguage(1, 'en_GB.UTF-8', new Uri('https://example.com/en/'), []);
    }

    #[Test]
    public function theRouterBuildsThePageWithItsArgumentsInTheLanguage(): void
    {
        $language = $this->english();

        $url = (new RouteUrlBuilder())->build($this->site(), $language, 2, self::ARGUMENTS);

        self::assertSame('https://example.com/en/author/ada-lovelace', $url);
        self::assertSame([[2, [...self::ARGUMENTS, '_language' => $language]]], $this->generated);
    }

    #[Test]
    public function withoutALanguageTheRouterPicksTheDefault(): void
    {
        (new RouteUrlBuilder())->build($this->site(), null, 2, self::ARGUMENTS);

        self::assertSame([[2, self::ARGUMENTS]], $this->generated);
    }

    #[Test]
    public function aPageWithoutRouteArgumentsOrWithoutUidIsNotRouted(): void
    {
        $builder = new RouteUrlBuilder();

        self::assertSame('', $builder->build($this->site(), $this->english(), 2, []));
        self::assertSame('', $builder->build($this->site(), $this->english(), 0, self::ARGUMENTS));
        self::assertSame('', $builder->build($this->site(), $this->english(), -1, self::ARGUMENTS));
        self::assertSame([], $this->generated);
    }

    #[Test]
    public function aRouterFailureYieldsNoUrl(): void
    {
        self::assertSame('', (new RouteUrlBuilder())->build($this->site(new \RuntimeException('no route')), $this->english(), 2, self::ARGUMENTS));
    }

    #[Test]
    public function theFirstPageIsRouted(): void
    {
        self::assertSame('https://example.com/en/author/ada-lovelace', (new RouteUrlBuilder())->build($this->site(), null, 1, self::ARGUMENTS));
    }
}
