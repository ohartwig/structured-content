<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Domain\Model;

use Koh\StructuredContent\Domain\Model\PageDates;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The dates a page is presented with.
 *
 * A post written ahead of time and released via starttime kept its crdate as
 * datePublished — the day it was written, which nobody saw it on. The site
 * shows the release date (`lastUpdated`, else the creation date); the
 * structured data says the same.
 */
final class PageDatesTest extends TestCase
{
    private const WRITTEN = 1_700_000_000;
    private const RELEASED = 1_720_000_000;
    private const UPDATED = 1_740_000_000;
    private const EDITED = 1_760_000_000;

    #[Test]
    public function theLastUpdatedDateIsThePublicationDate(): void
    {
        $dates = PageDates::fromPage(['crdate' => self::WRITTEN, 'starttime' => self::RELEASED, 'lastUpdated' => self::UPDATED]);

        self::assertSame(self::UPDATED, $dates->published);
    }

    #[Test]
    public function withoutItTheStartOfPublicationIs(): void
    {
        self::assertSame(self::RELEASED, PageDates::fromPage(['crdate' => self::WRITTEN, 'starttime' => self::RELEASED, 'lastUpdated' => 0])->published);
    }

    #[Test]
    public function withoutEitherTheCreationDateIs(): void
    {
        self::assertSame(self::WRITTEN, PageDates::fromPage(['crdate' => self::WRITTEN, 'starttime' => 0])->published);
    }

    #[Test]
    public function withoutAnyDateThereIsNone(): void
    {
        $dates = PageDates::fromPage([]);

        self::assertSame(0, $dates->published);
        self::assertSame(0, $dates->modified);
        self::assertSame([], $dates->toSchemaProperties());
    }

    /**
     * MariaDB returns integers as strings; junk and negative values count as
     * no date.
     */
    #[Test]
    public function databaseStringsAreReadAsNumbers(): void
    {
        $dates = PageDates::fromPage(['crdate' => (string) self::WRITTEN, 'lastUpdated' => 'soon', 'starttime' => '-5', 'tstamp' => (string) self::EDITED]);

        self::assertSame(self::WRITTEN, $dates->published);
        self::assertSame(self::EDITED, $dates->modified);
    }

    /**
     * Modified is the newer of the page's own change and the last change of
     * the content rendered on it.
     */
    #[Test]
    public function modifiedIsTheNewestChangeOfPageAndContent(): void
    {
        self::assertSame(self::EDITED, PageDates::fromPage(['crdate' => self::WRITTEN, 'tstamp' => self::UPDATED, 'SYS_LASTCHANGED' => self::EDITED])->modified);
        self::assertSame(self::EDITED, PageDates::fromPage(['crdate' => self::WRITTEN, 'tstamp' => self::EDITED, 'SYS_LASTCHANGED' => self::UPDATED])->modified);
    }

    /**
     * A scheduled post was last saved before it went live; it is not
     * modified before it was published.
     */
    #[Test]
    public function modifiedIsNeverBeforePublished(): void
    {
        $dates = PageDates::fromPage(['crdate' => self::WRITTEN, 'tstamp' => self::WRITTEN, 'starttime' => self::RELEASED]);

        self::assertSame(self::RELEASED, $dates->published);
        self::assertSame(self::RELEASED, $dates->modified);
    }

    #[Test]
    public function aMissingModificationStaysMissing(): void
    {
        self::assertSame(0, PageDates::fromPage(['crdate' => self::WRITTEN])->modified);
    }

    #[Test]
    public function theSchemaPropertiesAreIso8601(): void
    {
        self::assertSame(
            ['datePublished' => date('c', self::RELEASED), 'dateModified' => date('c', self::EDITED)],
            (new PageDates(self::RELEASED, self::EDITED))->toSchemaProperties(),
        );
        self::assertSame(['datePublished' => date('c', self::RELEASED)], (new PageDates(self::RELEASED, 0))->toSchemaProperties());
        self::assertSame(['dateModified' => date('c', self::EDITED)], (new PageDates(0, self::EDITED))->toSchemaProperties());
    }

    #[Test]
    public function pagesAreOrderedByPublicationThenCreationThenUid(): void
    {
        $pages = [
            ['uid' => 1, 'crdate' => self::WRITTEN],
            ['uid' => 2, 'crdate' => self::WRITTEN, 'starttime' => self::RELEASED],
            ['uid' => 3, 'crdate' => self::RELEASED],
            ['uid' => 4, 'crdate' => self::WRITTEN, 'lastUpdated' => self::UPDATED],
            ['uid' => 5, 'crdate' => self::WRITTEN],
        ];

        usort($pages, PageDates::newestFirst(...));

        self::assertSame([4, 3, 2, 5, 1], array_column($pages, 'uid'));
    }
}
