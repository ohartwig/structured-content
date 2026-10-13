<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Model;

use Koh\StructuredContent\Domain\Model\ResolvedContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResolvedContextTest extends TestCase
{
    #[Test]
    public function testConstructorAssignsProperties(): void
    {
        $context = new ResolvedContext(
            audience: 'customer',
            tone: 'informational',
            channels: ['web_chat', 'email'],
            inheritedFrom: 'page:42',
        );

        self::assertSame('customer', $context->audience);
        self::assertSame('informational', $context->tone);
        self::assertSame(['web_chat', 'email'], $context->channels);
        self::assertSame('page:42', $context->inheritedFrom);
    }

    #[Test]
    public function testWithDefaultsReturnsInstanceWithDefaultValues(): void
    {
        $context = ResolvedContext::withDefaults();

        self::assertSame('customer', $context->audience);
        self::assertSame('informational', $context->tone);
        self::assertSame([], $context->channels);
        self::assertSame('defaults', $context->inheritedFrom);
    }

    #[Test]
    public function testIsReadonly(): void
    {
        $context = new ResolvedContext(
            audience: 'partner',
            tone: 'technical',
            channels: ['phone'],
            inheritedFrom: 'record',
        );

        $reflection = new \ReflectionClass($context);
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function testEmptyChannelsArray(): void
    {
        $context = new ResolvedContext(
            audience: 'internal',
            tone: 'legal',
            channels: [],
            inheritedFrom: 'page:1',
        );

        self::assertSame([], $context->channels);
    }
}
