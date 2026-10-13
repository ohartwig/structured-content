<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Model;

final readonly class ResolvedContext
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public string $audience,
        public string $tone,
        public array $channels,
        public string $inheritedFrom,
    ) {}

    public static function withDefaults(): self
    {
        return new self(
            audience: 'customer',
            tone: 'informational',
            channels: [],
            inheritedFrom: 'defaults',
        );
    }
}
