<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Presentation\Middleware;

use Koh\StructuredContent\Application\Service\LlmsTxtGeneratorService;
use Koh\StructuredContent\Domain\Contract\PageTreeRepositoryInterface;
use Koh\StructuredContent\Presentation\Factory\SiteContextFactory;
use Koh\StructuredContent\Presentation\Middleware\MarkdownContentNegotiationMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Markdown statt HTML, wenn ein Agent `Accept: text/markdown` schickt.
 *
 * Die Middleware antwortet, bevor irgendetwas gerendert ist. Sie muss also an
 * der Anfrage erkennen, ob die URL eine gewoehnliche Seite ist. Alles andere,
 * was durch die Seiten-Pipeline laeuft, traegt den Datensatz der Seite, an der
 * es haengt: die XML-Sitemap und die Feeds haengen an der Startseite. Ohne
 * diese Pruefung beantwortete `/sitemap.xml` mit `Accept: text/markdown` die
 * Anfrage mit dem Markdown der Startseite.
 */
final class MarkdownContentNegotiationMiddlewareTest extends TestCase
{
    private bool $handlerCalled = false;

    /** @var array<string, mixed> */
    private array $page = ['uid' => 1, 'pid' => 0, 'doktype' => 1, 'title' => 'Startseite', 'slug' => '/'];

    private function middleware(): MarkdownContentNegotiationMiddleware
    {
        $pageTree = $this->createStub(PageTreeRepositoryInterface::class);
        $pageTree->method('loadVisiblePage')->willReturnCallback(fn(): array => $this->page);
        $pageTree->method('renderPageBody')->willReturn('Der Inhalt der Seite.');

        return new MarkdownContentNegotiationMiddleware(
            new LlmsTxtGeneratorService($pageTree),
            new SiteContextFactory(),
            new ResponseFactory(),
            new StreamFactory(),
        );
    }

    private function handler(): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function (): ResponseInterface {
            $this->handlerCalled = true;

            return new HtmlResponse('<html>die gerenderte Antwort</html>');
        });

        return $handler;
    }

    /**
     * @param array<string, mixed> $routeArguments
     */
    private function request(
        string $pageType = '0',
        array $routeArguments = [],
        string $accept = 'text/markdown',
    ): ServerRequestInterface {
        $site = new Site('example', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
            ],
        ]);
        $pageInformation = new PageInformation();
        $pageInformation->setId((int) $this->page['uid']);
        $pageInformation->setPageRecord($this->page);

        return new ServerRequest('https://example.com/', 'GET')
            ->withHeader('Accept', $accept)
            ->withAttribute('site', $site)
            ->withAttribute('frontend.page.information', $pageInformation)
            ->withAttribute('routing', new PageArguments(
                pageId: (int) $this->page['uid'],
                pageType: $pageType,
                routeArguments: $routeArguments,
            ));
    }

    #[Test]
    public function aRegularPageIsAnsweredAsMarkdown(): void
    {
        $response = $this->middleware()->process($this->request(), $this->handler());

        self::assertSame('text/markdown; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('# Startseite', (string) $response->getBody());
        self::assertFalse($this->handlerCalled, 'the HTML page is not rendered for a Markdown answer');
    }

    /**
     * Die Sitemap ist Seitentyp 1533906435 auf der Startseite. Beantwortet
     * wird sie von EXT:seo, nicht mit dem Markdown der Startseite.
     */
    #[Test]
    public function theXmlSitemapIsNotAnsweredWithTheHomepage(): void
    {
        $response = $this->middleware()->process($this->request(pageType: '1533906435'), $this->handler());

        self::assertTrue($this->handlerCalled);
        self::assertSame('<html>die gerenderte Antwort</html>', (string) $response->getBody());
        self::assertSame('', $response->getHeaderLine('X-Markdown-Tokens'));
    }

    /**
     * Dasselbe fuer jeden anderen Seitentyp: Feeds, JSON-Ausgaben, was spaeter
     * noch registriert wird.
     */
    #[Test]
    public function otherPageTypesFallThrough(): void
    {
        foreach (['9818', '1', '1700000000'] as $pageType) {
            $this->handlerCalled = false;

            $response = $this->middleware()->process($this->request(pageType: $pageType), $this->handler());

            self::assertTrue($this->handlerCalled, 'type ' . $pageType);
            self::assertStringNotContainsString('text/markdown', $response->getHeaderLine('Content-Type'), 'type ' . $pageType);
        }
    }

    /**
     * `/autor/<slug>`, `/glossar/<term>` und Episoden liegen auf einer
     * Container-Seite. Deren Markdown ist nicht das, was die URL zeigt.
     */
    #[Test]
    public function pluginDetailUrlsFallThrough(): void
    {
        $response = $this->middleware()->process(
            $this->request(routeArguments: ['tx_contentblockscollection_person' => ['person' => 'kai-ole-hartwig']]),
            $this->handler(),
        );

        self::assertTrue($this->handlerCalled);
        self::assertStringNotContainsString('text/markdown', $response->getHeaderLine('Content-Type'));
    }

    /**
     * Ein Shortcut muss die Weiterleitung weiter hinten in der Kette
     * erreichen, statt als 200 mit Markdown zu enden.
     */
    #[Test]
    public function shortcutPagesFallThroughToTheirRedirect(): void
    {
        $this->page['doktype'] = 4;

        $this->middleware()->process($this->request(), $this->handler());

        self::assertTrue($this->handlerCalled);
    }

    #[Test]
    public function withoutMarkdownInAcceptTheRegularResponseIsReturned(): void
    {
        $response = $this->middleware()->process($this->request(accept: 'text/html,*/*;q=0.8'), $this->handler());

        self::assertTrue($this->handlerCalled);
        self::assertSame('<html>die gerenderte Antwort</html>', (string) $response->getBody());
    }
}
