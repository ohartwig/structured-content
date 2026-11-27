<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\Enrichment;

use Koh\StructuredContent\Infrastructure\Enrichment\FreshnessSignalEnricher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FreshnessSignalEnricherTest extends TestCase
{
    #[Test]
    public function thePageTimestampsBecomeThePublishedAndModifiedDates(): void
    {
        $signal = (new FreshnessSignalEnricher())->enrich(['crdate' => 1_782_896_400, 'tstamp' => 1_782_982_800]);

        self::assertSame(1_782_896_400, $signal->datePublished?->getTimestamp());
        self::assertSame(1_782_982_800, $signal->dateModified?->getTimestamp());
    }

    #[Test]
    public function aMissingOrZeroTimestampLeavesTheDateUnset(): void
    {
        $signal = (new FreshnessSignalEnricher())->enrich(['crdate' => 0]);

        self::assertNull($signal->datePublished);
        self::assertNull($signal->dateModified);
    }

    /**
     * The fallback dates agree with the dates the generators emit (PageDates).
     */
    #[Test]
    public function theFallbackUsesThePageDatesOfTheGenerators(): void
    {
        $signal = (new FreshnessSignalEnricher())->enrich(['crdate' => 1_700_000_000, 'starttime' => 1_720_000_000, 'tstamp' => 1_700_000_000]);

        self::assertSame(1_720_000_000, $signal->datePublished?->getTimestamp());
        self::assertSame(1_720_000_000, $signal->dateModified?->getTimestamp());
    }
}
