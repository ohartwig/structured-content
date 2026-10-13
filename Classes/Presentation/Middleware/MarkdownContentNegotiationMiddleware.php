<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\Middleware;

use Koh\StructuredContent\Application\Service\LlmsTxtGeneratorService;
use Koh\StructuredContent\Presentation\Factory\SiteContextFactory;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Markdown content negotiation per the llmstxt.org spec.
 *
 * When a request includes `Accept: text/markdown`, return a markdown
 * representation of the same URL instead of the HTML page. Sets
 * `Content-Type: text/markdown` and `X-Markdown-Tokens` (rough char/4
 * heuristic, matching OpenAI's "~4 chars per token" rule of thumb that
 * Anthropic and Google use as a similar baseline).
 *
 * Validation: https://isitagentready.com/api/scan
 *   → checks.contentAccessibility.markdownNegotiation.status = "pass"
 *
 * Short-circuits the HTML rendering pipeline when the markdown variant
 * can be built — TYPO3 page-rendering is expensive and there is no
 * reason to render HTML the agent will discard.
 *
 * Vary: Accept ensures intermediate caches (Caddy, Cloudflare) do not
 * serve the markdown variant to a browser asking for text/html or
 * vice versa.
 *
 * Only a request for a regular page is answered: page type 0 and no route
 * arguments. The decision has to be taken on the request, because this
 * middleware answers before anything is rendered. Everything else that runs
 * through the page pipeline carries the record of the page it is attached
 * to — the XML sitemap (type 1533906435) and feeds sit on the root page, so
 * answering them would return the homepage as Markdown. Plugin detail URLs
 * (`/autor/<slug>`, `/glossar/<term>`, episodes) and paginated lists carry
 * route arguments; their page record is the container page, whose Markdown
 * is not what the URL shows. Shortcut pages have to reach the shortcut
 * redirect further down the chain. All of these fall through to the regular
 * response. Static routes (robots.txt, security.txt) never get here: the
 * static route resolver answers them before the page is resolved.
 */
final readonly class MarkdownContentNegotiationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LlmsTxtGeneratorService $generator,
        private SiteContextFactory $siteContextFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->wantsMarkdown($request)) {
            return $handler->handle($request);
        }

        $site = $request->getAttribute('site');
        $pageInfo = $request->getAttribute('frontend.page.information');
        if (!$site instanceof Site || !$pageInfo instanceof PageInformation) {
            return $handler->handle($request);
        }
        if (!$this->isRegularPageRequest($request, $pageInfo)) {
            return $handler->handle($request);
        }

        $languageId = (int) ($request->getAttribute('language')?->getLanguageId() ?? 0);
        $context = $this->siteContextFactory->create($site);
        $markdown = $this->generator->buildPageMarkdown($context, $pageInfo->getId(), $languageId);
        if ('' === $markdown) {
            // No useful markdown for this page (sysfolder, plugin-only, …) —
            // fall through to HTML rather than serve an empty body the agent
            // would interpret as a successful empty response.
            return $handler->handle($request);
        }

        $tokens = (int) ceil(mb_strlen($markdown) / 4);
        $stream = $this->streamFactory->createStream($markdown);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'text/markdown; charset=utf-8')
            ->withHeader('X-Markdown-Tokens', (string) $tokens)
            ->withHeader('Vary', 'Accept')
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withBody($stream);
    }

    /**
     * A request for the page itself: page type 0, no route arguments, and not
     * a shortcut. See the class comment for what falls through and why.
     */
    private function isRegularPageRequest(ServerRequestInterface $request, PageInformation $pageInfo): bool
    {
        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            return false;
        }
        if (!in_array($routing->getPageType(), ['', '0'], true)) {
            return false;
        }
        if ([] !== $routing->getRouteArguments()) {
            return false;
        }

        return PageRepository::DOKTYPE_SHORTCUT !== (int) ($pageInfo->getPageRecord()['doktype'] ?? 0);
    }

    /**
     * RFC 7231 / 9110 Accept-header parsing — accept `text/markdown` even
     * when bundled with quality params or other types. We deliberately do
     * NOT match `*\/*` because that would override the HTML default for
     * every browser that accepts everything.
     */
    private function wantsMarkdown(ServerRequestInterface $request): bool
    {
        $accept = $request->getHeaderLine('Accept');
        if ('' === $accept) {
            return false;
        }
        foreach (explode(',', $accept) as $part) {
            $type = trim(explode(';', $part)[0]);
            if (0 === strcasecmp($type, 'text/markdown')) {
                return true;
            }
        }

        return false;
    }
}
