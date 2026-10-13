<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Domain\Contract\GlossaryTermRepositoryInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Die Schicht ueber dem Glossar-Repository — und der Grund, warum es sie gibt,
 * ist das Merken.
 *
 * Der Begriffs-Nachschlag laeuft im Seitenaufbau pro erkanntem Begriff einmal,
 * und auf einer Seite mit dreissig verlinkten Begriffen sind das dreissig
 * Abfragen fuer eine Handvoll verschiedener Woerter. Gemerkt wird deshalb auch
 * das *negative* Ergebnis: ein Begriff, den es nicht gibt, wird sonst auf
 * jeder Seite erneut gesucht, und "nicht gefunden" ist das haeufigste
 * Ergebnis, wenn der Text mehr Woerter enthaelt als das Glossar.
 *
 * Die zweite Eigenschaft ist die Vorbedingung: ohne die optionale
 * dpn_glossary-Extension antwortet jede Methode mit dem leeren Ergebnis und
 * fragt das Repository gar nicht erst. Der Basispfad faellt dabei auf
 * `/glossar` zurueck statt auf einen leeren String — ein leerer Pfad ergaebe
 * Links, die auf die Startseite zeigen.
 */
final class GlossaryTermServiceTest extends TestCase
{
    private bool $available = true;

    private int $termLookups = 0;

    private int $listLookups = 0;

    /** @var array<string, array<string, mixed>|null> */
    private array $terms = [];

