<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

use Koh\StructuredContent\Domain\Model\FreshnessSignal;

interface FreshnessEnricherInterface
{
    /**
     * @param array<string, mixed> $pageData
     */
    public function enrich(array $pageData): FreshnessSignal;
}
