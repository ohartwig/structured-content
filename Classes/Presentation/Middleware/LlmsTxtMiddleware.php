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
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Intercepts /llms.txt and /llms-full.md under each site language's base
 * path - /llms.txt for the default language, /en/llms.txt for a language
 * whose base is /en/ - and returns auto-generated content from the page tree
 * in that language.
 *
 * Runs `before` the page-resolver so it short-circuits the regular routing
 * pipeline before TYPO3 tries to find a page record matching these slugs.
 */
final readonly class LlmsTxtMiddleware implements MiddlewareInterface
{
    public function __construct(
        private LlmsTxtGeneratorService $generator,
        private SiteContextFactory $siteContextFactory,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $site = $request->getAttribute('site');
        if (!$site instanceof Site) {
            return $handler->handle($request);
        }

        $language = $request->getAttribute('language');
        $language = $language instanceof SiteLanguage ? $language : $site->getDefaultLanguage();

        $file = self::requestedFile($request->getUri()->getPath(), $language);
        if (null === $file) {
            return $handler->handle($request);
        }

        $settings = $site->getSettings();
        if (!(bool) $settings->get('structuredContent.enabled') || !(bool) $settings->get('structuredContent.llmsTxt.enabled')) {
            return $handler->handle($request);
        }

        $context = $this->siteContextFactory->create($site, $language);

        $body = 'llms.txt' === $file
            ? $this->generator->buildLlmsTxt($context)
            : $this->generator->buildLlmsFullMd($context);

        if ('' === $body) {
            return $handler->handle($request);
        }

        $contentType = 'llms.txt' === $file ? 'text/plain; charset=utf-8' : 'text/markdown; charset=utf-8';

        $stream = $this->streamFactory->createStream($body);

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withBody($stream);
    }

    /**
     * Which generated file the path asks for, relative to the language's base
     * path, or null when it is not one of them. Until 2026-10-01 only the bare
     * /llms.txt matched, so a language with its own base (/en/) had no
     * llms.txt at all and its per-language settings (taglineEn,
     * featuredGroupLabelEn ...) were never used.
     */
    public static function requestedFile(string $path, SiteLanguage $language): ?string
    {
        $base = rtrim($language->getBase()->getPath(), '/');
        foreach (['llms.txt', 'llms-full.md'] as $file) {
            if ($path === $base . '/' . $file) {
                return $file;
            }
        }

        return null;
    }
}
