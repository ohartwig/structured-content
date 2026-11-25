<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\BookSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Books shown as cards of a showcase element become Schema.org/Book.
 *
 * Which element is a showcase is a site setting; without it the generator
 * stays silent, whatever the page contains.
 */
final class BookSchemaGeneratorTest extends TestCase
{
    use PodcastFeedDoubles;

    private const CTYPE = 'moselwal_bookshowcase';
    private const FIELD = 'book_showcase_items';

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 0; $i < 64; ++$i) {
            GeneralUtility::addInstance(
                FrontendRestrictionContainer::class,
                $this->createStub(FrontendRestrictionContainer::class),
            );
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    private function generator(string $imageUrl = '/fileadmin/books/cover.jpg'): BookSchemaGenerator
    {
        $context = new Context();
        $context->setAspect('language', new LanguageAspect(0));

        $reference = $this->createStub(FileReference::class);
        $reference->method('getPublicUrl')->willReturn($imageUrl);
        $files = $this->createStub(FileRepository::class);
        $files->method('findByRelation')->willReturn('' === $imageUrl ? [] : [$reference]);

        return new BookSchemaGenerator($this->connectionPool(), $context, $files);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function page(array $schema = ['bookShowcases' => [self::CTYPE . ':' . self::FIELD], 'founderPersonSlug' => 'jane-doe']): array
    {
        return [
            'uid' => 5,
            'slug' => '/buecher',
            '_siteBase' => 'https://example.com/',
            '_entityBase' => 'https://example.com/',
            '_siteRootBase' => 'https://example.com/',
            '_language' => 'de-DE',
            '_siteSettings' => ['structuredContent' => ['schema' => $schema]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return list<array<string, mixed>>
     */
    private function showcase(array $overrides = []): array
    {
        return [['uid' => 30, 'CType' => self::CTYPE, 'header' => 'Meine Bücher', ...$overrides]];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function card(array $overrides = []): array
    {
        return [
            'uid' => 101,
            'sys_language_uid' => 0,
            'hidden' => 0,
            'fieldname' => self::FIELD,
            'moselwal_card_header' => 'Würde im Alltag',
            'moselwal_card_bodytext2' => 'Pflege, die zuhört',
            'moselwal_card_bodytext' => '<p>Ein Buch über <strong>Pflege</strong> &amp; Würde.</p>',
            'moselwal_card_cta_link' => 'https://www.amazon.de/dp/B0TEST _blank - "Bei Amazon"',
            'moselwal_card_works_for' => 'Erschienen 2026 · ISBN 979-8258303066',
            ...$overrides,
        ];
    }

    #[Test]
    public function aCardOfAShowcaseBecomesABook(): void
    {
        $this->rows = [$this->card()];

        self::assertTrue($this->generator()->canHandle($this->page(), $this->showcase()));
        $document = $this->generator()->generate($this->page(), $this->showcase());

        self::assertSame('Book', $document->schemaType);
        self::assertSame([
            '@type' => 'Book',
            '@id' => 'https://example.com/buecher#book-9798258303066',
            'name' => 'Würde im Alltag',
            'alternativeHeadline' => 'Pflege, die zuhört',
            'description' => 'Ein Buch über Pflege & Würde.',
            'image' => 'https://example.com/fileadmin/books/cover.jpg',
            'url' => 'https://www.amazon.de/dp/B0TEST',
            'isbn' => '9798258303066',
            'datePublished' => '2026',
            'inLanguage' => 'de-DE',
            'author' => ['@type' => 'Person', '@id' => 'https://example.com/autor/jane-doe#person'],
        ], $document->data);
    }

    #[Test]
    public function severalBooksGoOutAsAGraph(): void
    {
        $this->rows = [
            $this->card(),
            $this->card(['uid' => 102, 'moselwal_card_header' => 'Zweites Buch', 'moselwal_card_works_for' => '']),
        ];

        $document = $this->generator()->generate($this->page(), $this->showcase());

        self::assertCount(2, $document->data['@graph']);
        $second = $document->data['@graph'][1];
        self::assertSame('https://example.com/buecher#book-zweites-buch-102', $second['@id']);
        self::assertArrayNotHasKey('isbn', $second);
        self::assertArrayNotHasKey('datePublished', $second);
    }

    #[Test]
    public function hiddenCardsAndCardsOfAnotherFieldAreLeftOut(): void
    {
        $this->rows = [
            $this->card(['hidden' => 1]),
            $this->card(['uid' => 103, 'fieldname' => 'other_items']),
            $this->card(['uid' => 104, 'moselwal_card_header' => 'Sichtbar']),
        ];

        $document = $this->generator()->generate($this->page(), $this->showcase());

        self::assertSame('Sichtbar', $document->data['name']);
    }

    #[Test]
    public function aHiddenShowcaseIsIgnored(): void
    {
        $this->rows = [$this->card()];

        self::assertFalse($this->generator()->canHandle($this->page(), $this->showcase(['hidden' => 1])));
        self::assertSame([], $this->generator()->generate($this->page(), $this->showcase(['hidden' => 1]))->data);
    }

    #[Test]
    public function nothingHappensWithoutTheSetting(): void
    {
        $this->rows = [$this->card()];

        foreach ([[], ['bookShowcases' => []], ['bookShowcases' => ['']], ['bookShowcases' => [self::CTYPE]]] as $schema) {
            self::assertFalse($this->generator()->canHandle($this->page($schema), $this->showcase()));
        }
    }

    #[Test]
    public function otherElementsAreNoShowcase(): void
    {
        $this->rows = [$this->card()];

        self::assertFalse($this->generator()->canHandle($this->page(), $this->showcase(['CType' => 'moselwal_cards'])));
    }

    #[Test]
    public function withoutFounderTheBookHasNoAuthorAndLongTextsAreShortened(): void
    {
        $this->rows = [$this->card([
            'moselwal_card_bodytext' => '<p>' . str_repeat('Wort ', 100) . '</p>',
            'moselwal_card_cta_link' => 't3://page?uid=12',
        ])];

        $document = $this->generator('')->generate(
            $this->page(['bookShowcases' => [self::CTYPE . ':' . self::FIELD]]),
            $this->showcase(),
        );

        self::assertArrayNotHasKey('author', $document->data);
        self::assertArrayNotHasKey('url', $document->data);
        self::assertArrayNotHasKey('image', $document->data);
        self::assertLessThanOrEqual(300, mb_strlen($document->data['description']));
        self::assertStringEndsWith('Wort…', $document->data['description']);
    }
}
