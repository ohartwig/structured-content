<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Result;
use Koh\StructuredContent\Domain\Enum\RelationType;
use Koh\StructuredContent\Infrastructure\Persistence\DoctrineRelationshipRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Die Kanten zwischen Inhalten: was was erklaert, ersetzt, voraussetzt.
 *
 * Zwei Eigenschaften tragen die Klasse.
 *
 * Eine Beziehung hat eine Richtung, und `findBidirectionalByTarget()` liefert
 * nur die, die als beidseitig gepflegt wurden. Ohne diese Einschraenkung
 * erschiene jede einseitige Beziehung auch auf der Gegenseite — "A ersetzt B"
 * wuerde auf B als "B ersetzt A" ausgegeben, und das ist im Schema die
 * gegenteilige Aussage.
 *
 * Und der Typ ist ein Aufzaehlungswert, keine Zeichenkette. Eine Zeile mit
 * einem Wert, den es nicht mehr gibt, faellt beim Lesen auf — statt still als
 * unbekannte Beziehung in die Ausgabe zu wandern.
 */
final class DoctrineRelationshipRepositoryTest extends TestCase
{
    private const TABLE = 'tx_structuredcontent_relation';

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    private int $count = 0;

    /** @var int Wie viele `andWhere()` eine Abfrage zusaetzlich gesammelt hat */
    private int $additionalConditions = 0;

    /** @var list<array{table: string, values: array<string, mixed>}> */
    private array $inserted = [];

    /** @var list<array{table: string, where: array<string, mixed>}> */
    private array $deleted = [];

    private function repository(): DoctrineRelationshipRepository
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturnCallback(fn (): array => $this->rows);
        $result->method('fetchOne')->willReturnCallback(fn (): mixed => $this->count);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'count', 'from', 'where'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $queryBuilder->method('andWhere')->willReturnCallback(
            function () use ($queryBuilder): QueryBuilder {
                ++$this->additionalConditions;

                return $queryBuilder;
            },
        );
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connection = $this->createStub(Connection::class);
        $connection->method('insert')->willReturnCallback(
            function (string $table, array $values): int {
                $this->inserted[] = ['table' => $table, 'values' => $values];

                return 1;
            },
        );
        $connection->method('delete')->willReturnCallback(
            function (string $table, array $where): int {
                $this->deleted[] = ['table' => $table, 'where' => $where];

                return 1;
            },
        );

        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
        $pool->method('getConnectionForTable')->willReturn($connection);

