<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Koh\StructuredContent\Domain\Contract\PageRootlineProviderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;

final readonly class Typo3PageRootlineProvider implements PageRootlineProviderInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRootline(int $pageUid): array
    {
        $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $pageUid);
        try {
            return $rootlineUtility->get();
        } catch (\RuntimeException) {
            return [];
        }
    }
}
