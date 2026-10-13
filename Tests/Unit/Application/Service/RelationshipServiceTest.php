<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\RelationshipService;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Enum\RelationType;
use Koh\StructuredContent\Domain\Exception\CircularRelationException;
use Koh\StructuredContent\Domain\Exception\DuplicateRelationException;
use Koh\StructuredContent\Domain\Model\ContentRelation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Koh\StructuredContent\Domain\Contract\EventDispatcherInterface;

final class RelationshipServiceTest extends TestCase
{
    private RelationshipRepositoryInterface $repository;
    private EventDispatcherInterface $eventDispatcher;
    private RelationshipService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(RelationshipRepositoryInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->service = new RelationshipService(
            repository: $this->repository,
            eventDispatcher: $this->eventDispatcher,
        );
    }

    #[Test]
    public function testGetRelationsForDelegatesToRepository(): void
    {
        $expected = [
            new ContentRelation(
                sourceTable: 'pages',
                sourceUid: 1,
                targetTable: 'pages',
                targetUid: 2,
                relationType: RelationType::RELATED_TO,
                direction: 'bidirectional',
                confidence: 1.0,
                createdBy: 'manual',
                description: null,
            ),
        ];

        $this->repository
            ->expects(self::once())
            ->method('findBySource')
            ->with('pages', 1, null)
            ->willReturn($expected);

        $result = $this->service->getRelationsFor(table: 'pages', uid: 1);

        self::assertSame($expected, $result);
    }

    #[Test]
    public function testGetRelationsForWithTypeFilter(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findBySource')
            ->with('pages', 1, RelationType::RELATED_TO);

        $this->service->getRelationsFor(table: 'pages', uid: 1, type: RelationType::RELATED_TO);
    }

    #[Test]
    public function testAddRelationInsertsValidRelation(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('exists')
            ->with('pages', 1, 'pages', 2, RelationType::RELATED_TO)
            ->willReturn(false);

        $this->repository
            ->expects(self::once())
            ->method('findBySource')
            ->willReturn([]);

        $this->repository
            ->expects(self::once())
            ->method('insert')
            ->with('pages', 1, 'pages', 2, RelationType::RELATED_TO, 'bidirectional', 0.9, 'manual');

        $this->eventDispatcher
            ->expects(self::once())
            ->method('dispatch');

        $this->service->addRelation(
            sourceTable: 'pages',
            sourceUid: 1,
            targetTable: 'pages',
            targetUid: 2,
            type: RelationType::RELATED_TO,
            direction: 'bidirectional',
            confidence: 0.9,
            createdBy: 'manual',
        );
    }

    #[Test]
    public function testAddRelationThrowsOnDuplicate(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('exists')
            ->with('pages', 1, 'pages', 2, RelationType::RELATED_TO)
            ->willReturn(true);

        $this->expectException(DuplicateRelationException::class);

        $this->service->addRelation(
            sourceTable: 'pages',
            sourceUid: 1,
            targetTable: 'pages',
            targetUid: 2,
            type: RelationType::RELATED_TO,
            direction: 'bidirectional',
            confidence: 0.9,
            createdBy: 'manual',
        );
    }

    #[Test]
    public function testAddRelationThrowsOnSelfReference(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('exists')
            ->willReturn(false);

        $this->expectException(CircularRelationException::class);

        $this->service->addRelation(
            sourceTable: 'pages',
            sourceUid: 1,
            targetTable: 'pages',
            targetUid: 1,
            type: RelationType::RELATED_TO,
            direction: 'bidirectional',
            confidence: 1.0,
            createdBy: 'manual',
        );
    }

    #[Test]
    public function testValidateRelationTypeAcceptsValidType(): void
    {
        $this->service->validateRelationType(RelationType::RELATED_TO->value);
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function testWouldCreateCycleDetectsSelfReference(): void
    {
        self::assertTrue(
            $this->service->wouldCreateCycle(
                sourceTable: 'pages',
                sourceUid: 1,
                targetTable: 'pages',
                targetUid: 1,
                type: RelationType::REQUIRES,
            )
        );
    }

    #[Test]
    public function testWouldCreateCycleDetectsDirectCycle(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findBySource')
            ->with('pages', 2, RelationType::REQUIRES)
            ->willReturn([
                new ContentRelation(
                    sourceTable: 'pages',
                    sourceUid: 2,
                    targetTable: 'pages',
                    targetUid: 1,
                    relationType: RelationType::REQUIRES,
                    direction: 'unidirectional',
                    confidence: 1.0,
                    createdBy: 'manual',
                    description: null,
                ),
            ]);

        self::assertTrue(
            $this->service->wouldCreateCycle(
                sourceTable: 'pages',
                sourceUid: 1,
                targetTable: 'pages',
                targetUid: 2,
                type: RelationType::REQUIRES,
            )
        );
    }

    #[Test]
    public function testWouldCreateCycleReturnsFalseForNoCycle(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findBySource')
            ->with('pages', 2, RelationType::REQUIRES)
            ->willReturn([]);

        self::assertFalse(
            $this->service->wouldCreateCycle(
                sourceTable: 'pages',
                sourceUid: 1,
                targetTable: 'pages',
                targetUid: 2,
                type: RelationType::REQUIRES,
            )
        );
    }
}
