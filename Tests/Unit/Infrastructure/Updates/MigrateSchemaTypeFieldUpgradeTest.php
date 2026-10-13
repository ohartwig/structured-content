<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Updates;

use Doctrine\DBAL\Result;
use Koh\StructuredContent\Infrastructure\Updates\MigrateSchemaTypeFieldUpgrade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;

/**
 * Der Umzug des Schema-Typ-Feldes von semantic-delivery hierher.
 *
 * Ein Upgrade-Assistent laeuft genau einmal, unbeaufsichtigt, auf einer
 * Kundeninstallation — und danach schaut niemand nach, ob er das Richtige
 * getan hat. Zwei Eigenschaften folgen daraus:
 *
 * Er raeumt die alte Spalte leer. Bleibt der Wert dort stehen, tragen
 * beide Felder dieselbe Angabe — und die naechste Migration, die auf "nicht
 * leer" prueft, findet die Zeilen wieder und laeuft ein zweites Mal.
 */
final class MigrateSchemaTypeFieldUpgradeTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var list<array{values: array<string, mixed>, where: array<string, mixed>}> */
    private array $updated = [];

    protected function setUp(): void
    {
        parent::setUp();

        GeneralUtility::addInstance(ConnectionPool::class, $this->connectionPool());
        GeneralUtility::addInstance(ConnectionPool::class, $this->connectionPool());
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    private function connectionPool(): ConnectionPool
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturnCallback(fn (): array => $this->rows);
        $result->method('fetchOne')->willReturnCallback(fn (): mixed => $this->count);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'count', 'from', 'where'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($queryBuilder);
        $connection->method('update')->willReturnCallback(
            function (string $table, array $values, array $where): int {
                $this->updated[] = ['values' => $values, 'where' => $where];

                return 1;
            },
        );

        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        return $pool;
    }

    /**
     * Die zweite Eigenschaft: der Wert zieht um und die alte Spalte wird
     * geleert. Bleibt er stehen, tragen beide Felder dieselbe Angabe — und die
     * naechste Migration findet die Zeilen wieder.
     */
    #[Test]
    public function everyValueMovesAndTheOldColumnIsCleared(): void
    {
        $this->rows = [
            ['uid' => 42, 'tx_semanticdelivery_schema_type' => 'TechArticle'],
            ['uid' => 43, 'tx_semanticdelivery_schema_type' => 'Service'],
        ];

        self::assertTrue((new MigrateSchemaTypeFieldUpgrade())->executeUpdate());
        self::assertCount(2, $this->updated);

        self::assertSame('TechArticle', $this->updated[0]['values']['tx_structuredcontent_schema_type']);
        self::assertSame('', $this->updated[0]['values']['tx_semanticdelivery_schema_type']);
        self::assertSame(['uid' => 42], $this->updated[0]['where']);

        self::assertSame('Service', $this->updated[1]['values']['tx_structuredcontent_schema_type']);
        self::assertSame(['uid' => 43], $this->updated[1]['where']);
    }

    /**
     * Ein Lauf ohne Zeilen ist erfolgreich und schreibt nichts — der
     * Assistent muss auch dann durchlaufen koennen, wenn zwischen dem Anzeigen
     * und dem Ausfuehren jemand anderes aufgeraeumt hat.
     */
    #[Test]
    public function aRunWithNothingLeftToDoSucceedsWithoutWriting(): void
    {
        self::assertTrue((new MigrateSchemaTypeFieldUpgrade())->executeUpdate());
        self::assertSame([], $this->updated);
    }

    /**
     * Der Assistent verlangt ein aktuelles Datenbankschema, bevor er laeuft.
     * Ohne diese Vorbedingung liefe er gegen eine Zielspalte, die es noch
     * nicht gibt — und verlöre dabei jeden Wert, den er gerade umgezogen hat.
     */
    #[Test]
    public function theWizardRequiresAnUpToDateSchemaFirst(): void
    {
        self::assertSame(
            [DatabaseUpdatedPrerequisite::class],
            (new MigrateSchemaTypeFieldUpgrade())->getPrerequisites(),
        );
    }

    /**
     * Titel und Beschreibung sind das, was ein Betreiber im
     * Installations-Werkzeug liest, bevor er den Knopf drueckt. Sie muessen
     * sagen, was passiert.
     */
    #[Test]
    public function theWizardExplainsItselfBeforeItIsRun(): void
    {
        $wizard = new MigrateSchemaTypeFieldUpgrade();

        self::assertNotSame('', $wizard->getTitle());
        self::assertStringContainsString('tx_semanticdelivery_schema_type', $wizard->getDescription());
        self::assertStringContainsString('tx_structuredcontent_schema_type', $wizard->getDescription());
    }
}
