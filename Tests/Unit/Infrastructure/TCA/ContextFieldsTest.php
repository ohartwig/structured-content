<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\TCA;

use Koh\StructuredContent\Infrastructure\TCA\ContextFields;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextFieldsTest extends TestCase
{
    #[Test]
    public function testAddToTableAddsFieldsToTcaArray(): void
    {
        // Reset GLOBALS for test isolation
        $GLOBALS['TCA']['pages']['columns'] ??= [];

        ContextFields::addToTable('pages');

        self::assertArrayHasKey('ai_context_audience', $GLOBALS['TCA']['pages']['columns']);
        self::assertArrayHasKey('ai_context_tone', $GLOBALS['TCA']['pages']['columns']);
        self::assertArrayHasKey('ai_context_channels', $GLOBALS['TCA']['pages']['columns']);
    }

    #[Test]
    public function testFieldConfigurationHasCorrectType(): void
    {
        $GLOBALS['TCA']['tt_content']['columns'] ??= [];

        ContextFields::addToTable('tt_content');

        $audienceConfig = $GLOBALS['TCA']['tt_content']['columns']['ai_context_audience']['config'];
        self::assertSame('select', $audienceConfig['type']);

        $toneConfig = $GLOBALS['TCA']['tt_content']['columns']['ai_context_tone']['config'];
        self::assertSame('select', $toneConfig['type']);

        $channelsConfig = $GLOBALS['TCA']['tt_content']['columns']['ai_context_channels']['config'];
        self::assertSame('select', $channelsConfig['type']);
    }

    #[Test]
    public function testAudienceFieldHasCorrectItems(): void
    {
        $GLOBALS['TCA']['pages']['columns'] ??= [];

        ContextFields::addToTable('pages');

        $items = $GLOBALS['TCA']['pages']['columns']['ai_context_audience']['config']['items'];
        $values = array_column($items, 'value');

        self::assertContains('', $values);
        self::assertContains('customer', $values);
        self::assertContains('partner', $values);
        self::assertContains('internal', $values);
        self::assertContains('developer', $values);
    }

    #[Test]
    public function testChannelsFieldAllowsMultipleSelection(): void
    {
        $GLOBALS['TCA']['pages']['columns'] ??= [];

        ContextFields::addToTable('pages');

        $config = $GLOBALS['TCA']['pages']['columns']['ai_context_channels']['config'];
        self::assertSame('select', $config['type']);
        self::assertSame('selectCheckBox', $config['renderType']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TCA']['pages'], $GLOBALS['TCA']['tt_content']);
    }
}
