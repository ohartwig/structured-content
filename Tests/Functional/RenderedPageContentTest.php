<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Functional;

use Koh\StructuredContent\Infrastructure\SchemaOrg\FaqSchemaGenerator;
use Koh\StructuredContent\Presentation\Middleware\PageContentCollector;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The content elements handed to the schema generators are the ones the
 * frontend renders in the requested language.
 *
 * Observed in production: an English page whose content had been created
 * directly in English (free mode, `l18n_parent = 0`) emitted no schema for
 * it. The middleware read only default-language rows and overlaid them, so a
 * row without a default-language counterpart never reached a generator —
 * and neither did a row for all languages.
 *
 * The expected sets are those of ContentObjectRenderer::getRecords() for
 * tt_content, per overlay type of the site language's fallbackType.
 */
final class RenderedPageContentTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'koh/structured-content',
        'typo3conf/ext/structured_content/Tests/Functional/Fixtures/Extensions/card_collection_fixture',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/RenderedPageContent.csv');
    }

    #[Test]
    public function fallbackShowsTranslationsFreeModeRowsUntranslatedRowsAndRowsForAllLanguages(): void
    {
        $elements = $this->collect(new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_MIXED, [0]));

        self::assertSame(
            ['Connected', 'Free-mode questions', 'Unübersetzt', 'For all languages'],
            array_column($elements, 'header'),
            'in sorting order; the hidden free-mode row and rows of another language left out',
        );
        self::assertSame(11, $elements[0]['_LOCALIZED_UID'] ?? null, 'the connected translation is overlaid');
    }

    #[Test]
    public function strictDropsUntranslatedRowsButKeepsFreeModeRows(): void
    {
        // fallbackType `strict` is OVERLAYS_ON_WITH_FLOATING: the frontend
        // still renders records without a default-language counterpart.
        $elements = $this->collect(new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON_WITH_FLOATING, []));

        self::assertSame(
            ['Connected', 'Free-mode questions', 'For all languages'],
            array_column($elements, 'header'),
        );
    }

    #[Test]
    public function overlaysOnShowsNoFreeModeRows(): void
    {
        $elements = $this->collect(new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON, []));

        self::assertSame(['Connected', 'For all languages'], array_column($elements, 'header'));
    }

    #[Test]
    public function freeShowsOnlyRowsOfTheLanguageAndForAllLanguages(): void
    {
        $elements = $this->collect(new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_OFF, [0]));

        self::assertSame(
            ['Connected', 'Free-mode questions', 'For all languages'],
            array_column($elements, 'header'),
        );
        self::assertSame(11, $elements[0]['uid'], 'without overlays the translation is its own row');
    }

    #[Test]
    public function theDefaultLanguageShowsDefaultRowsAndRowsForAllLanguages(): void
    {
        $elements = $this->collect(new LanguageAspect(0, 0, LanguageAspect::OVERLAYS_ON_WITH_FLOATING, []));

        self::assertSame(['Verbunden', 'Unübersetzt', 'For all languages'], array_column($elements, 'header'));
    }

    #[Test]
    public function anotherLanguageGetsItsOwnTranslationsAndFreeModeRows(): void
    {
        $elements = $this->collect(new LanguageAspect(2, 2, LanguageAspect::OVERLAYS_MIXED, [0]));

        self::assertSame(
            ['Connecté', 'Mode libre', 'Unübersetzt', 'For all languages'],
            array_column($elements, 'header'),
        );
    }

    #[Test]
    public function noElementIsHandedOverTwice(): void
    {
        foreach ([
            new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_MIXED, [0]),
            new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON_WITH_FLOATING, []),
            new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_OFF, [0]),
        ] as $language) {
            $shown = array_map(
                static fn(array $element): int => (int) ($element['_LOCALIZED_UID'] ?? $element['uid']),
                $this->collect($language),
            );

            self::assertSame(array_values(array_unique($shown)), $shown, $language->getOverlayType());
            self::assertNotContains(10, $shown, 'the default row of an overlaid translation is not shown as well');
        }
    }

    #[Test]
    public function aFreeModeElementBringsItsOwnInlineChildren(): void
    {
        $language = new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_MIXED, [0]);
        $faq = array_values(array_filter(
            $this->collect($language),
            static fn(array $element): bool => 'moselwal_faq' === $element['CType'],
        ));
        self::assertCount(1, $faq);

        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('language', $language);
        $document = (new FaqSchemaGenerator($this->get(ConnectionPool::class), $context))
            ->generate(['title' => 'Advisory'], $faq);

        self::assertSame(
            ['Is this free mode?'],
            array_column($document->data['mainEntity'] ?? [], 'name'),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collect(LanguageAspect $language): array
    {
        GeneralUtility::makeInstance(Context::class)->setAspect('language', $language);

        return (new PageContentCollector($this->get(ConnectionPool::class)))->findRenderedContent(2, $language);
    }
}