        return new DoctrineRelationshipRepository($pool);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
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
            'description' => 'Erklaert den Hintergrund.',
            ...$overrides,
        ];
    }

    #[Test]
    public function aStoredRelationIsReadBackWhole(): void
    {
        $this->rows = [$this->row()];

        $relations = $this->repository()->findBySource('pages', 42);

        self::assertCount(1, $relations);
        $relation = $relations[0];

        self::assertSame('pages', $relation->sourceTable);
        self::assertSame(42, $relation->sourceUid);
        self::assertSame('pages', $relation->targetTable);
        self::assertSame(43, $relation->targetUid);
        self::assertSame(RelationType::EXPLAINS, $relation->relationType);
        self::assertSame('unidirectional', $relation->direction);
        self::assertSame('Erklaert den Hintergrund.', $relation->description);
    }

    /**
     * Die Zuversicht kommt als Kommazahl zurueck, nicht als Zeichenkette —
     * sie wird verglichen, und ein Vergleich zweier Zeichenketten sortiert
     * '0.9' vor '0.85'.
     */
    #[Test]
    public function theConfidenceIsANumber(): void
    {
        $this->rows = [$this->row(['confidence' => '0.85'])];

        self::assertSame(0.85, $this->repository()->findBySource('pages', 42)[0]->confidence);
    }

    /**
     * Eine leere Beschreibung ist keine Beschreibung. Als leerer String
     * erschiene sie im Schema als vorhandene, aber inhaltslose Angabe.
     */
    #[Test]
    public function anEmptyDescriptionIsNoDescription(): void
    {
        foreach ([['description' => ''], ['description' => null], []] as $override) {
            $row = $this->row($override);
            if ([] === $override) {
                unset($row['description']);
            }
            $this->rows = [$row];

            self::assertNull($this->repository()->findBySource('pages', 42)[0]->description);
        }
    }

    #[Test]
    public function aRecordWithoutRelationsHasNone(): void
    {
        self::assertSame([], $this->repository()->findBySource('pages', 42));
    }

    /**
     * Der Typ engt die Abfrage ein, wenn einer angegeben ist — und tut es
     * nicht, wenn keiner angegeben ist. Eine Einschraenkung auf `null` waere
     * eine Abfrage, die nichts findet, und die liest sich wie "es gibt keine
     * Beziehungen".
     */
    #[Test]
    public function aTypeNarrowsTheQueryAndItsAbsenceDoesNot(): void
    {
        $this->rows = [$this->row()];

        $this->additionalConditions = 0;
        $this->repository()->findBySource('pages', 42);
        self::assertSame(0, $this->additionalConditions, 'no type, no extra condition');

        $this->additionalConditions = 0;
        $this->repository()->findBySource('pages', 42, RelationType::EXPLAINS);
        self::assertSame(1, $this->additionalConditions, 'a type narrows the query');
    }

    /**
     * Die Regression, auf die es ankommt: von der Gegenseite aus werden nur
     * beidseitige Beziehungen gefunden. Ohne diese Einschraenkung erschiene
     * jede einseitige auch drueben — "A ersetzt B" wuerde auf B zu "B ersetzt
     * A", und das ist die gegenteilige Aussage.
     */
    #[Test]
    public function fromTheOtherSideOnlyBidirectionalRelationsAreFound(): void
    {
        $this->rows = [$this->row(['direction' => 'bidirectional'])];

        $relations = $this->repository()->findBidirectionalByTarget('pages', 43);

        self::assertCount(1, $relations);
        self::assertSame('bidirectional', $relations[0]->direction);
    }

    #[Test]
    public function theTypeNarrowsTheQueryFromTheOtherSideToo(): void
    {
        $this->rows = [$this->row(['direction' => 'bidirectional'])];

        $this->additionalConditions = 0;
        $this->repository()->findBidirectionalByTarget('pages', 43);
        self::assertSame(0, $this->additionalConditions);

        $this->additionalConditions = 0;
        $this->repository()->findBidirectionalByTarget('pages', 43, RelationType::EXPLAINS);
        self::assertSame(1, $this->additionalConditions);
    }

    /**
     * Die zweite Eigenschaft: der Typ ist ein Aufzaehlungswert. Eine Zeile mit
     * einem Wert, den es nicht mehr gibt, faellt beim Lesen auf, statt still
     * als unbekannte Beziehung in die Ausgabe zu wandern.
     */
    #[Test]
    public function aRelationTypeThatNoLongerExistsIsNotSilentlyAccepted(): void
    {
        $this->rows = [$this->row(['relation_type' => 'irgendwas-alters'])];

        $this->expectException(\ValueError::class);

        $this->repository()->findBySource('pages', 42);
    }

    /**
     * `exists()` ist die Doppelungssperre vor dem Schreiben. Sie muss eine
     * Zaehlung lesen, nicht eine Zeile — sonst haengt die Antwort daran, ob
     * die erste Zeile zufaellig etwas Wahrheitsfaehiges enthaelt.
     */
    #[Test]
    public function anExistingRelationIsRecognisedAndAMissingOneIsNot(): void
    {
        $this->count = 1;
        self::assertTrue($this->repository()->exists('pages', 42, 'pages', 43, RelationType::EXPLAINS));

        $this->count = 0;
        self::assertFalse($this->repository()->exists('pages', 42, 'pages', 43, RelationType::EXPLAINS));
    }

    #[Test]
    public function aNewRelationIsWrittenWithEverythingItNeeds(): void
    {
        $this->repository()->insert('pages', 42, 'pages', 43, RelationType::EXPLAINS, 'bidirectional', 0.8, 'editor');

        self::assertCount(1, $this->inserted);
        self::assertSame(self::TABLE, $this->inserted[0]['table']);

        $values = $this->inserted[0]['values'];
        self::assertSame('pages', $values['source_table']);
        self::assertSame(42, $values['source_uid']);
        self::assertSame(43, $values['target_uid']);
        self::assertSame('explains', $values['relation_type'], 'the enum is stored as its value');
        self::assertSame('bidirectional', $values['direction']);
        self::assertSame(0.8, $values['confidence']);
        self::assertSame('editor', $values['created_by']);
    }

    /**
     * Geloescht wird genau die eine Kante — Quelle, Ziel und Typ zusammen.
     * Faellt der Typ aus der Bedingung, verschwinden mit "A erklaert B" auch
     * "A ersetzt B" und alles andere zwischen denselben beiden.
     */
    #[Test]
    public function deletingRemovesExactlyOneEdge(): void
    {
        $this->repository()->delete('pages', 42, 'pages', 43, RelationType::EXPLAINS);

        self::assertCount(1, $this->deleted);
        self::assertSame(self::TABLE, $this->deleted[0]['table']);
        self::assertSame(
            [
                'source_table' => 'pages',
                'source_uid' => 42,
                'target_table' => 'pages',
                'target_uid' => 43,
                'relation_type' => 'explains',
            ],
            $this->deleted[0]['where'],
        );
    }
}
