<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Domain\Contract\GlossaryTermRepositoryInterface;
use Koh\StructuredContent\Infrastructure\SchemaOrg\DefinedTermSchemaGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Der einzelne Glossarbegriff.
 *
 * Die Eigenschaft, die ihn traegt, ist die Zugehoerigkeit: `inDefinedTermSet`
 * verbindet ihn mit dem Glossar, zu dem er gehoert. Ohne sie ist jeder Begriff
 * eine freistehende Definition ohne Herkunft — und genau die Verbindung ist
 * das, was einen Glossareintrag von einem beliebigen Absatz unterscheidet.
 *
 * Die zweite ist die Rueckfallkette der Beschreibung: erst der gepflegte
 * Beschreibungstext, dann die Meta-Beschreibung, dann der Tooltip. Sie
 * existiert, weil ein Begriff ohne Definition ein `DefinedTerm` ist, das
 * nichts definiert — und weil die drei Felder in gewachsenen Glossaren
 * unterschiedlich gepflegt sind.
 */
final class DefinedTermSchemaGeneratorTest extends TestCase
{
    private bool $available = true;

    /** @var array<string, mixed>|null */
    private ?array $term = null;

    private string $description = '';

    /** @var list<array{int, int}> uid and language of every lookup by uid */
    private array $uidLookups = [];

    /** @var list<string> */
    private array $synonyms = [];

