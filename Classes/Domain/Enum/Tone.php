<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Enum;

enum Tone: string
{
    case INFORMATIONAL = 'informational';
    case PROMOTIONAL = 'promotional';
    case TECHNICAL = 'technical';
    case LEGAL = 'legal';
}
