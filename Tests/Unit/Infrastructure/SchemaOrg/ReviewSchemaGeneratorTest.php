<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\ReviewSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Kundenstimmen — die Sorte strukturierter Daten, bei der ein Fehler nicht nur
 * unwirksam ist, sondern eine falsche Aussage ueber Dritte macht.
 *
 * Drei Eigenschaften halten das zusammen.
 *
 * Eine Bewertung von 0 ist keine Bewertung. Sie entsteht aus einem leeren Feld
 * — `(float) ''` ist 0.0 — und als `ratingValue: 0` ausgegeben ist sie die
 * schlechtestmoegliche Note, die niemand vergeben hat. Sie zaehlt deshalb auch
 * nicht in den Durchschnitt: eine ungefuellte Karte wuerde ihn sonst nach
 * unten ziehen.
 *
 * Eine Stimme ohne Namen und ohne Text ist keine Stimme. Ein leeres
 * `Review`-Objekt behauptet eine Bewertung, ueber die nichts bekannt ist.
 *
 * Und mehrere Stimmen ergeben eine Gesamtbewertung, nicht zwanzig einzelne
 * Bewertungen der Organisation. Das ist der Unterschied zwischen "vier Sterne
 * aus zwanzig Stimmen" und zwanzig konkurrierenden Aussagen ueber dieselbe
 * Firma.
 */
final class ReviewSchemaGeneratorTest extends TestCase
{
    use PodcastFeedDoubles;

