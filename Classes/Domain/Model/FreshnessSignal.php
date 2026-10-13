<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Model;

final readonly class FreshnessSignal
{
    public function __construct(
        public ?\DateTimeImmutable $datePublished = null,
        public ?\DateTimeImmutable $dateModified = null,
        public string $frequency = 'monthly',
    ) {}

    /**
     * @return array<string, string>
     */
    public function toSchemaProperties(): array
    {
        $properties = [];

        if (null !== $this->datePublished) {
            $properties['datePublished'] = $this->datePublished->format('c');
        }

        if (null !== $this->dateModified) {
            $properties['dateModified'] = $this->dateModified->format('c');
        }

        return $properties;
    }
}
