<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Event;

use Koh\StructuredContent\Application\Event\RelationshipChangedEvent;
use Koh\StructuredContent\Domain\Enum\RelationType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelationshipChangedEventTest extends TestCase
{
    #[Test]
    public function testConstructorAssignsProperties(): void
    {
        $event = new RelationshipChangedEvent(
            action: 'added',
            sourceTable: 'pages',
            sourceUid: 1,
            targetTable: 'tt_content',
            targetUid: 42,
            relationType: RelationType::BELONGS_TO,
        );

        self::assertSame('added', $event->action);
        self::assertSame('pages', $event->sourceTable);
        self::assertSame(1, $event->sourceUid);
        self::assertSame('tt_content', $event->targetTable);
        self::assertSame(42, $event->targetUid);
        self::assertSame(RelationType::BELONGS_TO, $event->relationType);
    }

    #[Test]
    public function testIsReadonly(): void
    {
        $event = new RelationshipChangedEvent(
            action: 'removed',
            sourceTable: 'pages',
            sourceUid: 1,
            targetTable: 'pages',
            targetUid: 2,
            relationType: RelationType::RELATED_TO,
        );

        $reflection = new \ReflectionClass($event);
        self::assertTrue($reflection->isReadOnly());
    }
}