    private function service(): GlossaryTermService
    {
        $repository = $this->createStub(GlossaryTermRepositoryInterface::class);
        $repository->method('isAvailable')->willReturnCallback(fn (): bool => $this->available);
        $repository->method('findTermBySlug')->willReturnCallback(
            function (string $slug): ?array {
                ++$this->termLookups;

                return $this->terms[$slug] ?? null;
            },
        );
        $repository->method('findTermByUid')->willReturnCallback(
            function (int $uid): ?array {
                ++$this->termLookups;

                foreach ($this->terms as $term) {
                    if (null !== $term && $uid === ($term['uid'] ?? null)) {
                        return $term;
                    }
                }

                return null;
            },
        );
        $repository->method('findAllTerms')->willReturnCallback(function (): array {
            ++$this->listLookups;

            return array_values(array_filter($this->terms));
        });
        $repository->method('findFirstDescription')->willReturn('<p>Ein Vokabular fuer <b>strukturierte</b> Daten.</p>');
        $repository->method('findSynonymNames')->willReturn(['  Structured Data ', '', '   ', 'Strukturierte Daten']);
        $repository->method('findPageSlug')->willReturn('/lexikon');

        return new GlossaryTermService($repository);
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
            '_siteSettings' => ['dpn-glossary' => ['glossaryPage' => 42]],
            ...$overrides,
        ];
    }

    /**
     * Die Eigenschaft, um derentwillen es diese Schicht gibt: derselbe Begriff
     * wird einmal nachgeschlagen. Auf einer Seite mit dreissig verlinkten
     * Begriffen sind das sonst dreissig Abfragen fuer eine Handvoll Woerter.
     */
    #[Test]
    public function thesameTermIsLookedUpOnce(): void
    {
        $this->terms['schema-org'] = ['uid' => 1, 'name' => 'Schema.org'];
        $service = $this->service();

        $service->findTermBySlug('schema-org');
        $service->findTermBySlug('schema-org');
        $service->findTermBySlug('schema-org');

        self::assertSame(1, $this->termLookups);
    }

    /**
     * Und das gilt fuer das negative Ergebnis genauso — "nicht gefunden" ist
     * das haeufigste Ergebnis, wenn der Text mehr Woerter enthaelt als das
     * Glossar.
     */
    #[Test]
    public function aTermThatDoesNotExistIsAlsoOnlyLookedUpOnce(): void
    {
        $service = $this->service();

        self::assertNull($service->findTermBySlug('gibt-es-nicht'));
        self::assertNull($service->findTermBySlug('gibt-es-nicht'));

        self::assertSame(1, $this->termLookups);
    }

    /**
     * Verschiedene Sprachen sind verschiedene Begriffe — sonst bekaeme die
     * englische Seite die deutsche Erklaerung, je nachdem welche zuerst
     * angefragt wurde.
     */
    #[Test]
    public function eachLanguageIsRememberedSeparately(): void
    {
        $this->terms['schema-org'] = ['uid' => 1, 'name' => 'Schema.org'];
        $service = $this->service();

        $service->findTermBySlug('schema-org', 0);
        $service->findTermBySlug('schema-org', 1);

        self::assertSame(2, $this->termLookups);
    }

    #[Test]
    public function theTermListIsAlsoRemembered(): void
    {
        $service = $this->service();

        $service->findAllTerms();
        $service->findAllTerms();

        self::assertSame(1, $this->listLookups);
    }

    /**
     * Die Vorbedingung: ohne die Extension antwortet jede Methode mit dem
     * leeren Ergebnis und fragt das Repository gar nicht.
     */
    #[Test]
    public function withoutTheExtensionNothingIsAsked(): void
    {
        $this->available = false;
        $this->terms['schema-org'] = ['uid' => 1, 'name' => 'Schema.org'];

        $service = $this->service();

        self::assertFalse($service->isAvailable());
        self::assertNull($service->findTermBySlug('schema-org'));
        self::assertSame('', $service->loadFirstDescription(1));
        self::assertSame([], $service->loadSynonyms(1));
        self::assertSame([], $service->findAllTerms());
        self::assertFalse($service->isGlossaryListingPage($this->page()));

        self::assertSame(0, $this->termLookups);
        self::assertSame(0, $this->listLookups);
    }

    /**
     * Der Basispfad faellt auf `/glossar` zurueck statt auf einen leeren
     * String — ein leerer Pfad ergaebe Links, die auf die Startseite zeigen.
     */
    #[Test]
    public function theBasePathFallsBackToSomethingUsable(): void
    {
        $this->available = false;
        self::assertSame('/glossar', $this->service()->resolveGlossaryBasePath($this->page()));

        $this->available = true;
        self::assertSame('/glossar', $this->service()->resolveGlossaryBasePath(['uid' => 42]), 'no settings at all');
        self::assertSame(
            '/glossar',
            $this->service()->resolveGlossaryBasePath($this->page(['_siteSettings' => []])),
            'settings without a glossary page',
        );
    }

    /**
     * Mit konfigurierter Detailseite kommt deren Slug — das ist, woran die
     * Begriffslinks haengen.
     */
    #[Test]
    public function aConfiguredGlossaryPageProvidesTheBasePath(): void
    {
        self::assertSame('/lexikon', $this->service()->resolveGlossaryBasePath($this->page()));
    }

    /**
     * Beide Schreibweisen der Einstellung werden gelesen — die verschachtelte
     * und die mit Punkt. TYPO3 liefert je nach Herkunft die eine oder die
     * andere, und eine Site, die nur die andere pflegt, bekaeme sonst keine
     * Glossarlinks.
     */
    #[Test]
    public function bothSpellingsOfTheSettingAreRead(): void
    {
        foreach ([
            'nested' => ['dpn-glossary' => ['glossaryPage' => 42]],
            'dotted' => ['dpn-glossary.glossaryPage' => 42],
        ] as $why => $settings) {
            self::assertSame(
                '/lexikon',
                $this->service()->resolveGlossaryBasePath($this->page(['_siteSettings' => $settings])),
                $why,
            );
        }
    }

    /**
     * Die Uebersichtsseite erkennt sich daran, dass sie die konfigurierte
     * Detailseite *ist* — auf jeder anderen Seite waere ein Glossar-Verzeichnis
     * eine zweite Haupt-Entitaet.
     */
    #[Test]
    public function onlyTheConfiguredPageIsTheGlossaryListing(): void
    {
        self::assertTrue($this->service()->isGlossaryListingPage($this->page()));
        self::assertFalse($this->service()->isGlossaryListingPage($this->page(['uid' => 43])));
        self::assertFalse($this->service()->isGlossaryListingPage(['uid' => 42]));
    }

    /**
     * Die Erklaerung kommt als Text, nicht als Auszeichnung: sie landet in
     * einem JSON-LD-Feld, und `<b>` erscheint dort als Text.
     */
    #[Test]
    public function theDescriptionComesBackAsPlainText(): void
    {
        $description = $this->service()->loadFirstDescription(1);

        self::assertSame('Ein Vokabular fuer strukturierte Daten.', $description);
    }

    /**
     * Und die Synonyme ohne Leerstellen und ohne leere Eintraege — ein leeres
     * `alternateName` ist ein Name, den niemand benutzt.
     */
    #[Test]
    public function theSynonymsComeBackTrimmedAndWithoutBlanks(): void
    {
        self::assertSame(
            ['Structured Data', 'Strukturierte Daten'],
            $this->service()->loadSynonyms(1),
        );
    }

    /**
     * Eine uid, die keinen Datensatz meint, wird nicht nachgeschlagen.
     */
    #[Test]
    public function anImpossibleTermUidIsNotLookedUp(): void
    {
        $service = $this->service();

        foreach ([0, -1] as $uid) {
            self::assertSame('', $service->loadFirstDescription($uid), (string) $uid);
            self::assertSame([], $service->loadSynonyms($uid), (string) $uid);
        }
    }

    #[Test]
    public function anEmptySlugIsNoSlug(): void
    {
        self::assertNull($this->service()->findTermBySlug(''));
        self::assertSame(0, $this->termLookups);
    }

    /**
     * Eine Detailadresse traegt je nach Enhancer den Slug oder die uid des
     * Originals. Beide fuehren zum Begriff, und beide werden nur einmal pro
     * Anfrage nachgeschlagen.
     */
    #[Test]
    public function aTermIsFoundBySlugOrByUidAndLookedUpOnce(): void
    {
        $this->terms = ['schema-org' => ['uid' => 7, 'name' => 'Schema.org', 'url_segment' => 'schema-org']];
        $service = $this->service();

        self::assertSame('Schema.org', $service->findTerm('schema-org')['name'] ?? null);
        self::assertSame('Schema.org', $service->findTerm('7')['name'] ?? null);
        $service->findTerm('7');
        self::assertNull($service->findTerm('8'));

        self::assertSame(3, $this->termLookups, 'slug, uid 7 and uid 8 once each');
    }

    #[Test]
    public function withoutTheExtensionNoTermIsLookedUpByUid(): void
    {
        $this->available = false;

        self::assertNull($this->service()->findTerm('7'));
        self::assertSame(0, $this->termLookups);
    }
}
