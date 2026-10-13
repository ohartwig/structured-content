<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Exception;

final class InvalidRelationTypeException extends \InvalidArgumentException
{
    public static function forValue(string $value): self
    {
        return new self(
            message: sprintf('Invalid relation type: "%s"', $value),
        );
    }
}
