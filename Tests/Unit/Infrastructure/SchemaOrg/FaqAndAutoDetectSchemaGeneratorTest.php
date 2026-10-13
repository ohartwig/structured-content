<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\SchemaTypeResolverService;
use Koh\StructuredContent\Infrastructure\SchemaOrg\AutoDetectSchemaGenerator;
use Koh\StructuredContent\Infrastructure\SchemaOrg\FaqSchemaGenerator;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die FAQ-Erkennung und der Auffangnetz-Generator.
 *
 * Die FAQ-Erkennung liest Fragen aus Fliesstext: eine Zeile, die mit einem
 * Fragezeichen endet, ist eine Frage, alles bis zur naechsten ist die Antwort.
 * Das ist eine Heuristik, und die Eigenschaft, die sie brauchbar haelt, ist
 * die Auslassung: eine Frage ohne Antwort kommt nicht in die Ausgabe. Eine
 * `Question` mit leerer `acceptedAnswer` ist in den Rich Results eine
 * ausklappbare Zeile, die sich zu nichts ausklappt — sichtbar, in der Suche,
 * fuer alle.
 *
 * Der Auffangnetz-Generator ist das Gegenstueck: er greift nur, wenn kein
 * anderer greift, und er beansprucht die Startseite nie. Sein zweiter
 * Ausschluss ist der interessantere: erkennt er einen Typ, der Angebote oder
 * Bewertungen voraussetzt, ohne dass welche da sind, faellt er auf `WebPage`
 * zurueck. Ein `Product` ohne Preis und ohne Bewertung ist genau die Angabe,
 * die eine Rich-Result-Pruefung als unvollstaendig abstraft.
 */
