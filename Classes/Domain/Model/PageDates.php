<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Model;

/**
 * Publication and modification date of a page, as every generator emits
 * them for the page (`datePublished` / `dateModified`).
 *
 * Published is the date the page is presented with: the editor-maintained
 * "last updated" date (`lastUpdated`) when set, else the start of
 * publication (`starttime`) when set, else the creation date (`crdate`).
 * A post written ahead of time and released via starttime keeps the
 * `crdate` of the day it was written; sites show it with its release date —
 * `lastUpdated`, or `starttime` — and so must the structured data, or the
 * BlogPosting claims a date nobody saw the post on.
 *
 * Modified is the last change of the page or of its content:
 * `SYS_LASTCHANGED` (which the frontend keeps at the newest timestamp of the
 * page and the content rendered on it) or the page's `tstamp`, whichever is
 * newer — `tstamp` alone does not move when only a content element is
 * edited. It is never earlier than the publication date: a scheduled post
 * was last saved before it went live.
 */
final readonly class PageDates
{
    public function __construct(
        public int $published,
        public int $modified,
    ) {}

    /**
     * @param array<string, mixed> $page a pages row (or overlaid page record)
     */
    public static function fromPage(array $page): self
    {
        $published = 0;
        foreach (['lastUpdated', 'starttime', 'crdate'] as $field) {
            $value = self::timestamp($page, $field);
            if ($value > 0) {
                $published = $value;

                break;
            }
        }

        $modified = max(self::timestamp($page, 'tstamp'), self::timestamp($page, 'SYS_LASTCHANGED'));
        if ($modified > 0 && $modified < $published) {
            $modified = $published;
        }

        return new self($published, $modified);
    }

    /**
     * Newest publication first; pages of the same date by creation date,
     * then by uid, so the order is stable.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function newestFirst(array $a, array $b): int
    {
        return [self::fromPage($b)->published, self::timestamp($b, 'crdate'), self::timestamp($b, 'uid')]
            <=> [self::fromPage($a)->published, self::timestamp($a, 'crdate'), self::timestamp($a, 'uid')];
    }

    /**
     * `datePublished` / `dateModified` as ISO 8601, each only when known.
     *
     * @return array<string, string>
     */
    public function toSchemaProperties(): array
    {
        $properties = [];
        if ($this->published > 0) {
            $properties['datePublished'] = date('c', $this->published);
        }
        if ($this->modified > 0) {
            $properties['dateModified'] = date('c', $this->modified);
        }

        return $properties;
    }

    /**
     * @param array<string, mixed> $page
     */
    private static function timestamp(array $page, string $field): int
    {
        $value = $page[$field] ?? 0;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }
}
