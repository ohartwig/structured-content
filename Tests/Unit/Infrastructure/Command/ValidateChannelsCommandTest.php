<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Command;

use Koh\StructuredContent\Infrastructure\Command\ValidateChannelsCommand;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Der Befehl, der die gepflegten Kanaele gegen die gueltigen prueft.
 *
 * Die Kanalliste ist ein Freitextfeld mit CSV darin — genau die Sorte Feld, in
 * der ein Tippfehler nichts kaputtmacht und trotzdem wirkt: der Inhalt wird
 * dann fuer einen Kanal ausgeliefert, den es nicht gibt, also fuer keinen. Kein
 * Fehler, keine Meldung, die Seite erscheint in einem Kanal einfach nicht.
 *
 * Deshalb existiert dieser Befehl, und deshalb ist seine wichtigste
 * Eigenschaft der Rueckgabewert: bei Funden `FAILURE`. Er laeuft in einer
 * Pipeline oder in einem Cron, und dort ist der Exit-Code das Einzige, was
 * gelesen wird — ein Befehl, der Fehler auflistet und trotzdem 0 zurueckgibt,
 * ist ein Bericht, den niemand sieht.
 *
 * Die zweite Eigenschaft ist, was er *nicht* meldet: Leerraum um ein Komma und
 * ein leerer Eintrag aus einer handgepflegten Liste sind kein ungueltiger
 * Kanal. Meldete er sie, waere die Liste voller Rauschen und die echten Funde
 * darin nicht mehr zu sehen.
 */
final class ValidateChannelsCommandTest extends TestCase
{
    use PodcastFeedDoubles;

    private function validate(): CommandTester
    {
        $tester = new CommandTester(new ValidateChannelsCommand($this->connectionPool()));
        $tester->execute([]);

        return $tester;
    }

    #[Test]
    public function anInstallationWithoutMaintainedChannelsIsValid(): void
    {
        $tester = $this->validate();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('valid', $tester->getDisplay());
    }

    #[Test]
    public function everyKnownChannelPasses(): void
    {
        $this->rows = [[
            'uid' => 42,
            'ai_context_channels' => 'web_chat,whatsapp,phone,email,social,mcp_public,mcp_internal,pdf',
        ]];

        self::assertSame(Command::SUCCESS, $this->validate()->getStatusCode());
    }

    /**
     * Die Eigenschaft, auf die es ankommt: ein Fund ist ein Fehlschlag. Der
     * Befehl laeuft in einer Pipeline, und dort ist der Exit-Code das
     * Einzige, was gelesen wird.
     */
    #[Test]
    public function aTypoIsAFailureAndNotJustAReport(): void
    {
        $this->rows = [['uid' => 42, 'ai_context_channels' => 'web_chat,whatsap']];

        $tester = $this->validate();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('whatsap', $tester->getDisplay());
    }

    /**
     * Und der Fund sagt, wo er steckt — Tabelle und uid. Ein Bericht, der nur
     * "irgendwo ist ein falscher Kanal" sagt, laesst den Leser suchen.
     */
    #[Test]
    public function theFindingSaysWhereItIs(): void
    {
        $this->rows = [['uid' => 4711, 'ai_context_channels' => 'telepathie']];

        $display = $this->validate()->getDisplay();

        self::assertStringContainsString('4711', $display);
        self::assertStringContainsString('telepathie', $display);
        self::assertStringContainsString('pages', $display);
    }

    /**
     * Die zweite Eigenschaft: Leerraum und leere Eintraege sind kein Fund. Eine
     * handgepflegte CSV sieht so aus, und ein Bericht voller Rauschen versteckt
     * die echten Funde.
     */
    #[Test]
    public function whitespaceAndBlanksAreNotFindings(): void
    {
        $this->rows = [['uid' => 42, 'ai_context_channels' => ' web_chat , , phone,']];

        $tester = $this->validate();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
    }

    /**
     * Mehrere Funde erscheinen alle — der erste zu melden und abzubrechen
     * hiesse, dass ein Aufraeumen so viele Laeufe braucht, wie es Fehler gibt.
     */
    #[Test]
    public function everyFindingIsReportedNotJustTheFirst(): void
    {
        $this->rows = [['uid' => 42, 'ai_context_channels' => 'telepathie,rauchzeichen']];

        $display = $this->validate()->getDisplay();

        self::assertStringContainsString('telepathie', $display);
        self::assertStringContainsString('rauchzeichen', $display);
        self::assertStringContainsString('2', $display, 'the count is named');
    }

    #[Test]
    public function theCommandKeepsItsName(): void
    {
        self::assertSame(
            'structured-content:validate-channels',
            (new ValidateChannelsCommand($this->connectionPool()))->getName(),
        );
    }
}
