<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Enrichment;

use Koh\StructuredContent\Domain\Contract\FreshnessEnricherInterface;
use Koh\StructuredContent\Domain\Model\FreshnessSignal;
use Koh\StructuredContent\Domain\Model\PageDates;

final class FreshnessSignalEnricher implements FreshnessEnricherInterface
{
    /**
     * @param array<string, mixed> $pageData
     */
    public function enrich(array $pageData): FreshnessSignal
    {
        // The same dates the generators emit for the page (PageDates), so a
        // document that falls back to the page's dates agrees with the rest.
        $dates = PageDates::fromPage($pageData);
        $datePublished = $dates->published > 0 ? (new \DateTimeImmutable())->setTimestamp($dates->published) : null;
        $dateModified = $dates->modified > 0 ? (new \DateTimeImmutable())->setTimestamp($dates->modified) : null;

        $frequency = $this->calculateFrequency($pageData);

        return new FreshnessSignal($datePublished, $dateModified, $frequency);
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function calculateFrequency(array $pageData): string
    {
        $crdate = (int) ($pageData['crdate'] ?? 0);
        $tstamp = (int) ($pageData['tstamp'] ?? 0);

        if (0 === $crdate || 0 === $tstamp) {
            return 'monthly';
        }

        $daysSinceCreation = (time() - $crdate) / 86400;
        $daysSinceModification = (time() - $tstamp) / 86400;

        if ($daysSinceModification < 1) {
            return 'daily';
        }

        if ($daysSinceModification < 7) {
            return 'weekly';
        }

        if ($daysSinceCreation > 365 && $daysSinceModification > 90) {
            return 'yearly';
        }

        return 'monthly';
    }
}
