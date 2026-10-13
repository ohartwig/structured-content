<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Presentation\Middleware;

use Koh\StructuredContent\Application\Service\LlmsTxtGeneratorService;
use Koh\StructuredContent\Domain\Contract\PageTreeRepositoryInterface;
use Koh\StructuredContent\Presentation\Factory\SiteContextFactory;
use Koh\StructuredContent\Presentation\Middleware\LlmsTxtMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * llms.txt and llms-full.md are served under each language's base path.
 * Until 2026-10-01 only the bare /llms.txt matched, so /en/llms.txt was a 404
 * and the English settings were never used.
 */
final class LlmsTxtMiddlewareTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function paths(): iterable
    {
        yield 'default language, llms.txt' => ['https://example.com/', '/llms.txt', 'llms.txt'];
        yield 'default language, llms-full.md' => ['https://example.com/', '/llms-full.md', 'llms-full.md'];
        yield 'english base, llms.txt' => ['https://example.com/en/', '/en/llms.txt', 'llms.txt'];
        yield 'english base, llms-full.md' => ['https://example.com/en/', '/en/llms-full.md', 'llms-full.md'];
        yield 'base without trailing slash' => ['https://example.com/en', '/en/llms.txt', 'llms.txt'];
        yield 'own domain per language' => ['https://example.org/', '/llms.txt', 'llms.txt'];
        yield 'english base, bare path is not english' => ['https://example.com/en/', '/llms.txt', null];
        yield 'another file under the base' => ['https://example.com/en/', '/en/robots.txt', null];
        yield 'a page whose slug ends in llms.txt' => ['https://example.com/', '/blog/llms.txt', null];
    }

    #[Test]
    #[DataProvider('paths')]
    public function requestedFileFollowsTheLanguageBase(string $base, string $path, ?string $expected): void
    {
        $language = new SiteLanguage(1, 'en_GB.UTF-8', new Uri($base), []);

        self::assertSame($expected, LlmsTxtMiddleware::requestedFile($path, $language));
    }

    private bool $handlerCalled = false;

    private function middleware(): LlmsTxtMiddleware
    {
        // An empty page tree: the file still has its heading, so a 200 with
        // a body proves the middleware answered rather than passed on.
        return new LlmsTxtMiddleware(
            new LlmsTxtGeneratorService(self::createStub(PageTreeRepositoryInterface::class)),
            new SiteContextFactory(),
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = self::createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function (): ResponseInterface {
            $this->handlerCalled = true;

            return new HtmlResponse('<html>the page pipeline</html>');
        });

        return $handler;
    }

    private function site(bool $enabled = true): Site
    {
        return new Site('example', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/', 'websiteTitle' => 'Beispiel'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/en/', 'websiteTitle' => 'Example'],
            ],
            'settings' => ['structuredContent' => ['enabled' => $enabled, 'llmsTxt' => ['enabled' => $enabled]]],
        ]);
    }

    private function request(string $path, ?Site $site, ?int $languageId): ServerRequest
    {
        $request = new ServerRequest('https://example.com' . $path, 'GET');
        if (null !== $site) {
            $request = $request->withAttribute('site', $site);
            if (null !== $languageId) {
                $request = $request->withAttribute('language', $site->getLanguageById($languageId));
            }
        }

        return $request;
    }

    #[Test]
    public function englishLlmsTxtIsServedUnderTheEnglishBase(): void
    {
        $response = $this->middleware()->process($this->request('/en/llms.txt', $this->site(), 1), $this->handler());

        self::assertFalse($this->handlerCalled);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame('noindex', $response->getHeaderLine('X-Robots-Tag'));
        self::assertStringStartsWith('# ', (string) $response->getBody());
    }

    #[Test]
    public function withoutAResolvedLanguageTheDefaultLanguageAnswers(): void
    {
        $response = $this->middleware()->process($this->request('/llms.txt', $this->site(), null), $this->handler());

        self::assertFalse($this->handlerCalled);
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function englishFullDumpIsMarkdownUnderTheEnglishBase(): void
    {
        $response = $this->middleware()->process($this->request('/en/llms-full.md', $this->site(), 1), $this->handler());

        self::assertFalse($this->handlerCalled);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function anyOtherPathIsLeftToThePagePipeline(): void
    {
        $this->middleware()->process($this->request('/en/robots.txt', $this->site(), 1), $this->handler());

        self::assertTrue($this->handlerCalled);
    }

    #[Test]
    public function withoutASiteNothingIsGenerated(): void
    {
        $this->middleware()->process($this->request('/llms.txt', null, null), $this->handler());

        self::assertTrue($this->handlerCalled);
    }

    #[Test]
    public function aSiteThatSwitchedLlmsTxtOffGetsNoFile(): void
    {
        $this->middleware()->process($this->request('/en/llms.txt', $this->site(false), 1), $this->handler());

        self::assertTrue($this->handlerCalled);
    }
}
