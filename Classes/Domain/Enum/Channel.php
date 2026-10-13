<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Enum;

enum Channel: string
{
    case WEB_CHAT = 'web_chat';
    case WHATSAPP = 'whatsapp';
    case PHONE = 'phone';
    case EMAIL = 'email';
    case SOCIAL = 'social';
    case MCP_PUBLIC = 'mcp_public';
    case MCP_INTERNAL = 'mcp_internal';
    case PDF = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::WEB_CHAT => 'Web Chat',
            self::WHATSAPP => 'WhatsApp',
            self::PHONE => 'Phone',
            self::EMAIL => 'Email',
            self::SOCIAL => 'Social',
            self::MCP_PUBLIC => 'MCP Public',
            self::MCP_INTERNAL => 'MCP Internal',
            self::PDF => 'PDF',
        };
    }
}