final class FaqAndAutoDetectSchemaGeneratorTest extends TestCase
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

    private function faq(): FaqSchemaGenerator
    {
        return new FaqSchemaGenerator($this->connectionPool(), $this->defaultLanguageContext());
    }

    private function autoDetect(): AutoDetectSchemaGenerator
    {
        return new AutoDetectSchemaGenerator(new SchemaTypeResolverService());
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
            '_rootPageId' => 1,
            'title' => 'Haeufige Fragen',
            'description' => 'Antworten auf das, was oft gefragt wird.',
            'slug' => '/faq',
            'canonical_link' => 'https://example.com/faq',
            '_siteBase' => 'https://example.com/',
            ...$overrides,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function textFaq(string $bodytext): array
    {
        return [['uid' => 1, 'CType' => 'text', 'header' => 'FAQ', 'bodytext' => $bodytext]];
    }

    /**
     * Inhalt ohne jedes Erkennungsmerkmal — fuer die Faelle, in denen genau
     * das gebraucht wird: eine Seite, die keinen bestimmteren Generator
     * anzieht.
     *
     * @return list<array<string, mixed>>
     */
    private function plainText(): array
    {
        return [['uid' => 1, 'CType' => 'text', 'header' => 'Einleitung', 'bodytext' => 'Ein Absatz ohne Signalwoerter.']];
    }

    #[Test]
    public function questionsAndAnswersAreReadOutOfRunningText(): void
    {
        $document = $this->faq()->generate($this->page(), $this->textFaq(
            "Was kostet das?\nDas haengt vom Umfang ab.\nWie lange dauert es?\nMeist zwei Wochen.",
        ));

        self::assertSame('FAQPage', $document->schemaType);
        self::assertCount(2, $document->data['mainEntity']);

        $first = $document->data['mainEntity'][0];
        self::assertSame('Question', $first['@type']);
        self::assertSame('Was kostet das?', $first['name']);
        self::assertSame('Answer', $first['acceptedAnswer']['@type']);
        self::assertStringContainsString('Das haengt vom Umfang ab.', $first['acceptedAnswer']['text']);
    }

    /**
     * Die Eigenschaft, die die Heuristik brauchbar haelt: eine Frage ohne
     * Antwort kommt nicht in die Ausgabe. Eine Question mit leerer Antwort ist
     * in den Rich Results eine Zeile, die sich zu nichts ausklappt.
     */
    #[Test]
    public function aQuestionWithoutAnAnswerIsLeftOut(): void
    {
        $document = $this->faq()->generate($this->page(), $this->textFaq(
            "Was kostet das?\nDas haengt vom Umfang ab.\nUnd sonst?",
        ));

        self::assertCount(1, $document->data['mainEntity']);
        self::assertSame('Was kostet das?', $document->data['mainEntity'][0]['name']);
    }

    /**
     * Eine mehrzeilige Antwort bleibt eine Antwort — der Text laeuft bis zur
     * naechsten Frage.
     */
    #[Test]
    public function anAnswerRunsUntilTheNextQuestion(): void
    {
        $document = $this->faq()->generate($this->page(), $this->textFaq(
            "Was kostet das?\nDas haengt vom Umfang ab.\nUnd von der Frist.\nWie lange dauert es?\nZwei Wochen.",
        ));

        $answer = $document->data['mainEntity'][0]['acceptedAnswer']['text'];

        self::assertStringContainsString('Umfang', $answer);
        self::assertStringContainsString('Frist', $answer);
        self::assertStringNotContainsString('Zwei Wochen', $answer);
    }

    /**
     * Auszeichnung wird entfernt: `<p>`-Klammern im Antworttext erscheinen in
     * einem Suchergebnis als Text.
     */
    #[Test]
    public function markupIsStrippedFromTheAnswers(): void
    {
        $document = $this->faq()->generate($this->page(), $this->textFaq(
            "<p>Was kostet das?</p>\n<p>Das haengt vom Umfang ab.</p>",
        ));

        $encoded = json_encode($document->data, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('<p>', $encoded);
    }

    /**
     * Dasselbe Element zweimal in der Liste ergibt die Fragen nicht zweimal —
     * doppelte Fragen sind in den Rich Results doppelte Zeilen.
     */
    #[Test]
    public function thesameBlockListedTwiceDoesNotDoubleTheQuestions(): void
    {
        $block = $this->textFaq("Was kostet das?\nDas haengt vom Umfang ab.")[0];

        $document = $this->faq()->generate($this->page(), [$block, $block]);

        self::assertCount(1, $document->data['mainEntity']);
    }

    /**
     * Ein Text ohne Fragen ergibt kein FAQ-Dokument — die Middleware wirft das
     * leere weg, statt eine FAQPage ohne Fragen auszuliefern.
     */
    #[Test]
    public function textWithoutQuestionsProducesNoFaq(): void
    {
        $document = $this->faq()->generate($this->page(), $this->textFaq('Wir sind eine Agentur aus Monheim.'));

        self::assertSame([], $document->data);
    }


    /**
     * Ein gepflegter FAQ-Block traegt die Fragen als Karten, nicht als Text —
     * das ist der Weg, den eine Redaktion nimmt, wenn sie Fragen wirklich
     * pflegt statt sie in einen Absatz zu schreiben.
     */
    #[Test]
    public function aMaintainedFaqBlockReadsItsCards(): void
    {
        $this->rows = [
            ['uid' => 10, 'sorting' => 128, 'moselwal_card_header' => 'Was kostet das?', 'moselwal_card_bodytext' => 'Das haengt vom Umfang ab.'],
            ['uid' => 11, 'sorting' => 256, 'moselwal_card_header' => 'Wie lange dauert es?', 'moselwal_card_bodytext' => 'Meist zwei Wochen.'],
        ];

        $document = $this->faq()->generate($this->page(), [['uid' => 1, 'CType' => 'moselwal_faq']]);

        self::assertSame('FAQPage', $document->schemaType);
        self::assertCount(2, $document->data['mainEntity']);
        self::assertSame('Was kostet das?', $document->data['mainEntity'][0]['name']);
        self::assertSame(
            'Das haengt vom Umfang ab.',
            $document->data['mainEntity'][0]['acceptedAnswer']['text'],
        );
    }

    /**
     * Die Karten kommen in ihrer gepflegten Reihenfolge — eine FAQ-Liste ist
     * eine Reihenfolge, und die haeufigste Frage steht oben.
     */
    #[Test]
    public function theCardsKeepTheirMaintainedOrder(): void
    {
        $this->rows = [
            ['uid' => 10, 'sorting' => 128, 'moselwal_card_header' => 'Was kostet das?', 'moselwal_card_bodytext' => 'Kommt darauf an.'],
        ];

        $this->faq()->generate($this->page(), [['uid' => 1, 'CType' => 'moselwal_faq']]);

        self::assertSame([['sorting', 'ASC']], $this->ordering);
    }

    /**
     * Und dieselbe Auslassung wie im Fliesstext: eine Karte ohne Antwort
     * kommt nicht in die Ausgabe.
     */
    #[Test]
    public function aCardWithoutAnAnswerIsLeftOut(): void
    {
        $this->rows = [
            ['uid' => 10, 'sorting' => 128, 'moselwal_card_header' => 'Was kostet das?', 'moselwal_card_bodytext' => '  '],
            ['uid' => 11, 'sorting' => 256, 'moselwal_card_header' => 'Wie lange dauert es?', 'moselwal_card_bodytext' => 'Zwei Wochen.'],
        ];

        $document = $this->faq()->generate($this->page(), [['uid' => 1, 'CType' => 'moselwal_faq']]);

        self::assertCount(1, $document->data['mainEntity']);
        self::assertSame('Wie lange dauert es?', $document->data['mainEntity'][0]['name']);
    }

    /**
     * Ein FAQ-Block ohne Karten ergibt kein Dokument — die Middleware wirft
     * das leere weg, statt eine FAQPage ohne Fragen auszuliefern.
     */
    #[Test]
    public function aMaintainedBlockWithoutCardsProducesNothing(): void
    {
        $document = $this->faq()->generate($this->page(), [['uid' => 1, 'CType' => 'moselwal_faq']]);

        self::assertSame([], $document->data);
    }

    /**
     * Der Auffangnetz-Generator beansprucht die Startseite nie: dort stehen
     * Organisation und Website, und ein generisch erkannter Typ daneben waere
     * eine zweite Haupt-Entitaet auf der wichtigsten Seite.
     */
    #[Test]
    public function theFallbackNeverClaimsTheRootPage(): void
    {
        self::assertFalse(
            $this->autoDetect()->canHandle($this->page(['uid' => 1, '_rootPageId' => 1]), $this->plainText()),
        );
    }

    /**
     * Und er tritt zurueck, sobald ein bestimmterer Generator zustaendig ist —
     * eine als Artikel gepflegte Seite geht ihn nichts an.
     */
    #[Test]
    public function theFallbackStepsAsideForAMaintainedType(): void
    {
        self::assertFalse(
            $this->autoDetect()->canHandle(
                $this->page(['tx_structuredcontent_schema_type' => 'BlogPosting']),
                $this->plainText(),
            ),
        );
    }

    /**
     * Und er greift genau dort, wo der Aufloeser nichts erkennt: eine Seite
     * mit Titel, aber ohne Inhaltselemente, die er einordnen koennte. Das ist
     * die Luecke, fuer die es ihn gibt — eine solche Seite ginge sonst ganz
     * ohne Schema hinaus.
     */
    #[Test]
    public function aPageTheResolverCannotTypeIsCaughtByTheFallback(): void
    {
        self::assertTrue($this->autoDetect()->canHandle(
            $this->page(['slug' => '/eine-seite', 'title' => 'Eine Seite', 'keywords' => '']),
            [],
        ));
    }

    /**
     * Sobald der Aufloeser die Seite einordnen kann, tritt er zurueck — sonst
     * stuenden zwei Dokumente nebeneinander, die dieselbe Seite beschreiben.
     */
    #[Test]
    public function aPageTheResolverCanTypeIsLeftAlone(): void
    {
        self::assertFalse($this->autoDetect()->canHandle(
            $this->page(['slug' => '/eine-seite', 'title' => 'Eine Seite', 'keywords' => '']),
            $this->plainText(),
        ));
    }

    #[Test]
    public function anEmptyPageIsNotWorthAFallbackDocument(): void
    {
        self::assertFalse($this->autoDetect()->canHandle(['uid' => 42, '_rootPageId' => 1, 'title' => ''], []));
    }

    /**
     * Der zweite Ausschluss, und der interessantere: ein erkannter Typ, der
     * Angebote oder Bewertungen voraussetzt, faellt ohne sie auf `WebPage`
     * zurueck. Ein Product ohne Preis und ohne Bewertung ist genau die
     * Angabe, die eine Rich-Result-Pruefung als unvollstaendig abstraft.
     */
    #[Test]
    public function aTypeThatNeedsOffersFallsBackToAPlainPageWithoutThem(): void
    {
        $document = $this->autoDetect()->generate(
            $this->page(['slug' => '/produkte/ein-ding', 'title' => 'Ein Produkt', 'keywords' => 'product']),
            $this->plainText(),
        );

        self::assertSame('WebPage', $document->schemaType);
    }

    #[Test]
    public function theFallbackCarriesTheNameDescriptionAndUrlOfThePage(): void
    {
        $document = $this->autoDetect()->generate(
            $this->page(['slug' => '/eine-seite', 'title' => 'Eine Seite', 'keywords' => '']),
            [],
        );

        self::assertSame('Eine Seite', $document->data['name']);
        self::assertSame('Antworten auf das, was oft gefragt wird.', $document->data['description']);
        self::assertSame('https://example.com/faq', $document->data['url']);
    }

    /**
     * Der Auffangnetz-Generator ordnet sich unter alle anderen ein — er ist
     * das, was greift, wenn nichts Bestimmteres greift.
     */
    #[Test]
    public function theFallbackSortsBelowEveryOtherGenerator(): void
    {
        self::assertLessThan(45, $this->autoDetect()->getPriority());
    }

    /**
     * TYPO3 14 verwirft Aspekte, die dem Konstruktor uebergeben werden — die
     * Sprache muss gesetzt werden, sonst prueft der Test eine andere.
     */
    private function defaultLanguageContext(): Context
    {
        $context = new Context();
        $context->setAspect('language', new LanguageAspect(0));

        return $context;
    }
}
