<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Functional;

use Koh\StructuredContent\Infrastructure\SchemaOrg\FaqSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ItemListSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\ReviewSchemaGenerator;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The schema of a translated page has to list the inline children the page
 * shows, not those of the default-language element.
 *
 * Observed in production: an English advisory page rendered its English FAQ
 * cards, while the FAQPage JSON-LD in the head carried the German questions.
 * The English cards had been created on the translated element (free mode,
 * `l10n_parent = 0`); the generator read the cards of the default-language
 * element and overlaid translations per `l10n_parent` — of which there were
 * none.
 *
 * The content elements are prepared the way JsonLdInjectorMiddleware does
 * it: default-language rows through PageRepository::getLanguageOverlay().
 * The expected children are what the core's RelationResolver renders: for a
 * translated element, the records attached to the translation's uid.
 */
final class TranslatedInlineChildrenTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'koh/structured-content',
        'typo3conf/ext/structured_content/Tests/Functional/Fixtures/Extensions/card_collection_fixture',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/TranslatedInlineChildren.csv');
        $this->importCSVDataSet(__DIR__ . '/Fixtures/TranslatedCollectionCards.csv');
    }

    #[Test]
    public function aTranslatedElementListsItsOwnCards(): void
    {
        $questions = $this->faqQuestions($this->englishFallback(), 10);

        self::assertSame(
            ['Why did it take so long?', 'Do we have to update immediately?'],
            $questions,
            'the English cards in their own sorting; hidden cards and cards of another language left out',
        );
    }

    #[Test]
    public function aTranslatedElementWithoutCardsListsNone(): void
    {
        // The frontend resolves the collection by the translation's uid and
        // shows no cards. A schema with the German cards would claim
        // questions the page does not show.
        self::assertSame([], $this->faqQuestions($this->englishFallback(), 20));
    }

    #[Test]
    public function connectedTranslationsAreReadFromTheTranslatedElement(): void
    {
        self::assertSame(['Connected?'], $this->faqQuestions($this->englishFallback(), 40));
    }

    #[Test]
    public function anUntranslatedElementFallsBackToTheDefaultLanguage(): void
    {
        // fallbackType `fallback`: the element is shown in German, and so
        // are its cards.
        self::assertSame(['Ist das übersetzt?'], $this->faqQuestions($this->englishFallback(), 30));
    }

    #[Test]
    public function anUntranslatedElementIsDroppedInStrictMode(): void
    {
        $strict = new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_ON, []);

        self::assertNull($this->overlaidElement($strict, 30), 'the page does not show it');
    }

    #[Test]
    public function theDefaultLanguageListsTheDefaultCards(): void
    {
        self::assertSame(
            ['Warum hat es so lange gedauert?', 'Müssen wir sofort updaten?'],
            $this->faqQuestions(new LanguageAspect(0), 10),
        );
    }

    #[Test]
    public function aTranslatedCardBlockListsItsOwnCards(): void
    {
        $language = $this->englishFallback();
        $element = $this->overlaidElement($language, 50);
        self::assertNotNull($element);

        $document = (new ItemListSchemaGenerator($this->get(ConnectionPool::class)))->generate(
            ['_siteBase' => 'https://example.com/en/', '_languageId' => 1],
            [$element],
        );

        self::assertSame(
            ['Getting started'],
            array_column($document->data['itemListElement'] ?? [], 'name'),
        );
    }

    #[Test]
    public function aTranslatedTestimonialBlockListsItsOwnCards(): void
    {
        $language = $this->englishFallback();
        $element = $this->overlaidElement($language, 60);
        self::assertNotNull($element);

        $document = (new ReviewSchemaGenerator($this->get(ConnectionPool::class)))->generate(
            ['_siteBase' => 'https://example.com/en/', 'sys_language_uid' => 1],
            [$element],
        );

        self::assertSame('Very good advice.', $document->data['reviewBody'] ?? null);
    }

    private function englishFallback(): LanguageAspect
    {
        return new LanguageAspect(1, 1, LanguageAspect::OVERLAYS_MIXED, [0]);
    }

    /**
     * @return list<mixed>
     */
    private function faqQuestions(LanguageAspect $language, int $defaultUid): array
    {
        $element = $this->overlaidElement($language, $defaultUid);
        self::assertNotNull($element, 'the element is shown on the page');

        $generator = new FaqSchemaGenerator($this->get(ConnectionPool::class), $this->context($language));
        $document = $generator->generate(['title' => 'Advisory'], [$element]);

        return array_column($document->data['mainEntity'] ?? [], 'name');
    }

    /**
     * The content element as JsonLdInjectorMiddleware hands it to the
     * generators.
     *
     * @return array<string, mixed>|null
     */
    private function overlaidElement(LanguageAspect $language, int $defaultUid): ?array
    {
        $qb = $this->get(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));
        $row = $qb->select('*')
            ->from('tt_content')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($defaultUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        self::assertIsArray($row);

        if (0 === $language->getId()) {
            return $row;
        }

        return GeneralUtility::makeInstance(PageRepository::class, $this->context($language))
            ->getLanguageOverlay('tt_content', $row, $language);
    }

    private function context(LanguageAspect $language): Context
    {
        $context = GeneralUtility::makeInstance(Context::class);
        $context->setAspect('language', $language);

        return $context;
    }
}
