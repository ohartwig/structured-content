<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

interface PageRootlineProviderInterface
{
    /**
     * Returns the rootline for a given page UID, ordered from the page itself to the root.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRootline(int $pageUid): array;
}
