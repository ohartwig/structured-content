<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Model;

use Koh\StructuredContent\Domain\Model\FilteredRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FilteredRecordTest extends TestCase
{
    #[Test]
    public function testConstructorAssignsProperties(): void
    {
        $record = new FilteredRecord(
            record: ['uid' => 1, 'title' => 'Test'],
            removedFields: ['internal_notes', 'admin_comment'],
            reason: 'Channel restriction for web_chat',
        );

        self::assertSame(['uid' => 1, 'title' => 'Test'], $record->record);
        self::assertSame(['internal_notes', 'admin_comment'], $record->removedFields);
        self::assertSame('Channel restriction for web_chat', $record->reason);
    }

    #[Test]
    public function testReasonCanBeNull(): void
    {
        $record = new FilteredRecord(
            record: ['uid' => 1],
            removedFields: [],
            reason: null,
        );

        self::assertNull($record->reason);
    }

    #[Test]
    public function testEmptyRemovedFieldsArray(): void
    {
        $record = new FilteredRecord(
            record: ['uid' => 1, 'title' => 'Test', 'bodytext' => 'Content'],
            removedFields: [],
            reason: null,
        );

        self::assertSame([], $record->removedFields);
    }

    #[Test]
    public function testIsReadonly(): void
    {
        $record = new FilteredRecord(
            record: [],
            removedFields: [],
            reason: null,
        );

        $reflection = new \ReflectionClass($record);
        self::assertTrue($reflection->isReadOnly());
    }
}
