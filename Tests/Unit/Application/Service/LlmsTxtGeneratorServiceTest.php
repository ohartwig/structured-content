<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\LlmsTxtGeneratorService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\StructuredContent\Application\Dto\SiteContext;
use Koh\StructuredContent\Domain\Contract\PageTreeRepositoryInterface;
use Koh\StructuredContent\Presentation\Factory\SiteContextFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die `llms.txt` — die Datei, aus der ein Sprachmodell erfaehrt, was auf
 * dieser Site steht.
 *
 * Sie wird von niemandem gelesen, der sich beschweren wuerde. Kein Browser
 * rendert sie, kein Validator prueft sie, und ein Fehler darin zeigt sich
 * erst, wenn Wochen spaeter eine Antwort in einem Chat auf eine Seite
 * verweist, die es nicht gibt — oder eine wichtige gar nicht erst nennt.
 * Deshalb sind hier die Auslassungen das Interessante:
 *
 * Nur sichtbare Seiten kommen hinein. Eine versteckte oder aus der Suche
 * ausgenommene Seite, die in der llms.txt steht, ist eine Empfehlung an jedes
 * Modell, genau diese Seite zu zitieren.
 *
 * Die Links sind absolut. Eine relative Zeile ist fuer einen Leser, der die
 * Datei ausserhalb der Site verarbeitet, schlicht unaufloesbar.
 *
 * Und die sprachspezifischen Texte fallen auf die Standardsprache zurueck,
 * statt leer zu bleiben — eine llms.txt ohne Einleitung ist eine Site ohne
 * Beschreibung.
 */
final class LlmsTxtGeneratorServiceTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $pages = [];

    private string $pageBody = '';

    /**
     * Pages that exist but are not visible: they are in the pid and sorting
     * maps, not in loadVisiblePages().
     *
     * @var list<array<string, mixed>>
     */
    private array $invisiblePages = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Der Dienst fragt den Zugriffs-Kontext nach der aktuellen Zeit, und
        // der echte Context liest sie aus dieser Globalen. Ohne sie faellt er
        // beim Bau ueber ein null-Timestamp.
        $GLOBALS['EXEC_TIME'] = 1785300000;
        $GLOBALS['SIM_EXEC_TIME'] = 1785300000;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['EXEC_TIME'], $GLOBALS['SIM_EXEC_TIME']);
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    /**
     * Der Dienst bekommt seinen Seitenbaum jetzt ueber einen Port.
     *
     * Was hier frueher stand, war der halbe Datenbankstapel: ein
     * QueryBuilder-Doppel mit acht verketteten Methoden, ein Result-Doppel, ein
     * Restriction-Container-Doppel und vierhundert vorab eingereihte Instanzen
     * fuer GeneralUtility::makeInstance(). Das alles war noetig, weil der Dienst
     * selbst abgefragt hat — und es hat den Test an die Formulierung der
     * Abfragen gebunden statt an ihr Ergebnis.
     *
     * Die Pruefung, dass die Abfragen die Sichtbarkeitsbeschraenkungen tragen,
     * ist nicht entfallen. Sie ist mit dem Code umgezogen, nach
     * Tests/Unit/Infrastructure/Persistence/PageTreeRepositoryTest.
     */
    private function service(): LlmsTxtGeneratorService
    {
        $pageTree = $this->createStub(PageTreeRepositoryInterface::class);
        $pageTree->method('loadVisiblePages')->willReturnCallback(fn (): array => $this->pages);
        $pageTree->method('loadPidMap')->willReturnCallback(
            fn (): array => array_column([...$this->pages, ...$this->invisiblePages], 'pid', 'uid'),
        );
        // In this test world the flag is a column on the rows — visible and
        // invisible alike — just as it is in the database.
        $pageTree->method('loadFeaturedPageUids')->willReturnCallback(
            fn (): array => array_values(array_map(
                static fn (array $page): int => (int) $page['uid'],
                array_filter(
                    [...$this->pages, ...$this->invisiblePages],
                    static fn (array $page): bool => 1 === (int) ($page['tx_structuredcontent_llms_featured'] ?? 0),
                ),
            )),
        );
        $pageTree->method('loadSortingMap')->willReturnCallback(
            fn (): array => array_column([...$this->pages, ...$this->invisiblePages], 'sorting', 'uid'),
        );
        // Der Baumdurchlauf ist Sache des Repositories und dort geprueft. Hier
        // wuerde ein "nein" jede Seite aus der Ausgabe nehmen und saemtliche
        // Erwartungen dieses Tests leerlaufen lassen.
        $pageTree->method('isPubliclyReachable')->willReturn(true);
        // Der Einzelabzug sucht dieselbe Zeilenquelle nach uid ab. Eine uid ohne
        // Zeile ergibt null — so faellt der oeffentliche Markdown-Abruf einer
        // unbekannten oder unsichtbaren Seite auf den leeren String zurueck.
        $pageTree->method('loadVisiblePage')->willReturnCallback(
            fn (int $pageUid): ?array => array_values(array_filter(
                $this->pages,
                static fn (array $page): bool => (int) $page['uid'] === $pageUid,
            ))[0] ?? null,
        );
        $pageTree->method('renderPageBody')->willReturnCallback(fn (): string => $this->pageBody);

        return new LlmsTxtGeneratorService($pageTree);
    }

    /**
     * @param array<string, mixed> $llmsSettings
     */
    private function siteObject(array $llmsSettings = [], string $base = 'https://example.com/'): Site
    {
        return new Site('example', 1, [
            'base' => $base,
            'websiteTitle' => 'Example GmbH',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/'],
            ],
            'settings' => ['structuredContent' => ['llmsTxt' => $llmsSettings]],
        ]);
    }

    /**
     * A site with the whole `structuredContent` block instead of llmsTxt only
     * — for the summary that falls back to the schema setting — and with the
     * `structuredContent` block of the English language configuration.
     *
     * @param array<string, mixed> $structuredContent
     * @param array<string, mixed> $englishStructuredContent
     */
    private function siteContextFrom(array $structuredContent, int $languageId = 0, array $englishStructuredContent = []): SiteContext
    {
        $english = ['languageId' => 1, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/en/'];
        if ([] !== $englishStructuredContent) {
            $english['structuredContent'] = $englishStructuredContent;
        }
        $site = new Site('example', 1, [
            'base' => 'https://example.com/',
            'websiteTitle' => 'Example GmbH',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
                $english,
            ],
            'settings' => ['structuredContent' => $structuredContent],
        ]);

        return (new SiteContextFactory())->create($site, $site->getLanguageById($languageId));
    }

    /**
     * A blog hub under the root: /blog (uid 20) with two year pages
     * (21 = 2025, 22 = 2026) and $count posts below them. The posts are
     * deliberately listed oldest first, so a test can tell whether they are
     * sorted by date rather than by tree order.
     *
     * @return list<array<string, mixed>>
     */
    private function blogWithYearPages(int $count): array
    {
        $pages = [
            $this->page(['uid' => 20, 'pid' => 1, 'title' => 'Blog', 'slug' => '/blog', 'description' => 'Alle Artikel.']),
            $this->page(['uid' => 21, 'pid' => 20, 'title' => '2025', 'slug' => '/blog/2025', 'description' => '']),
            $this->page(['uid' => 22, 'pid' => 20, 'title' => '2026', 'slug' => '/blog/2026', 'description' => '']),
        ];
        for ($i = 1; $i <= $count; ++$i) {
            $pages[] = $this->page([
                'uid' => 100 + $i,
                'pid' => $i <= intdiv($count, 2) ? 21 : 22,
                'title' => 'Beitrag ' . $i,
                'slug' => '/blog/beitrag-' . $i,
                'description' => '',
                'crdate' => 1700000000 + $i * 86400,
            ]);
        }

        return $pages;
    }

    /**
     * @param array<string, mixed> $llmsSettings
     */
    private function site(array $llmsSettings = [], string $base = 'https://example.com/'): SiteContext
    {
        return (new SiteContextFactory())->create($this->siteObject($llmsSettings, $base));
    }

    /**
     * Derselbe Auftritt in der englischen Sprachfassung.
     *
     * Frueher wurde die SiteLanguage als zweites Argument durchgereicht; die
     * Uebersetzung nach SiteContext macht daraus zwei verschiedene Kontexte,
     * und das ist ehrlicher — der Dienst kennt keine Sprachumschaltung mehr,
     * er bekommt fertig aufgeloeste Werte.
     *
     * @param array<string, mixed> $llmsSettings
     */
    private function siteEnglish(array $llmsSettings = [], string $base = 'https://example.com/'): SiteContext
    {
        $site = $this->siteObject($llmsSettings, $base);

        return (new SiteContextFactory())->create($site, $site->getLanguageById(1));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function page(array $overrides = []): array
    {
        return [
            'uid' => 10,
            'pid' => 1,
            // Die Uebersetzungsabfrage liest dieselbe Zeilenquelle wie die
            // Seitenabfrage, also traegt jede Zeile die Spalte, die dort
            // ausgewaehlt wird. 0 heisst: das ist kein Uebersetzungssatz.
            'l10n_parent' => 0,
            'title' => 'Leistungen',
            'slug' => '/leistungen',
            'description' => 'Was wir tun.',
            'doktype' => 1,
            'hidden' => 0,
            'nav_hide' => 0,
            'no_index' => 0,
            'sorting' => 256,
            ...$overrides,
        ];
    }

    #[Test]
    public function theFileStartsWithTheSiteTitleAsItsHeading(): void
    {
        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringStartsWith('# Example GmbH', $output);
    }

    /**
     * Die Einleitung steht als Blockzitat unter dem Titel — das ist die Form,
     * die die llms.txt-Konvention fuer die Kurzbeschreibung vorsieht.
     */
    #[Test]
    public function theTaglineIsQuotedUnderTheHeading(): void
    {
        $output = $this->service()->buildLlmsTxt($this->site([
            'tagline' => 'Wir bauen TYPO3-Systeme.',
            'intro' => 'Seit 2009 aus Monheim am Rhein.',
        ]));

        self::assertStringContainsString("\n> Wir bauen TYPO3-Systeme.", $output);
        self::assertStringContainsString("\nSeit 2009 aus Monheim am Rhein.", $output);
    }

    /**
     * Eine mehrzeilige Einleitung bleibt mehrzeilig, und jede Zeile des
     * Blockzitats behaelt ihr Zeichen — ohne das rendert nur die erste Zeile
     * als Zitat und der Rest kippt in den Fliesstext.
     */
    #[Test]
    public function aMultiLineTaglineKeepsItsQuoteMarkerOnEveryLine(): void
    {
        $output = $this->service()->buildLlmsTxt($this->site([
            'tagline' => "Erste Zeile.\r\nZweite Zeile.",
        ]));

        self::assertStringContainsString('> Erste Zeile.', $output);
        self::assertStringContainsString('> Zweite Zeile.', $output);
    }

    /**
     * Die Regression, auf die es ankommt: die Links sind absolut. Eine
     * relative Zeile ist fuer einen Leser, der die Datei ausserhalb der Site
     * verarbeitet, nicht aufloesbar — und genau dafuer ist sie da.
     */
    #[Test]
    public function everyLinkIsAbsolute(): void
    {
        $this->pages = [$this->page(), $this->page(['uid' => 11, 'title' => 'Kontakt', 'slug' => '/kontakt'])];

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringContainsString('https://example.com/leistungen', $output);
        self::assertStringContainsString('https://example.com/kontakt', $output);
        self::assertDoesNotMatchRegularExpression('/\]\(\/[a-z]/', $output, 'a relative link slipped in');
    }

    /**
     * Eine Seite wird als Markdown-Link mit ihrer Beschreibung ausgegeben —
     * die Beschreibung ist das, woran ein Modell entscheidet, ob die Seite
     * zur Frage passt.
     */
    #[Test]
    public function aPageIsListedWithItsDescription(): void
    {
        $this->pages = [$this->page()];

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringContainsString('[Leistungen](https://example.com/leistungen)', $output);
        self::assertStringContainsString('Was wir tun.', $output);
    }


    /**
     * Eine Seite ohne Slug ist die Startseite und wird als Basis-URL
     * verlinkt. Der Slug der Wurzelseite ist in TYPO3 leer — sie wegzulassen
     * hiesse, die wichtigste Seite der Site nicht zu nennen.
     */
    #[Test]
    public function aPageWithoutASlugIsTheSiteRoot(): void
    {
        $this->pages = [$this->page(['slug' => ''])];

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringContainsString('[Leistungen](https://example.com/)', $output);
    }

    /**
     * Eine Seite ohne Titel wird uebersprungen. Ein Link, dessen Text leer
     * ist, ist in Markdown eine Zeile, die nichts benennt.
     */
    #[Test]
    public function aPageWithoutATitleIsSkipped(): void
    {
        $this->pages = [
            $this->page(['title' => '   ']),
            $this->page(['uid' => 11, 'title' => 'Kontakt', 'slug' => '/kontakt']),
        ];

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringContainsString('Kontakt', $output);
        self::assertStringNotContainsString('](https://example.com/leistungen)', $output);
    }

    /**
     * Dieselbe Adresse steht nur einmal drin. Eine Seite, die ueber zwei Wege
     * in dieselbe Gruppe faellt, waere sonst ein doppelter Eintrag — und fuer
     * einen Leser ein Hinweis, dass sie doppelt so wichtig ist.
     */
    #[Test]
    public function thesameUrlIsListedOnlyOnce(): void
    {
        $this->pages = [$this->page(), $this->page(['uid' => 11, 'title' => 'Leistungen (Kopie)'])];

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertSame(1, substr_count($output, 'https://example.com/leistungen'));
    }

    /**
     * Kontaktangaben stehen in einem eigenen Abschnitt — und der Abschnitt
     * fehlt, wenn es nichts zu nennen gibt, statt als leere Ueberschrift
     * dazustehen.
     */
    #[Test]
    public function theContactSectionAppearsOnlyWhenThereIsSomethingInIt(): void
    {
        $withoutContact = $this->service()->buildLlmsTxt($this->site());
        self::assertStringNotContainsString('## Kontakt', $withoutContact);

        $withContact = $this->service()->buildLlmsTxt($this->site([
            'contactEmail' => 'hallo@example.com',
            'contactLocation' => 'Monheim am Rhein',
            'linkedinUrl' => 'https://linkedin.com/company/example',
            'githubUrl' => 'https://github.com/example',
        ]));

        self::assertStringContainsString('## Kontakt', $withContact);
        self::assertStringContainsString('E-Mail: hallo@example.com', $withContact);
        self::assertStringContainsString('Standort: Monheim am Rhein', $withContact);
        self::assertStringContainsString('LinkedIn: https://linkedin.com/company/example', $withContact);
        self::assertStringContainsString('GitHub: https://github.com/example', $withContact);
    }

    /**
     * Die dritte Regression: die Beschriftungen folgen der Sprache. Eine
     * englische llms.txt mit deutschen Ueberschriften liest sich fuer ein
     * Modell wie eine Datei, die zur Haelfte von einer anderen Site stammt.
     */
    #[Test]
    public function theLabelsFollowTheLanguage(): void
    {
        $settings = ['contactEmail' => 'hello@example.com'];

        $english = $this->service()->buildLlmsTxt($this->siteEnglish($settings));
        $german = $this->service()->buildLlmsTxt($this->site($settings));

        self::assertStringContainsString('## Kontakt', $german);
        self::assertStringContainsString('E-Mail:', $german);

        self::assertStringContainsString('## Contact', $english);
        self::assertStringContainsString('Email:', $english);
    }

    /**
     * Ein sprachspezifischer Text gewinnt gegen den Standard — und wenn es
     * keinen gibt, faellt es auf den Standard zurueck statt leer zu bleiben.
     * Eine llms.txt ohne Einleitung ist eine Site ohne Beschreibung.
     */
    #[Test]
    public function aLanguageSpecificTextWinsAndAMissingOneFallsBack(): void
    {
        $english = $this->service()->buildLlmsTxt($this->siteEnglish([
            'tagline' => 'Wir bauen TYPO3-Systeme.',
            'taglineEn' => 'We build TYPO3 systems.',
            'intro' => 'Seit 2009.',
        ]));

        self::assertStringContainsString('> We build TYPO3 systems.', $english);
        self::assertStringNotContainsString('Wir bauen TYPO3-Systeme.', $english);
        self::assertStringContainsString('Seit 2009.', $english, 'the intro had no translation and must not vanish');
    }

    /**
     * Die englische Ausgabe haengt an der Sprach-Base, nicht an der der Site
     * — sonst zeigen alle Links der englischen Datei auf die deutschen
     * Seiten.
     */
    #[Test]
    public function theEnglishFileLinksToTheEnglishBase(): void
    {
        $this->pages = [$this->page()];
        $output = $this->service()->buildLlmsTxt($this->siteEnglish());

        self::assertStringContainsString('https://example.com/en/leistungen', $output);
    }

    /**
     * Eigene Gruppenbeschriftungen ersetzen die Vorgaben. Sie sind das
     * einzige, was einem Leser sagt, wonach die Abschnitte sortiert sind.
     */
    #[Test]
    public function customGroupLabelsReplaceTheDefaults(): void
    {
        $this->pages = [$this->page()];

        $output = $this->service()->buildLlmsTxt($this->site([
            'mainGroupLabel' => 'Kernangebot',
            'contactGroupLabel' => 'So erreichen Sie uns',
            'contactEmail' => 'hallo@example.com',
        ]));

        self::assertStringContainsString('## Kernangebot', $output);
        self::assertStringContainsString('## So erreichen Sie uns', $output);
        self::assertStringNotContainsString('## Wichtige Seiten', $output);
    }

    /**
     * Eine Site ohne Seiten liefert trotzdem eine gueltige Datei — mit Titel
     * und ohne leere Abschnitte. Ein Abbruch hier waere ein 500er auf einer
     * Adresse, die Crawler regelmaessig abrufen.
     */
    #[Test]
    public function aSiteWithoutPagesStillProducesAValidFile(): void
    {
        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringStartsWith('# Example GmbH', $output);
        self::assertStringNotContainsString('## Wichtige Seiten', $output);
    }

    /**
     * Der Volltext-Abzug laesst sich abschalten. Er kann die ganze Site in
     * einer Antwort ausliefern, und das ist eine Entscheidung, die dem
     * Betreiber gehoert.
     */
    #[Test]
    public function theFullDumpCanBeSwitchedOff(): void
    {
        self::assertSame('', $this->service()->buildLlmsFullMd($this->site(['fullDumpEnabled' => false])));
        self::assertSame('', $this->service()->buildLlmsFullMd($this->site(['fullDumpEnabled' => 0])));
    }

    /**
     * Und er ist begrenzt. Eine Obergrenze von 0 oder weniger faellt auf die
     * Vorgabe zurueck, statt als "unbegrenzt" gelesen zu werden — sonst waere
     * eine versehentliche 0 in der Konfiguration ein Abzug der ganzen Site in
     * einer Antwort.
     */
    #[Test]
    public function aNonPositivePageLimitFallsBackToTheDefaultRatherThanMeaningUnlimited(): void
    {
        foreach ([0, -1] as $limit) {
            $output = $this->service()->buildLlmsFullMd($this->site(['fullDumpMaxPages' => $limit]));

            self::assertStringContainsString('Vollständiger Inhalt', $output, (string) $limit);
        }
    }


    /**
     * Der Markdown-Abzug einer einzelnen Seite: Titel, Adresse, Beschreibung,
     * Text. Er ist das, was ein Modell bekommt, wenn es der Adresse aus der
     * llms.txt folgt — die Adresse steht deshalb in spitzen Klammern, so wie
     * die Konvention es fuer eine maschinell lesbare Referenz vorsieht.
     */
    #[Test]
    public function aPageIsRenderedAsMarkdownWithItsAddress(): void
    {
        $this->pages = [$this->page()];

        $markdown = $this->service()->buildPageMarkdown($this->site(), 10);

        self::assertStringContainsString('# Leistungen', $markdown);
        self::assertStringContainsString('<https://example.com/leistungen>', $markdown);
        self::assertStringContainsString('> Was wir tun.', $markdown);
    }

    /**
     * Eine Seite, die keine Inhaltsseite ist — ein Ordner, ein Verweis —
     * ergibt kein Markdown. Ihr Inhalt ist nicht ihr Inhalt, und ein Modell
     * wuerde ihn der Adresse zuschreiben.
     */
    #[Test]
    public function aPageThatIsNoContentPageIsNotRendered(): void
    {
        foreach ([3, 4, 199, 254] as $doktype) {
            $this->pages = [$this->page(['doktype' => $doktype])];

            $markdown = $this->service()->buildPageMarkdown($this->site(), 10);

            if (in_array($doktype, [1, 2, 4], true)) {
                continue;
            }

            self::assertSame('', $markdown, 'doktype ' . $doktype);
        }
    }

    /**
     * Eine uid, die keinen Datensatz meint, wird nicht abgefragt.
     */
    #[Test]
    public function anImpossibleUidRendersNothing(): void
    {
        $this->pages = [$this->page()];

        self::assertSame('', $this->service()->buildPageMarkdown($this->site(), 0));
        self::assertSame('', $this->service()->buildPageMarkdown($this->site(), -1));
    }

    /**
     * Die Startseite hat keinen Slug; ihre Adresse ist die Basis mit
     * abschliessendem Schraegstrich, nicht die Basis mit einem angehaengten
     * Leerstring.
     */
    #[Test]
    public function theHomepageIsAddressedAsTheBaseItself(): void
    {
        $this->pages = [$this->page(['slug' => ''])];

        $markdown = $this->service()->buildPageMarkdown($this->site(), 10);

        self::assertStringContainsString('<https://example.com/>', $markdown);
    }

    /**
     * Und der Volltext-Abzug traegt eine Ueberschrift und einen Zeitstempel —
     * er ist eine Momentaufnahme, und ohne Datum weiss ein Leser nicht, wie
     * alt sie ist.
     */
    #[Test]
    public function theFullDumpSaysWhenItWasTaken(): void
    {
        $this->pages = [$this->page()];

        $markdown = $this->service()->buildLlmsFullMd($this->site());

        self::assertStringContainsString('# Example GmbH', $markdown);
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $markdown);
    }

    /**
     * Eine Seite, die es nicht gibt, ergibt kein Markdown — und keinen
     * Fehler. Die Adresse ist oeffentlich abrufbar, also ist eine unbekannte
     * uid der Normalfall, nicht die Ausnahme.
     */
    #[Test]
    public function anUnknownPageProducesNothingRatherThanAnError(): void
    {
        self::assertSame('', $this->service()->buildPageMarkdown($this->site(), 9999));
    }

    /**
     * Without a tagline there is still a summary under the title: the
     * organizationDescription that Organization and WebSite already carry in
     * the JSON-LD. An llms.txt without a blockquote misses the form the
     * convention prescribes for exactly this place.
     */
    #[Test]
    public function theOrganizationDescriptionIsTheSummaryWhenNoTaglineIsSet(): void
    {
        $output = $this->service()->buildLlmsTxt($this->siteContextFrom([
            'schema' => ['organizationDescription' => 'Beratung fuer sichere Plattformen.'],
            'llmsTxt' => ['tagline' => ''],
        ]));

        self::assertStringStartsWith("# Example GmbH\n\n> Beratung fuer sichere Plattformen.\n", $output);
    }

    /**
     * A tagline written on purpose wins — it was phrased for this file, the
     * organization description was not.
     */
    #[Test]
    public function aTaglineWinsOverTheOrganizationDescription(): void
    {
        $output = $this->service()->buildLlmsTxt($this->siteContextFrom([
            'schema' => ['organizationDescription' => 'Beratung fuer sichere Plattformen.'],
            'llmsTxt' => ['tagline' => 'Wir bauen TYPO3-Systeme.'],
        ]));

        self::assertStringContainsString('> Wir bauen TYPO3-Systeme.', $output);
        self::assertStringNotContainsString('Beratung fuer sichere Plattformen.', $output);
    }

    /**
     * The English file takes the English organization description from the
     * language configuration before falling back to the German tagline —
     * otherwise a German sentence would sit under an English title. An
     * English tagline still wins over both.
     */
    #[Test]
    public function theEnglishSummaryPrefersTheLanguagesOwnDescriptionOverTheGermanTagline(): void
    {
        $structuredContent = [
            'schema' => ['organizationDescription' => 'Beratung fuer sichere Plattformen.'],
            'llmsTxt' => ['tagline' => 'Wir bauen TYPO3-Systeme.'],
        ];
        $englishDescription = ['organizationDescription' => 'Consulting for secure platforms.'];

        $english = $this->service()->buildLlmsTxt($this->siteContextFrom($structuredContent, 1, $englishDescription));
        self::assertStringContainsString("\n> Consulting for secure platforms.\n", $english);
        self::assertStringNotContainsString('Wir bauen', $english);

        $structuredContent['llmsTxt']['taglineEn'] = 'We build TYPO3 systems.';
        $withTagline = $this->service()->buildLlmsTxt($this->siteContextFrom($structuredContent, 1, $englishDescription));
        self::assertStringContainsString("\n> We build TYPO3 systems.\n", $withTagline);
        self::assertStringNotContainsString('Consulting for secure platforms.', $withTagline);
    }

    /**
     * The flagged pages form the first section after the intro, in page-tree
     * order: depth-first by `sorting`, not by the flat `sorting` of the query,
     * which means nothing across different parents. Subpage 31 has the
     * smallest sorting but sits under hub 30 — which comes after page 11.
     */
    #[Test]
    public function flaggedPagesComeFirstInPageTreeOrder(): void
    {
        $this->pages = [
            $this->page(['sorting' => 256, 'tx_structuredcontent_llms_featured' => 1]),
            $this->page(['uid' => 31, 'pid' => 30, 'title' => 'Grundschutz', 'slug' => '/serie/grundschutz', 'description' => '', 'sorting' => 10, 'tx_structuredcontent_llms_featured' => 1]),
            $this->page(['uid' => 30, 'title' => 'Serie', 'slug' => '/serie', 'description' => '', 'sorting' => 100]),
            $this->page(['uid' => 11, 'title' => 'Glossar', 'slug' => '/glossar', 'description' => 'Begriffe.', 'sorting' => 50, 'tx_structuredcontent_llms_featured' => 1]),
        ];

        $output = $this->service()->buildLlmsTxt($this->site());

        $featured = strpos($output, '## Empfohlene Seiten');
        self::assertIsInt($featured);
        self::assertLessThan(strpos($output, '## Serie'), $featured);
        self::assertStringContainsString(
            "## Empfohlene Seiten\n\n- [Glossar](https://example.com/glossar): Begriffe.\n- [Grundschutz](https://example.com/serie/grundschutz)\n- [Leistungen](https://example.com/leistungen): Was wir tun.\n\n",
            $output,
        );
    }

    /**
     * A flagged page that is not visible — hidden, nav-hidden, outside the
     * site — is left out: visible pages are the only candidates. It exists
     * only in the pid map, like a hidden record.
     */
    #[Test]
    public function anInvisibleFlaggedPageIsSkipped(): void
    {
        $this->pages = [$this->page(['tx_structuredcontent_llms_featured' => 1])];
        $this->invisiblePages = [
            $this->page(['uid' => 12, 'title' => 'Entwurf', 'slug' => '/entwurf', 'tx_structuredcontent_llms_featured' => 1]),
        ];

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringContainsString("## Empfohlene Seiten\n\n- [Leistungen]", $output);
        self::assertStringNotContainsString('Entwurf', $output);
    }

    /**
     * Without a flagged page there is no section rather than an empty
     * heading — even when there are pages.
     *
     * This is also what the file looks like when the flag column does not
     * exist yet: the repository then returns no flagged uids (see
     * PageTreeRepositoryTest::aMissingFeaturedColumnYieldsNoFeaturedPagesInsteadOfAnException).
     */
    #[Test]
    public function noFlaggedPageMeansNoFeaturedSection(): void
    {
        $this->pages = [
            $this->page(),
            $this->page(['uid' => 11, 'title' => 'Glossar', 'slug' => '/glossar', 'tx_structuredcontent_llms_featured' => 0]),
        ];

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertStringNotContainsString('## Empfohlene Seiten', $output);
        self::assertStringContainsString('## Wichtige Seiten', $output);
    }

    /**
     * The section is capped: at 15 without a setting, otherwise at
     * featuredLimit — the first ones in tree order stay. 0 falls back to the
     * default instead of making the section unlimited.
     */
    #[Test]
    public function theFeaturedLimitApplies(): void
    {
        for ($i = 1; $i <= 20; ++$i) {
            $this->pages[] = $this->page([
                'uid' => 100 + $i,
                'title' => 'Seite ' . $i,
                'slug' => '/seite-' . $i,
                'description' => '',
                'sorting' => $i * 10,
                'tx_structuredcontent_llms_featured' => 1,
            ]);
        }
        $featuredSection = static function (string $output): string {
            $start = (int) strpos($output, '## Empfohlene Seiten');

            return substr($output, $start, (int) strpos($output, '## Wichtige Seiten') - $start);
        };

        $default = $featuredSection($this->service()->buildLlmsTxt($this->site()));
        self::assertSame(15, preg_match_all('/^- \[Seite \d+\]/m', $default));
        self::assertStringContainsString('[Seite 15]', $default);
        self::assertStringNotContainsString('[Seite 16]', $default);

        $three = $featuredSection($this->service()->buildLlmsTxt($this->site(['featuredLimit' => 3])));
        self::assertSame("## Empfohlene Seiten\n\n- [Seite 1](https://example.com/seite-1)\n- [Seite 2](https://example.com/seite-2)\n- [Seite 3](https://example.com/seite-3)\n\n", $three);

        $zero = $featuredSection($this->service()->buildLlmsTxt($this->site(['featuredLimit' => 0])));
        self::assertSame(15, preg_match_all('/^- \[Seite \d+\]/m', $zero));
    }

    /**
     * The English version features the same pages — the flag sits on the
     * default-language row — labels the section in English and links the
     * English URLs. A per-language label wins.
     */
    #[Test]
    public function theFeaturedSectionFollowsTheLanguage(): void
    {
        $this->pages = [$this->page(['tx_structuredcontent_llms_featured' => 1])];

        $english = $this->service()->buildLlmsTxt($this->siteEnglish());
        self::assertStringContainsString("## Featured Pages\n\n- [Leistungen](https://example.com/en/leistungen)", $english);

        $custom = $this->service()->buildLlmsTxt($this->siteEnglish([
            'featuredGroupLabel' => 'Im Fokus',
            'featuredGroupLabelEn' => 'Start here',
        ]));
        self::assertStringContainsString('## Start here', $custom);
    }

    /**
     * The SEO audit finding: 180 posts in one flat list. Above the limit the
     * newest posts by creation date stay, followed by the year pages that
     * lead to the rest. Without a setting the limit is 30.
     */
    #[Test]
    public function aLongPostListKeepsTheNewestPostsAndLinksTheYearPages(): void
    {
        $this->pages = $this->blogWithYearPages(40);

        $output = $this->service()->buildLlmsTxt($this->site());

        self::assertSame(30, preg_match_all('/^- \[Beitrag \d+\]/m', $output));
        self::assertStringContainsString('[Beitrag 40]', $output);
        self::assertStringContainsString('[Beitrag 11]', $output);
        self::assertStringNotContainsString('[Beitrag 10]', $output);
        self::assertLessThan(strpos($output, '[Beitrag 39]'), strpos($output, '[Beitrag 40]'), 'newest first');
        self::assertStringContainsString("- [Beitrag 11](https://example.com/blog/beitrag-11)\n- [2025](https://example.com/blog/2025)\n- [2026](https://example.com/blog/2026)\n", $output);
    }

    /**
     * The limit is configurable, and 0 switches it off: the section is then
     * complete again and in tree order.
     */
    #[Test]
    public function thePostListLimitIsConfigurableAndZeroSwitchesItOff(): void
    {
        $this->pages = $this->blogWithYearPages(12);

        $capped = $this->service()->buildLlmsTxt($this->site(['postListLimit' => 5]));
        self::assertSame(5, preg_match_all('/^- \[Beitrag \d+\]/m', $capped));
        self::assertStringContainsString('[Beitrag 8]', $capped);
        self::assertStringNotContainsString('[Beitrag 7]', $capped);

        $uncapped = $this->service()->buildLlmsTxt($this->site(['postListLimit' => 0]));
        self::assertSame(12, preg_match_all('/^- \[Beitrag \d+\]/m', $uncapped));
        self::assertLessThan(strpos($uncapped, '[Beitrag 2]'), strpos($uncapped, '[Beitrag 1]'), 'tree order is kept');
    }

    /**
     * Below the limit nothing changes, not even the order — a services hub
     * with eight pages is not a post list.
     */
    #[Test]
    public function aSectionBelowTheLimitIsLeftAsItWas(): void
    {
        $this->pages = $this->blogWithYearPages(6);

        $output = $this->service()->buildLlmsTxt($this->site(['postListLimit' => 10]));

        self::assertSame(6, preg_match_all('/^- \[Beitrag \d+\]/m', $output));
        self::assertLessThan(strpos($output, '[Beitrag 6]'), strpos($output, '[Beitrag 1]'));
        self::assertStringNotContainsString('Alle Beiträge', $output);
    }

    /**
     * A capped hub without year or index pages — the posts sit directly
     * below it or in nav-hidden folders — links to itself as the archive.
     * Otherwise the cut posts could no longer be reached from the file.
     */
    #[Test]
    public function aCappedHubWithoutIndexPagesLinksToItselfAsTheArchive(): void
    {
        $this->pages = [$this->page(['uid' => 20, 'pid' => 1, 'title' => 'Blog', 'slug' => '/blog', 'description' => 'Alle Artikel.'])];
        for ($i = 1; $i <= 4; ++$i) {
            $this->pages[] = $this->page(['uid' => 100 + $i, 'pid' => 20, 'title' => 'Beitrag ' . $i, 'slug' => '/blog/b' . $i, 'crdate' => $i]);
        }

        $german = $this->service()->buildLlmsTxt($this->site(['postListLimit' => 2]));
        self::assertStringContainsString("## Blog\n\n- [Beitrag 4](https://example.com/blog/b4): Was wir tun.\n- [Beitrag 3](https://example.com/blog/b3): Was wir tun.\n- [Alle Beiträge](https://example.com/blog): Alle Artikel.\n", $german);

        $english = $this->service()->buildLlmsTxt($this->siteEnglish(['postListLimit' => 2]));
        self::assertStringContainsString('- [All posts](https://example.com/en/blog)', $english);
    }
}
