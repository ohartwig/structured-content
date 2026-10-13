<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Command;

use Koh\StructuredContent\Infrastructure\Command\ExportRelationsCommand;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Der Export der Beziehungen — die einzige Stelle, an der das Wissen ueber
 * Inhaltsbeziehungen die Installation verlaesst.
 *
 * Die interessante Eigenschaft ist die Ausgabe selbst, weil sie von etwas
 * gelesen wird, das nicht nachfragen kann: eine Tabellenkalkulation oder ein
 * Graph-Werkzeug. Ein Anfuehrungszeichen in einem Tabellennamen bricht die
 * CSV-Zeile, ein `&` bricht das XML — und in beiden Faellen liest das
 * Zielwerkzeug den Rest der Datei falsch, ohne dass irgendetwas nach einem
 * Fehler aussieht.
 *
 * Die zweite Eigenschaft ist die Kanten-Identitaet im Graphen: jede Kante
 * braucht eine eigene id. Zwei Kanten mit derselben sind fuer jedes
 * Graph-Werkzeug eine Kante, und die Haelfte des Graphen fehlt.
 */
final class ExportRelationsCommandTest extends TestCase
{
    use PodcastFeedDoubles;

    /**
     * @param array<string, string> $options
     */
    private function export(array $options = []): CommandTester
    {
        $tester = new CommandTester(new ExportRelationsCommand($this->connectionPool()));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function relation(array $overrides = []): array
    {
        return [
            'source_table' => 'pages',
            'source_uid' => 42,
            'target_table' => 'pages',
            'target_uid' => 43,
            'relation_type' => 'explains',
            'direction' => 'unidirectional',
            'confidence' => '0.8',
            'created_by' => 'editor',
            ...$overrides,
        ];
    }

    /**
     * Ein Format, das es nicht gibt, ist ein Fehler und kein stiller
     * Rueckfall auf CSV — ein Skript, das GraphML erwartet und CSV bekommt,
     * merkt es erst beim Einlesen.
     */
    #[Test]
    public function anUnknownFormatIsRefusedRatherThanQuietlyReplaced(): void
    {
        $tester = $this->export(['--format' => 'json']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unsupported format', $tester->getDisplay());
    }

    /**
     * Ohne Beziehungen ist der Export leer und erfolgreich — nichts zu
     * exportieren ist kein Fehlschlag.
     */
    #[Test]
    public function anInstallationWithoutRelationsExportsNothingAndSucceeds(): void
    {
        $tester = $this->export();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('No relations found', $tester->getDisplay());
    }

    #[Test]
    public function theCsvCarriesAHeaderAndOneLinePerRelation(): void
    {
        $this->rows = [$this->relation(), $this->relation(['target_uid' => 44])];

        $display = $this->export()->getDisplay();

        self::assertStringContainsString('source_table,source_uid,target_table', $display);
        self::assertStringContainsString('pages,42,pages,43,explains,unidirectional,0.8,editor', $display);
        self::assertStringContainsString('pages,42,pages,44', $display);
    }

    /**
     * Die erste Eigenschaft: ein Anfuehrungszeichen im Wert bricht die Zeile
     * nicht. CSV wird von einer Tabellenkalkulation gelesen, die nicht
     * nachfragt — eine gebrochene Zeile verschiebt alle folgenden Spalten.
     */
    #[Test]
    public function aQuoteInAValueDoesNotBreakTheLine(): void
    {
        $this->rows = [$this->relation(['created_by' => 'der "Redakteur", nicht ich'])];

        $display = $this->export()->getDisplay();

        self::assertStringContainsString('"der ""Redakteur"", nicht ich"', $display);
    }

    /**
     * Und ein Backslash bleibt ein Backslash. PHPs historische
     * CSV-Voreinstellung behandelt ihn als zweites Escape-Zeichen und frisst
     * ihn dabei — ein Windows-Pfad oder ein regulaerer Ausdruck in einem Feld
     * kaeme veraendert wieder heraus.
     */
    #[Test]
    public function aBackslashSurvivesTheExport(): void
    {
        $this->rows = [$this->relation(['created_by' => 'import\\batch-7'])];

        self::assertStringContainsString('import\\batch-7', $this->export()->getDisplay());
    }

    #[Test]
    public function theGraphmlIsWellFormedXml(): void
    {
        $this->rows = [$this->relation()];

        $display = $this->export(['--format' => 'graphml'])->getDisplay();

        self::assertStringContainsString('<graphml', $display);
        self::assertStringContainsString('<node id="pages_42"/>', $display);
        self::assertStringContainsString('<node id="pages_43"/>', $display);
        self::assertStringContainsString('source="pages_42"', $display);
        self::assertStringContainsString('target="pages_43"', $display);
    }

    /**
     * Und dieselbe Eigenschaft im XML: ein `&` oder ein `<` in einem
     * Tabellennamen wird maskiert, sonst ist die Datei kein XML mehr und das
     * Graph-Werkzeug liest sie gar nicht erst ein.
     */
    #[Test]
    public function aSpecialCharacterInANodeNameIsEscaped(): void
    {
        $this->rows = [$this->relation(['source_table' => 'tx_a&b', 'target_table' => 'tx_c<d'])];

        $display = $this->export(['--format' => 'graphml'])->getDisplay();

        self::assertStringContainsString('tx_a&amp;b', $display);
        self::assertStringContainsString('tx_c&lt;d', $display);
        self::assertStringNotContainsString('id="tx_a&b', $display);
    }

    /**
     * Ein Knoten erscheint einmal, auch wenn viele Kanten auf ihn zeigen —
     * doppelte Knoten sind in GraphML ein Fehler, kein Duplikat.
     */
    #[Test]
    public function aNodeAppearsOnceHoweverManyEdgesTouchIt(): void
    {
        $this->rows = [
            $this->relation(['target_uid' => 43]),
            $this->relation(['target_uid' => 44]),
            $this->relation(['target_uid' => 45]),
        ];

        $display = $this->export(['--format' => 'graphml'])->getDisplay();

        self::assertSame(1, substr_count($display, '<node id="pages_42"/>'));
    }

    /**
     * Die zweite Eigenschaft: jede Kante bekommt eine eigene id. Zwei Kanten
     * mit derselben sind fuer jedes Graph-Werkzeug eine Kante — die Haelfte
     * des Graphen fehlt dann, ohne Fehlermeldung.
     */
    #[Test]
    public function everyEdgeHasAnIdentityOfItsOwn(): void
    {
        $this->rows = [
            $this->relation(['target_uid' => 43]),
            $this->relation(['target_uid' => 44]),
            $this->relation(['target_uid' => 45]),
        ];

        $display = $this->export(['--format' => 'graphml'])->getDisplay();

        preg_match_all('/<edge id="([^"]+)"/', $display, $matches);

        self::assertCount(3, $matches[1]);
        self::assertSame($matches[1], array_unique($matches[1]));
    }

    /**
     * Mit Zieldatei landet die Ausgabe dort und nicht auf der Konsole —
     * andernfalls schriebe ein Export in eine Datei zusaetzlich den ganzen
     * Inhalt ins Terminal.
     */
    #[Test]
    public function anOutputFileGetsTheContentInsteadOfTheTerminal(): void
    {
        $this->rows = [$this->relation()];
        $path = sys_get_temp_dir() . '/structured-content-export-' . bin2hex(random_bytes(4)) . '.csv';

        try {
            $display = $this->export(['--output' => $path])->getDisplay();

            self::assertFileExists($path);
            self::assertStringContainsString('pages,42,pages,43', (string) file_get_contents($path));
            self::assertStringContainsString('Exported 1 relations', $display);
            self::assertStringNotContainsString('source_table,source_uid', $display, 'the content stayed out of the terminal');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    #[Test]
    public function theCommandKeepsItsName(): void
    {
        self::assertSame(
            'structured-content:export-relations',
            (new ExportRelationsCommand($this->connectionPool()))->getName(),
        );
    }
}