    protected function setUp(): void
    {
        parent::setUp();

        for ($i = 0; $i < 64; ++$i) {
            GeneralUtility::addInstance(TcaSchemaFactory::class, $this->createStub(TcaSchemaFactory::class));
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

    private function generator(): ReviewSchemaGenerator
    {
        return new ReviewSchemaGenerator($this->connectionPool());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function page(array $overrides = []): array
    {
        return [
            'uid' => 42,
            'title' => 'Was Kunden sagen',
            '_siteBase' => 'https://example.com/',
            'sys_language_uid' => 0,
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function testimonialBlock(): array
    {
        return [['uid' => 1, 'CType' => 'moselwal_testimonials', 'header' => 'Kundenstimmen']];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function card(array $overrides = []): array
    {
        return [
            'uid' => 10,
            'moselwal_card_header' => 'Ada Lovelace',
            'moselwal_card_bodytext' => 'Hat uns sehr geholfen.',
            'moselwal_card_rating_value' => '5',
            'crdate' => 1785300000,
            ...$overrides,
        ];
    }

    #[Test]
    public function aPageWithTestimonialsIsHandled(): void
    {
        foreach (['moselwal_testimonials', 'moselwal_reviewslider', 'MOSELWAL_TESTIMONIAL'] as $cType) {
            self::assertTrue(
                $this->generator()->canHandle($this->page(), [['uid' => 1, 'CType' => $cType]]),
                $cType,
            );
        }
    }

    #[Test]
    public function aPageMarkedAsAReviewIsHandledWithoutABlock(): void
    {
        self::assertTrue(
            $this->generator()->canHandle($this->page(['tx_structuredcontent_schema_type' => 'Review']), []),
        );
    }

    #[Test]
    public function anOrdinaryPageIsNot(): void
    {
        self::assertFalse($this->generator()->canHandle($this->page(), [['uid' => 1, 'CType' => 'text']]));
        self::assertFalse($this->generator()->canHandle($this->page(), []));
    }

    #[Test]
    public function aSingleVoiceBecomesASingleReview(): void
    {
        $this->rows = [$this->card()];

        $document = $this->generator()->generate($this->page(), $this->testimonialBlock());

        self::assertSame('Review', $document->schemaType);
        self::assertSame('Review', $document->data['@type']);
        self::assertSame(['@type' => 'Person', 'name' => 'Ada Lovelace'], $document->data['author']);
        self::assertSame('Hat uns sehr geholfen.', $document->data['reviewBody']);
        self::assertSame(5.0, $document->data['reviewRating']['ratingValue']);
    }

    /**
     * Die Bewertungsskala wird mitgeliefert. Ohne sie ist eine 4 nicht
     * einzuordnen — auf einer Fuenfer-Skala ist sie gut, auf einer Zehner
     * mittelmaessig.
     */
    #[Test]
    public function theRatingCarriesItsScale(): void
    {
        $this->rows = [$this->card()];

        $rating = $this->generator()->generate($this->page(), $this->testimonialBlock())->data['reviewRating'];

        self::assertSame(5, $rating['bestRating']);
        self::assertSame(1, $rating['worstRating']);
    }

    /**
     * Die erste Regression: eine Bewertung von 0 wird nicht ausgegeben. Sie
     * entsteht aus einem leeren Feld und waere die schlechteste Note, die
     * niemand vergeben hat.
     */
    #[Test]
    public function anUnratedVoiceCarriesNoRating(): void
    {
        foreach (['', '0', 0, null] as $value) {
            $this->rows = [$this->card(['moselwal_card_rating_value' => $value])];

            $document = $this->generator()->generate($this->page(), $this->testimonialBlock());

            self::assertArrayNotHasKey('reviewRating', $document->data, var_export($value, true));
        }
    }

    /**
     * Die zweite: eine Stimme ohne Namen und ohne Text ist keine Stimme. Ein
     * leeres Review-Objekt behauptet eine Bewertung, ueber die nichts bekannt
     * ist.
     */
    #[Test]
    public function aVoiceWithNeitherANameNorAText(): void
    {
        $this->rows = [$this->card(['moselwal_card_header' => '', 'moselwal_card_bodytext' => ''])];

        $document = $this->generator()->generate($this->page(), $this->testimonialBlock());

        self::assertSame([], $document->data);
    }

    /**
     * Ein Name allein reicht, ein Text allein auch — beides kommt in der
     * Praxis vor, und beides ist mehr als nichts.
     */
    #[Test]
    public function halfAVoiceIsStillAVoice(): void
    {
        $this->rows = [$this->card(['moselwal_card_bodytext' => ''])];
        self::assertArrayHasKey('author', $this->generator()->generate($this->page(), $this->testimonialBlock())->data);

        $this->rows = [$this->card(['moselwal_card_header' => ''])];
        $document = $this->generator()->generate($this->page(), $this->testimonialBlock());
        self::assertArrayHasKey('reviewBody', $document->data);
        self::assertArrayNotHasKey('author', $document->data);
    }

    /**
     * Die Firma der Person wird uebernommen, wenn sie gepflegt ist — sie ist
     * das, was eine Stimme glaubwuerdig macht.
     */
    #[Test]
    public function theEmployerOfAVoiceIsCarried(): void
    {
        $this->rows = [$this->card(['moselwal_card_works_for' => 'Analytical Engines Ltd'])];

        $author = $this->generator()->generate($this->page(), $this->testimonialBlock())->data['author'];

        self::assertSame(['@type' => 'Organization', 'name' => 'Analytical Engines Ltd'], $author['worksFor']);
    }

    /**
     * Die dritte Regression: mehrere Stimmen ergeben eine Gesamtbewertung,
     * nicht mehrere konkurrierende Aussagen ueber dieselbe Firma.
     */
    #[Test]
    public function severalVoicesBecomeOneAggregateRating(): void
    {
        $this->rows = [
            $this->card(),
            $this->card(['uid' => 11, 'moselwal_card_header' => 'Grace Hopper', 'moselwal_card_rating_value' => '4']),
        ];

        $document = $this->generator()->generate($this->page(), $this->testimonialBlock());

        self::assertSame('AggregateRating', $document->schemaType);
        self::assertSame(2, $document->data['reviewCount']);
        self::assertSame(4.5, $document->data['ratingValue']);
        self::assertCount(2, $document->data['review']);
        self::assertSame(
            ['@type' => 'Organization', '@id' => 'https://example.com#organization'],
            $document->data['itemReviewed'],
        );
    }

    /**
     * Und der Durchschnitt zaehlt nur, was bewertet wurde. Eine ungefuellte
     * Karte wuerde ihn sonst nach unten ziehen — mit einer Note, die niemand
     * vergeben hat.
     */
    #[Test]
    public function theAverageCountsOnlyTheVoicesThatRated(): void
    {
        $this->rows = [
            $this->card(),
            $this->card(['uid' => 11, 'moselwal_card_header' => 'Grace Hopper', 'moselwal_card_rating_value' => '']),
        ];

        $document = $this->generator()->generate($this->page(), $this->testimonialBlock());

        self::assertSame(2, $document->data['reviewCount'], 'both voices are shown');
        self::assertSame(5.0, $document->data['ratingValue'], 'but only one of them rated');
    }

    /**
     * Ohne einzige Bewertung gibt es keinen Durchschnitt — 0 waere hier die
     * gleiche Falschaussage wie auf einer einzelnen Karte.
     */
    #[Test]
    public function withoutASingleRatingThereIsNoAverage(): void
    {
        $this->rows = [
            $this->card(['moselwal_card_rating_value' => '']),
            $this->card(['uid' => 11, 'moselwal_card_header' => 'Grace Hopper', 'moselwal_card_rating_value' => '']),
        ];

        $document = $this->generator()->generate($this->page(), $this->testimonialBlock());

        self::assertArrayNotHasKey('ratingValue', $document->data);
        self::assertSame(2, $document->data['reviewCount']);
    }

    /**
     * Ohne Site-Basis wird die Organisation beim Namen genannt statt ueber
     * eine Adresse, die es nicht gibt.
     */
    #[Test]
    public function withoutASiteBaseTheOrganisationIsNamedRatherThanLinked(): void
    {
        $this->rows = [
            $this->card(),
            $this->card(['uid' => 11, 'moselwal_card_header' => 'Grace Hopper']),
        ];

        $document = $this->generator()->generate($this->page(['_siteBase' => '']), $this->testimonialBlock());

        self::assertSame(
            ['@type' => 'Organization', 'name' => 'Was Kunden sagen'],
            $document->data['itemReviewed'],
        );
    }

    /**
     * Eine Seite mit Block, aber ohne Karten, liefert ein leeres Dokument —
     * die Middleware wirft es weg, statt einen typisierten Knoten ohne Inhalt
     * auszuliefern.
     */
    #[Test]
    public function aBlockWithoutCardsProducesAnEmptyDocument(): void
    {
        $document = $this->generator()->generate($this->page(), $this->testimonialBlock());

        self::assertSame([], $document->data);
    }
}
