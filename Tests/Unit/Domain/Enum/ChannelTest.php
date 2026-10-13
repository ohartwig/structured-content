<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Enum;

use Koh\StructuredContent\Domain\Enum\Channel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    #[Test]
    public function testAllCasesHaveStringValues(): void
    {
        self::assertSame('web_chat', Channel::WEB_CHAT->value);
        self::assertSame('whatsapp', Channel::WHATSAPP->value);
        self::assertSame('phone', Channel::PHONE->value);
        self::assertSame('email', Channel::EMAIL->value);
        self::assertSame('social', Channel::SOCIAL->value);
        self::assertSame('mcp_public', Channel::MCP_PUBLIC->value);
        self::assertSame('mcp_internal', Channel::MCP_INTERNAL->value);
        self::assertSame('pdf', Channel::PDF->value);
    }

    #[Test]
    public function testCaseCount(): void
    {
        self::assertCount(8, Channel::cases());
    }

    #[Test]
    public function testLabelReturnsHumanReadableString(): void
    {
        self::assertSame('Web Chat', Channel::WEB_CHAT->label());
        self::assertSame('WhatsApp', Channel::WHATSAPP->label());
        self::assertSame('Phone', Channel::PHONE->label());
        self::assertSame('Email', Channel::EMAIL->label());
        self::assertSame('Social', Channel::SOCIAL->label());
        self::assertSame('MCP Public', Channel::MCP_PUBLIC->label());
        self::assertSame('MCP Internal', Channel::MCP_INTERNAL->label());
        self::assertSame('PDF', Channel::PDF->label());
    }
}
