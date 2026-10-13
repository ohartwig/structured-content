<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Event;

use Koh\StructuredContent\Application\Event\ContextChangedEvent;
use Koh\StructuredContent\Domain\Model\ResolvedContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextChangedEventTest extends TestCase
{
    #[Test]
    public function testConstructorAssignsProperties(): void
    {
        $oldContext = new ResolvedContext(
            audience: 'customer',
            tone: 'informational',
            channels: ['web_chat'],
            inheritedFrom: 'defaults',
        );

        $newContext = new ResolvedContext(
            audience: 'developer',
            tone: 'technical',
            channels: ['email', 'mcp_public'],
            inheritedFrom: 'record',
        );

        $event = new ContextChangedEvent(
            table: 'pages',
            uid: 42,
            oldContext: $oldContext,
            newContext: $newContext,
        );

        self::assertSame('pages', $event->table);
        self::assertSame(42, $event->uid);
        self::assertSame($oldContext, $event->oldContext);
        self::assertSame($newContext, $event->newContext);
    }

    #[Test]
    public function testIsReadonly(): void
    {
        $context = ResolvedContext::withDefaults();
        $event = new ContextChangedEvent(
            table: 'pages',
            uid: 1,
            oldContext: $context,
            newContext: $context,
        );

        $reflection = new \ReflectionClass($event);
        self::assertTrue($reflection->isReadOnly());
    }
}
