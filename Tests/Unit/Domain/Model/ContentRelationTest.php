<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Model;

use Koh\StructuredContent\Domain\Enum\RelationType;
use Koh\StructuredContent\Domain\Model\ContentRelation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentRelationTest extends TestCase
{
    #[Test]
    public function testConstructorAssignsAllProperties(): void
    {
        $relation = new ContentRelation(
            sourceTable: 'tt_content',
            sourceUid: 1,
            targetTable: 'pages',
            targetUid: 42,
            relationType: RelationType::BELONGS_TO,
            direction: 'unidirectional',
            confidence: 0.95,
            createdBy: 'auto',
            description: 'Content belongs to this page',
        );

        self::assertSame('tt_content', $relation->sourceTable);
        self::assertSame(1, $relation->sourceUid);
        self::assertSame('pages', $relation->targetTable);
        self::assertSame(42, $relation->targetUid);
        self::assertSame(RelationType::BELONGS_TO, $relation->relationType);
        self::assertSame('unidirectional', $relation->direction);
        self::assertSame(0.95, $relation->confidence);
        self::assertSame('auto', $relation->createdBy);
        self::assertSame('Content belongs to this page', $relation->description);
    }

    #[Test]
    public function testDescriptionCanBeNull(): void
    {
        $relation = new ContentRelation(
            sourceTable: 'pages',
            sourceUid: 1,
            targetTable: 'pages',
            targetUid: 2,
            relationType: RelationType::RELATED_TO,
            direction: 'bidirectional',
            confidence: 1.0,
            createdBy: 'manual',
            description: null,
        );

        self::assertNull($relation->description);
    }

    #[Test]
    public function testIsReadonly(): void
    {
        $relation = new ContentRelation(
            sourceTable: 'pages',
            sourceUid: 1,
            targetTable: 'pages',
            targetUid: 2,
            relationType: RelationType::RELATED_TO,
            direction: 'bidirectional',
            confidence: 1.0,
            createdBy: 'manual',
            description: null,
        );

        $reflection = new \ReflectionClass($relation);
        self::assertTrue($reflection->isReadOnly());
    }
}
