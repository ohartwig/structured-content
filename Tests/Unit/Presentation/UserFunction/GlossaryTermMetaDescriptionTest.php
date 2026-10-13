<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Presentation\UserFunction;

use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Domain\Contract\GlossaryTermRepositoryInterface;
use Koh\StructuredContent\Presentation\UserFunction\GlossaryTermMetaDescription;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Security\AllowedCallableAssertion;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * The USER cObject behind the meta description of a term page: it reads the
 * term from the routing argument and the language from the request.
 */
final class GlossaryTermMetaDescriptionTest extends TestCase
{
    /** @var list<array{int, int}> */
    private array $lookups = [];

    private function subject(): GlossaryTermMetaDescription
    {
        $repository = $this->createStub(GlossaryTermRepositoryInterface::class);
        $repository->method('isAvailable')->willReturn(true);
        $repository->method('findTermByUid')->willReturnCallback(function (int $uid, int $languageId): array {
            $this->lookups[] = [$uid, $languageId];

            return ['uid' => 1 === $languageId ? 30 : $uid, 'tooltiptext' => 1 === $languageId ? 'English.' : 'Deutsch.'];
        });
        $repository->method('findFirstDescription')->willReturn(null);

        return new GlossaryTermMetaDescription(new GlossaryTermService($repository));
    }

    private function request(?array $routeArguments, int $languageId = 0): ServerRequest
    {
        $request = new ServerRequest('https://example.org/glossar/rce');
        if (null !== $routeArguments) {
            $request = $request->withAttribute('routing', new PageArguments(1499, '0', $routeArguments));
        }

        return $request->withAttribute(
            'language',
            new SiteLanguage($languageId, 0 === $languageId ? 'de-DE' : 'en-GB', new Uri('/'), []),
        );
    }

    /**
     * TypoScript may only call methods marked as allowed. Without the mark the
     * cObject throws, and the meta tag carried the error page's text.
     */
    #[Test]
    public function typoScriptIsAllowedToCallIt(): void
    {
        $this->expectNotToPerformAssertions();

        (new AllowedCallableAssertion())->assertCallable([GlossaryTermMetaDescription::class, 'render']);
    }

    #[Test]
    public function theTermOfTheRouteIsDescribedInThePageLanguage(): void
    {
        $subject = $this->subject();

        self::assertSame('Deutsch.', $subject->render('', [], $this->request(['tx_dpnglossary_glossary' => ['term' => '12']])));
        self::assertSame('English.', $subject->render('', [], $this->request(['tx_dpnglossary_glossary' => ['term' => '12']], 1)));
        self::assertSame([[12, 0], [12, 1]], $this->lookups);
    }

    #[Test]
    public function theLengthComesFromTheConfiguration(): void
    {
        $repository = $this->createStub(GlossaryTermRepositoryInterface::class);
        $repository->method('isAvailable')->willReturn(true);
        $repository->method('findTermByUid')->willReturn(['uid' => 12, 'tooltiptext' => str_repeat('Wort ', 20)]);
        $subject = new GlossaryTermMetaDescription(new GlossaryTermService($repository));

        $description = $subject->render('', ['maxLength' => '30'], $this->request(['tx_dpnglossary_glossary' => ['term' => '12']]));

        self::assertLessThanOrEqual(30, mb_strlen($description));
        self::assertStringEndsWith('…', $description);
    }

    #[Test]
    public function withoutATermRouteThereIsNothingToSay(): void
    {
        $subject = $this->subject();

        self::assertSame('', $subject->render('', [], $this->request(null)));
        self::assertSame('', $subject->render('', [], $this->request(['other' => ['term' => '12']])));
        self::assertSame('', $subject->render('', [], $this->request(['tx_dpnglossary_glossary' => 'x'])));
        self::assertSame([], $this->lookups);
    }
}
