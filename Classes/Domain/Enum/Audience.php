<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Enum;

enum Audience: string
{
    case CUSTOMER = 'customer';
    case PARTNER = 'partner';
    case INTERNAL = 'internal';
    case DEVELOPER = 'developer';
}