    private function generator(): DefinedTermSchemaGenerator
    {
        $repository = $this->createStub(GlossaryTermRepositoryInterface::class);
        $repository->method('isAvailable')->willReturnCallback(fn (): bool => $this->available);
        $repository->method('findTermBySlug')->willReturnCallback(fn (): ?array => $this->term);
        $repository->method('findTermByUid')->willReturnCallback(function (int $uid, int $languageId): ?array {
            $this->uidLookups[] = [$uid, $languageId];

            return $this->term;
        });
        $repository->method('findFirstDescription')->willReturnCallback(
            fn (): ?string => '' === $this->description ? null : $this->description,
        );
        $repository->method('findSynonymNames')->willReturnCallback(fn (): array => $this->synonyms);

        return new DefinedTermSchemaGenerator(new GlossaryTermService($repository));
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
            '_siteBase' => 'https://example.com/',
            '_glossaryBasePath' => '/glossar',
            '_languageId' => 0,
            '_routingArguments' => ['tx_dpnglossary_glossary' => ['term' => 'schema-org']],
            ...$overrides,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function termRow(array $overrides = []): array
    {
        return [
            'uid' => 1,
            'name' => 'Schema.org',
            'url_segment' => 'schema-org',
            'tooltiptext' => 'Ein Vokabular fuer strukturierte Daten.',
            'meta_description' => '',
            ...$overrides,
        ];
    }

    #[Test]
    public function aTermPageWithAKnownTermIsHandled(): void
    {
        $this->term = $this->termRow();

        self::assertTrue($this->generator()->canHandle($this->page(), []));
    }

    /**
     * Ohne Begriff in der Adresse ist es die Uebersichtsseite, nicht die
     * Detailseite — dort gehoert das Glossar hin, nicht ein einzelner Eintrag.
     */
    #[Test]
    public function aPageWithoutATermInItsAddressIsNotHandled(): void
    {
        $this->term = $this->termRow();

        self::assertFalse($this->generator()->canHandle($this->page(['_routingArguments' => []]), []));
        self::assertFalse($this->generator()->canHandle(['uid' => 42], []));
    }

    /**
     * Und ein Begriff, den es nicht gibt, ebenso wenig — sonst entstuende eine
     * Definition fuer ein Wort, das im Glossar nicht steht.
     */
    #[Test]
    public function aTermThatIsNotInTheGlossaryIsNotHandled(): void
    {
        self::assertFalse($this->generator()->canHandle($this->page(), []));
    }

    /**
     * Ohne die optionale Glossar-Extension gibt es gar nichts zu tun.
     */
    #[Test]
    public function withoutTheGlossaryExtensionNothingIsHandled(): void
    {
        $this->available = false;
        $this->term = $this->termRow();

        self::assertFalse($this->generator()->canHandle($this->page(), []));
    }

    /**
     * Die Eigenschaft, die den Begriff traegt: er gehoert zu einem Glossar.
     * Ohne diese Verbindung ist er eine freistehende Definition ohne Herkunft.
     */
    #[Test]
    public function theTermBelongsToItsGlossary(): void
    {
        $this->term = $this->termRow();

        $document = $this->generator()->generate($this->page(), []);

        self::assertSame('DefinedTerm', $document->schemaType);
        self::assertSame(
            ['@id' => 'https://example.com/glossar#termset'],
            $document->data['inDefinedTermSet'],
        );
        self::assertSame('https://example.com/glossar/schema-org#term', $document->data['@id']);
        self::assertSame('https://example.com/glossar/schema-org', $document->data['url']);
        self::assertSame('Schema.org', $document->data['name']);
    }

    /**
     * Mit dem PersistedAliasMapper des Glossary-Enhancers kommt die uid des
     * Originals an, nicht der Slug. Nach dem Slug gesucht, fand sich nie ein
     * Begriff — und keine Detailseite trug ein DefinedTerm. Die Adresse traegt
     * trotzdem den Slug, in der Sprache der Seite.
     */
    #[Test]
    public function aTermRoutedByUidIsFoundAndAddressedBySlug(): void
    {
        $this->term = $this->termRow(['uid' => 77, 'url_segment' => 'wolfi-os', 'name' => 'Wolfi OS']);
        $page = $this->page([
            '_languageId' => 1,
            '_siteBase' => 'https://example.com/en/',
            '_routingArguments' => ['tx_dpnglossary_glossary' => ['term' => '12']],
        ]);

        self::assertTrue($this->generator()->canHandle($page, []));
        $document = $this->generator()->generate($page, []);

        self::assertSame([12, 1], $this->uidLookups[0]);
        self::assertSame('https://example.com/en/glossar/wolfi-os', $document->data['url']);
        self::assertSame('Wolfi OS', $document->data['name']);
    }

    /**
     * Der Glossarpfad kommt von der Site, nicht aus einer Annahme — ein
     * Glossar unter /lexikon bekommt Adressen unter /lexikon.
     */
    #[Test]
    public function theGlossaryPathComesFromTheSite(): void
    {
        $this->term = $this->termRow();

        $document = $this->generator()->generate($this->page(['_glossaryBasePath' => '/lexikon']), []);

        self::assertSame('https://example.com/lexikon/schema-org', $document->data['url']);
    }

    /**
     * Die zweite Eigenschaft: die Rueckfallkette der Beschreibung. Ein
     * DefinedTerm ohne Definition definiert nichts.
     */
    #[Test]
    public function theDescriptionFallsBackThroughEveryMaintainedField(): void
    {
        $this->term = $this->termRow();

        $this->description = 'Der gepflegte Beschreibungstext.';
        self::assertSame(
            'Der gepflegte Beschreibungstext.',
            $this->generator()->generate($this->page(), [])->data['description'],
        );

        $this->description = '';
        $this->term = $this->termRow(['meta_description' => 'Die Meta-Beschreibung.']);
        self::assertSame(
            'Die Meta-Beschreibung.',
            $this->generator()->generate($this->page(), [])->data['description'],
        );

        $this->term = $this->termRow(['meta_description' => '']);
        self::assertSame(
            'Ein Vokabular fuer strukturierte Daten.',
            $this->generator()->generate($this->page(), [])->data['description'],
            'and finally the tooltip',
        );
    }

    /**
     * Ist keines der drei Felder gepflegt, bleibt die Beschreibung weg — statt
     * als leere Angabe zu erscheinen.
     */
    #[Test]
    public function aTermWithoutAnyDefinitionCarriesNone(): void
    {
        $this->term = $this->termRow(['tooltiptext' => '', 'meta_description' => '']);

        self::assertArrayNotHasKey('description', $this->generator()->generate($this->page(), [])->data);
    }

    /**
     * Synonyme erscheinen als alternative Namen: eines bleibt ein Wert,
     * mehrere werden zur Liste.
     */
    #[Test]
    public function theSynonymsBecomeAlternateNames(): void
    {
        $this->term = $this->termRow();

        $this->synonyms = ['Structured Data'];
        self::assertSame(
            'Structured Data',
            $this->generator()->generate($this->page(), [])->data['alternateName'],
        );

        $this->synonyms = ['Structured Data', 'Strukturierte Daten'];
        self::assertSame(
            ['Structured Data', 'Strukturierte Daten'],
            $this->generator()->generate($this->page(), [])->data['alternateName'],
        );
    }

    /**
     * Ein Begriff, der zwischen Erkennung und Erzeugung verschwindet, ergibt
     * ein leeres Dokument statt einer Ausnahme — die Middleware wirft es weg.
     */
    #[Test]
    public function aTermThatVanishedProducesAnEmptyDocument(): void
    {
        self::assertSame([], $this->generator()->generate($this->page(), [])->data);
    }
}
