<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\UserFunction;

use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Meta description of a glossary term page (featdd/dpn-glossary).
 *
 * Term pages render on the glossary listing page and would otherwise carry
 * its description, which EXT:seo sets from the page record. The setup
 * replaces the tag (`replace = 1`) with what this function returns; an empty
 * result leaves the listing page's description in place.
 *
 * Called as a USER cObject from Configuration/Sets/StructuredContent/setup.typoscript.
 * TYPO3 14 calls a userFunc only when the method carries #[AsAllowedCallable];
 * without it the cObject throws, and the error page's text ends up in the tag.
 * Public in Services.yaml so that ContentObjectRenderer gets the container
 * instance and with it the per-request caches of the service.
 */
final readonly class GlossaryTermMetaDescription
{
    public function __construct(
        private GlossaryTermService $glossaryService,
    ) {}

    /**
     * @param array<string, mixed> $conf
     */
    #[AsAllowedCallable]
    public function render(string $content, array $conf, ServerRequestInterface $request): string
    {
        $routing = $request->getAttribute('routing');
        if (!$routing instanceof PageArguments) {
            return '';
        }
        $arguments = $routing->getArguments()['tx_dpnglossary_glossary'] ?? null;
        if (!is_array($arguments)) {
            return '';
        }
        $identifier = trim((string) ($arguments['term'] ?? ''));

        $language = $request->getAttribute('language');
        $languageId = $language instanceof SiteLanguage ? $language->getLanguageId() : 0;

        $maxLength = (int) ($conf['maxLength'] ?? 0);

        return $this->glossaryService->describeTerm($identifier, $languageId, $maxLength > 0 ? $maxLength : 155);
    }
}
