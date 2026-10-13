<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Hooks;

use Koh\StructuredContent\Application\Service\ContextResolver;
use Koh\StructuredContent\Domain\Contract\ContextConfigurationProviderInterface;
use Koh\StructuredContent\Domain\Contract\PageRootlineProviderInterface;
use Koh\StructuredContent\Application\Event\ContextChangedEvent;
use Koh\StructuredContent\Domain\Model\ResolvedContext;
use Koh\StructuredContent\Infrastructure\Hooks\DataHandlerHook;
use Koh\StructuredContent\Tests\Unit\PodcastFeedDoubles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Der Haken, der beim Speichern im Backend mitlaeuft.
 *
 * Er haengt an jedem Schreibvorgang der ganzen Installation — an jedem
 * Editor-Klick, an jedem Import, an jeder MCP-Aenderung. Daraus folgt die
 * erste Eigenschaft, und sie ist eine Kostenfrage: er tut fast immer nichts.
 * Nur wenn eines der drei Kontextfelder im Datensatz vorkommt, laeuft
 * ueberhaupt etwas los. Ohne diese Abkuerzung stuende bei jedem Speichern
 * eine Kontext-Aufloesung und ein Event im Weg.
 *
 * Die zweite steht als Kommentar in der Klasse und ist eine Narbe: TYPO3
 * instanziiert Hooks ohne Konstruktorargumente. Ein Pflichtargument hier
 * bedeutet einen ArgumentCountError bei jedem Speichern — also ein Backend,
 * das nichts mehr speichern kann. Der Test haelt fest, dass die Klasse ohne
 * Argumente konstruierbar bleibt.
 */
final class DataHandlerHookTest extends TestCase
{
    use PodcastFeedDoubles;

    /** @var list<object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        // `ContextResolver` ist final, und die Warteschlange prueft den Typ —
        // also der echte auf gedoubelten Providern. Was er aufloest, ist hier
        // nicht die Frage; dass er ueberhaupt gefragt wird, zeigt das Ereignis.
        $resolver = new ContextResolver(
            $this->createStub(ContextConfigurationProviderInterface::class),
            $this->createStub(PageRootlineProviderInterface::class),
        );

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function (object $event): object {
            $this->dispatched[] = $event;

            return $event;
        });

        // Der Hook loest seine Mitspieler traege ueber GeneralUtility auf —
        // genau deshalb, weil TYPO3 ihn ohne Konstruktorargumente baut. Die
        // Warteschlange gibt jeweils eine Instanz heraus, also grosszuegig.
        // Der CacheManager ist in TYPO3s Sinne ein Singleton und geht
        // deshalb einen anderen Weg als die uebrigen.
        GeneralUtility::setSingletonInstance(CacheManager::class, $this->cacheManager());

        for ($i = 0; $i < 32; ++$i) {
            GeneralUtility::addInstance(ContextResolver::class, $resolver);
            GeneralUtility::addInstance(EventDispatcherInterface::class, $dispatcher);
            GeneralUtility::addInstance(ConnectionPool::class, $this->connectionPool());
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    private function dataHandler(): DataHandler
    {
        $dataHandler = $this->createStub(DataHandler::class);
        $dataHandler->substNEWwithIDs = ['NEW123abc' => 42];

        return $dataHandler;
    }

    /**
     * @param array<string, mixed> $fieldArray
     */
    private function save(string $table, string|int $id, array $fieldArray): void
    {
        (new DataHandlerHook())->processDatamap_afterDatabaseOperations(
            'update',
            $table,
            $id,
            $fieldArray,
            $this->dataHandler(),
        );
    }

    /**
     * Die zweite Eigenschaft, und die mit der Narbe: TYPO3 baut Hooks ohne
     * Konstruktorargumente. Ein Pflichtargument hier ist ein
     * ArgumentCountError bei jedem Speichern — ein Backend, das nichts mehr
     * speichern kann.
     */
    #[Test]
    public function theHookIsConstructibleWithoutArguments(): void
    {
        $reflection = new \ReflectionClass(DataHandlerHook::class);
        $constructor = $reflection->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(0, $constructor->getNumberOfRequiredParameters());
    }

