<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Persistence;

use Koh\StructuredContent\Infrastructure\Persistence\DoctrineEpisodeGuestsRepository;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Die Zusatzangaben zu einer Folge: wer zu Gast war, wer gefehlt hat, und was
 * gesagt wurde.
 *
 * Alle drei Angaben pflegt eine Redaktion in einem Inline-Feld, und alle drei
 * landen ungeprueft in der Ausgabe. Die Gaesteliste ist eine CSV in einer
 * Spalte — die klassische Stelle, an der eine 0 durchrutscht: `(int) ''` ist
 * 0, und uid 0 ist in TYPO3 kein Datensatz. Ein Gast mit uid 0 wird nachher
 * nachgeschlagen, nicht gefunden, und verschwindet stillschweigend aus der
 * Liste — oder schlimmer, er wird als leere Person ausgegeben.
 *
 * Das Transkript ist die zweite Eigenschaft: es kann auf mehrere Datensaetze
 * verteilt sein, und die Reihenfolge ist die redaktionelle. Zusammengesetzt in
 * der falschen Reihenfolge ist es ein Text, der etwas anderes sagt.
 */
final class DoctrineEpisodeGuestsRepositoryTest extends TestCase
{
    use PodcastFeedDoubles;

    private const SLUG = 'folge-12-wie-suchmaschinen-podcasts-lesen';

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

    private function repository(): DoctrineEpisodeGuestsRepository
    {
        return new DoctrineEpisodeGuestsRepository($this->connectionPool());
    }

    /**
     * Ohne Plugin oder ohne Folge gibt es nichts nachzuschlagen — und die
     * Abfrage wird gar nicht erst gestellt. Eine Abfrage auf uid 0 ist eine
     * Abfrage ins Leere, aber sie kostet trotzdem eine Runde zur Datenbank.
     */
    #[Test]
    public function withoutAPluginOrAnEpisodeNothingIsLookedUp(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_guests' => '7,9']];
        $repository = $this->repository();

        foreach ([[0, self::SLUG], [-1, self::SLUG], [1, ''], [1, '   ']] as [$pluginUid, $slug]) {
            self::assertSame([], $repository->findGuestUidsByPluginAndSlug($pluginUid, $slug));
            self::assertSame([], $repository->findAbsentHostUidsByPluginAndSlug($pluginUid, $slug));
            self::assertSame('', $repository->findTranscriptByPluginAndSlug($pluginUid, $slug));
        }
    }

    #[Test]
    public function theGuestsOfAnEpisodeAreReadAsUids(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_guests' => '7,9']];

        self::assertSame([7, 9], $this->repository()->findGuestUidsByPluginAndSlug(1, self::SLUG));
    }

    /**
     * Die Regression, auf die es ankommt: aus einer handgepflegten Liste faellt
     * keine 0 heraus. `(int) ''` ist 0, und uid 0 ist kein Datensatz — ein
     * solcher Gast wird nachher nicht gefunden und verschwindet still, oder er
     * wird als leere Person ausgegeben.
     */
    #[Test]
    public function aHandEditedListProducesNoZeroes(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_guests' => '7, , 9,,0,-3, ']];

        self::assertSame([7, 9], $this->repository()->findGuestUidsByPluginAndSlug(1, self::SLUG));
    }

    /**
     * Derselbe Gast zweimal ist einmal. Eine doppelte Nennung aendert nichts
     * an der Folge, wuerde aber im Schema als zwei Personen erscheinen.
     */
    #[Test]
    public function aGuestNamedTwiceAppearsOnce(): void
    {
        $this->rows = [
            ['moselwal_podcastepisodemeta_guests' => '7,9'],
            ['moselwal_podcastepisodemeta_guests' => '9,11'],
        ];

        self::assertSame([7, 9, 11], $this->repository()->findGuestUidsByPluginAndSlug(1, self::SLUG));
    }

    #[Test]
    public function anEpisodeWithoutGuestsHasNone(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_guests' => '']];

        self::assertSame([], $this->repository()->findGuestUidsByPluginAndSlug(1, self::SLUG));
    }

    /**
     * Die abwesenden Gastgeber werden genauso gelesen — sie sind das
     * Gegenstueck und entscheiden, wer *nicht* als Sprecher der Folge
     * ausgegeben wird.
     */
    #[Test]
    public function theAbsentHostsAreReadTheSameWay(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_absent_hosts' => '3, ,0,5']];

        self::assertSame([3, 5], $this->repository()->findAbsentHostUidsByPluginAndSlug(1, self::SLUG));
    }

    #[Test]
    public function anEpisodeWhereNobodyWasAbsentHasNoAbsentHosts(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_absent_hosts' => '']];

        self::assertSame([], $this->repository()->findAbsentHostUidsByPluginAndSlug(1, self::SLUG));
    }

    #[Test]
    public function theTranscriptComesBackAsItWasWritten(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_transcript' => 'Willkommen zu Folge zwoelf.']];

        self::assertSame(
            'Willkommen zu Folge zwoelf.',
            $this->repository()->findTranscriptByPluginAndSlug(1, self::SLUG),
        );
    }

    /**
     * Die zweite Eigenschaft: ein auf mehrere Datensaetze verteiltes
     * Transkript wird in der redaktionellen Reihenfolge zusammengesetzt, mit
     * einer Leerzeile dazwischen. Ohne Trennung liefe der letzte Satz des
     * einen Abschnitts in den ersten des naechsten.
     */
    #[Test]
    public function aTranscriptSplitOverSeveralRecordsIsJoinedInOrder(): void
    {
        $this->rows = [
            ['moselwal_podcastepisodemeta_transcript' => 'Erster Abschnitt.'],
            ['moselwal_podcastepisodemeta_transcript' => 'Zweiter Abschnitt.'],
        ];

        self::assertSame(
            "Erster Abschnitt.\n\nZweiter Abschnitt.",
            $this->repository()->findTranscriptByPluginAndSlug(1, self::SLUG),
        );
    }

    /**
     * Leere Abschnitte fallen heraus, statt als Luecken im Text zu erscheinen.
     */
    #[Test]
    public function emptySectionsDoNotBecomeGapsInTheText(): void
    {
        $this->rows = [
            ['moselwal_podcastepisodemeta_transcript' => 'Erster Abschnitt.'],
            ['moselwal_podcastepisodemeta_transcript' => '   '],
            ['moselwal_podcastepisodemeta_transcript' => 'Zweiter Abschnitt.'],
        ];

        self::assertSame(
            "Erster Abschnitt.\n\nZweiter Abschnitt.",
            $this->repository()->findTranscriptByPluginAndSlug(1, self::SLUG),
        );
    }

    /**
     * Und die Reihenfolge ist die gepflegte, nicht die zufaellige der
     * Datenbank — ein Transkript in der falschen Reihenfolge ist ein Text, der
     * etwas anderes sagt.
     */
    #[Test]
    public function theOrderIsTheEditorialOne(): void
    {
        $this->rows = [['moselwal_podcastepisodemeta_transcript' => 'Erster Abschnitt.']];

        $this->repository()->findTranscriptByPluginAndSlug(1, self::SLUG);

        self::assertSame([['sorting', 'ASC']], $this->ordering);
    }

    #[Test]
    public function anEpisodeWithoutATranscriptYieldsAnEmptyString(): void
    {
        self::assertSame('', $this->repository()->findTranscriptByPluginAndSlug(1, self::SLUG));
    }
}
