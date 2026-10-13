<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

interface ContextConfigurationProviderInterface
{
    public function getDefaultAudience(): string;

    public function getDefaultTone(): string;

    /**
     * @return list<string>
     */
    public function getDefaultChannels(): array;

    /**
     * @return list<string>|null
     */
    public function getFieldRestrictionsForTable(string $table, string $channel): ?array;
}