    /**
     * Die erste: ohne ein Kontextfeld im Datensatz passiert nichts. Der Hook
     * laeuft bei jedem Speichern der ganzen Installation mit.
     */
    #[Test]
    public function anOrdinarySaveCostsNothing(): void
    {
        $this->save('pages', 42, ['title' => 'Ein neuer Titel', 'nav_title' => 'Neu']);

        self::assertSame([], $this->dispatched);
    }

    /**
     * Mit einem Kontextfeld laeuft die Aufloesung und ein Ereignis geht
     * heraus — das ist, was nachgelagerte Verbraucher auf eine geaenderte
     * Zielgruppe reagieren laesst.
     */
    #[Test]
    public function aContextFieldChangeIsAnnounced(): void
    {
        $this->save('pages', 42, ['ai_context_audience' => 'developer']);

        self::assertCount(1, $this->dispatched);
        $event = $this->dispatched[0];

        self::assertInstanceOf(ContextChangedEvent::class, $event);
        self::assertSame('pages', $event->table);
        self::assertSame(42, $event->uid);
    }

    #[Test]
    public function everyContextFieldCountsAsOne(): void
    {
        foreach (['ai_context_audience', 'ai_context_tone', 'ai_context_channels'] as $field) {
            $this->dispatched = [];

            $this->save('tt_content', 7, [$field => 'irgendetwas']);

            self::assertCount(1, $this->dispatched, $field);
        }
    }

    /**
     * Nur Seiten und Inhaltselemente tragen Kontext. Ein Kontextfeld in einer
     * anderen Tabelle ist ein Namensvetter, kein Kontext.
     */
    #[Test]
    public function onlyPagesAndContentElementsCarryContext(): void
    {
        foreach (['sys_category', 'tx_news_domain_model_news', 'be_users'] as $table) {
            $this->save($table, 42, ['ai_context_audience' => 'developer']);

            self::assertSame([], $this->dispatched, $table);
        }
    }

    /**
     * Ein neu angelegter Datensatz traegt seine echte uid aus der
     * Ersetzungstabelle des DataHandlers. Ohne diese Aufloesung ginge das
     * Ereignis mit uid 0 hinaus — und niemand koennte den Datensatz
     * nachschlagen.
     */
    #[Test]
    public function aNewRecordIsAnnouncedWithTheUidItActuallyGot(): void
    {
        $this->save('pages', 'NEW123abc', ['ai_context_audience' => 'developer']);

        self::assertCount(1, $this->dispatched);
        self::assertSame(42, $this->dispatched[0]->uid);
    }

    /**
     * Und ein neuer Datensatz, dessen uid nicht aufloesbar ist, loest nichts
     * aus — ein Ereignis mit uid 0 zeigt auf nichts.
     */
    #[Test]
    public function aNewRecordWithoutAResolvableUidAnnouncesNothing(): void
    {
        $this->save('pages', 'NEWunbekannt', ['ai_context_audience' => 'developer']);

        self::assertSame([], $this->dispatched);
    }

    /**
     * Das Ereignis traegt beide Kontexte — den vorherigen und den neuen. Ein
     * Verbraucher, der nur den neuen bekaeme, koennte nicht entscheiden, ob
     * sich ueberhaupt etwas geaendert hat.
     */
    #[Test]
    public function theEventCarriesBothTheOldAndTheNewContext(): void
    {
        $this->save('pages', 42, ['ai_context_audience' => 'developer']);

        $event = $this->dispatched[0];

        self::assertInstanceOf(ResolvedContext::class, $event->oldContext);
        self::assertInstanceOf(ResolvedContext::class, $event->newContext);
    }
}
