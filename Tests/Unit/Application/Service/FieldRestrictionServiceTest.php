<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\FieldRestrictionService;
use Koh\StructuredContent\Domain\Contract\ContextConfigurationProviderInterface;
use Koh\StructuredContent\Domain\Model\FilteredRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FieldRestrictionServiceTest extends TestCase
{
    private ContextConfigurationProviderInterface $configProvider;
    private FieldRestrictionService $service;

    protected function setUp(): void
    {
        $this->configProvider = $this->createMock(ContextConfigurationProviderInterface::class);
        $this->service = new FieldRestrictionService(
            configurationProvider: $this->configProvider,
        );
    }

    #[Test]
    public function testFilterRecordRemovesRestrictedFields(): void
    {
        $this->configProvider
            ->expects(self::once())
            ->method('getFieldRestrictionsForTable')
            ->with('tt_content', 'web_chat')
            ->willReturn(['internal_notes', 'admin_comment']);

        $record = [
            'uid' => 1,
            'title' => 'Test',
            'bodytext' => 'Content',
            'internal_notes' => 'Secret stuff',
            'admin_comment' => 'Admin only',
        ];

        $result = $this->service->filterRecord(
            table: 'tt_content',
            record: $record,
            channel: 'web_chat',
        );

        self::assertInstanceOf(FilteredRecord::class, $result);
        self::assertArrayNotHasKey('internal_notes', $result->record);
        self::assertArrayNotHasKey('admin_comment', $result->record);
        self::assertArrayHasKey('uid', $result->record);
        self::assertArrayHasKey('title', $result->record);
        self::assertArrayHasKey('bodytext', $result->record);
        self::assertSame(['internal_notes', 'admin_comment'], $result->removedFields);
    }

    #[Test]
    public function testFilterRecordReturnsUnmodifiedWhenNoRestrictions(): void
    {
        $this->configProvider
            ->expects(self::once())
            ->method('getFieldRestrictionsForTable')
            ->with('pages', 'email')
            ->willReturn(null);

        $record = ['uid' => 1, 'title' => 'Test Page'];

        $result = $this->service->filterRecord(
            table: 'pages',
            record: $record,
            channel: 'email',
        );

        self::assertSame($record, $result->record);
        self::assertSame([], $result->removedFields);
        self::assertNull($result->reason);
    }

    #[Test]
    public function testFilterRecordReturnsUnmodifiedWhenEmptyRestrictions(): void
    {
        $this->configProvider
            ->expects(self::once())
            ->method('getFieldRestrictionsForTable')
            ->with('pages', 'phone')
            ->willReturn([]);

        $record = ['uid' => 1, 'title' => 'Test'];

        $result = $this->service->filterRecord(
            table: 'pages',
            record: $record,
            channel: 'phone',
        );

        self::assertSame($record, $result->record);
        self::assertSame([], $result->removedFields);
    }

    #[Test]
    public function testFilterRecordOnlyRemovesExistingFields(): void
    {
        $this->configProvider
            ->expects(self::once())
            ->method('getFieldRestrictionsForTable')
            ->with('tt_content', 'social')
            ->willReturn(['nonexistent_field', 'bodytext']);

        $record = ['uid' => 1, 'title' => 'Test', 'bodytext' => 'Content'];

        $result = $this->service->filterRecord(
            table: 'tt_content',
            record: $record,
            channel: 'social',
        );

        self::assertArrayNotHasKey('bodytext', $result->record);
        self::assertSame(['bodytext'], $result->removedFields);
    }
}
