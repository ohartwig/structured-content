<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Enum;

enum RelationType: string
{
    case RELATED_TO = 'related_to';
    case REPLACES = 'replaces';
    case REQUIRES = 'requires';
    case EXPLAINS = 'explains';
    case BELONGS_TO = 'belongs_to';
    case CONTRADICTS = 'contradicts';
    case ALTERNATIVE_TO = 'alternative_to';

    public function isUnidirectional(): bool
    {
        return match ($this) {
            self::REPLACES, self::REQUIRES, self::EXPLAINS, self::BELONGS_TO => true,
            self::RELATED_TO, self::CONTRADICTS, self::ALTERNATIVE_TO => false,
        };
    }

    public function isBidirectional(): bool
    {
        return !$this->isUnidirectional();
    }

    public function label(): string
    {
        return match ($this) {
            self::RELATED_TO => 'Related To',
            self::REPLACES => 'Replaces',
            self::REQUIRES => 'Requires',
            self::EXPLAINS => 'Explains',
            self::BELONGS_TO => 'Belongs To',
            self::CONTRADICTS => 'Contradicts',
            self::ALTERNATIVE_TO => 'Alternative To',
        };
    }
}
