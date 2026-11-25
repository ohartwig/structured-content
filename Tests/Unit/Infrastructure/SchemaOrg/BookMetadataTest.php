<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\BookMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ISBN and publication year share one free-text line of a book card. Only
 * unambiguous values may leave it: a wrong ISBN in the schema is worse than
 * none, and a year must not be read out of the ISBN's digit groups.
 */
final class BookMetadataTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function isbnCases(): iterable
    {
        yield 'marked 979 with dash' => ['Erschienen 2026 · ISBN 979-8258303066', '9798258303066'];
        yield 'marked 978 with groups' => ['ISBN-13: 978-3-16-148410-0', '9783161484100'];
        yield 'marked with spaces' => ['ISBN 978 3 16 148410 0', '9783161484100'];
        yield 'unmarked 13 digits' => ['Taschenbuch, 9783161484100', '9783161484100'];
        yield 'marked ISBN-10' => ['ISBN-10: 3-16-148410-X', '316148410X'];
        yield 'marked ISBN-10 digits only' => ['ISBN 0306406152', '0306406152'];
        yield 'number after the ISBN is not part of it' => ['ISBN 979-8258303066 2026', '9798258303066'];
        yield 'wrong check digit' => ['ISBN 979-8258303067', ''];
        yield 'unmarked ten digits are no ISBN' => ['Tel. 0306406152', ''];
        yield 'no ISBN' => ['Erschienen 2026', ''];
        yield 'empty' => ['', ''];
    }

    #[Test]
    #[DataProvider('isbnCases')]
    public function isbnIsReadFromTheText(string $text, string $expected): void
    {
        self::assertSame($expected, BookMetadata::isbn($text));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function yearCases(): iterable
    {
        yield 'year before the ISBN' => ['Erschienen 2026 · ISBN 979-8258303066', '2026'];
        yield 'year after the ISBN' => ['ISBN 978-3-16-148410-0, published 2019', '2019'];
        yield 'digit group of the ISBN is no year' => ['ISBN 978-3-1999-4567-3', ''];
        yield 'year only' => ['2024', '2024'];
        yield 'five digits are no year' => ['Auflage 20245', ''];
        yield 'no year' => ['Taschenbuch', ''];
    }

    #[Test]
    #[DataProvider('yearCases')]
    public function yearIsReadFromTheText(string $text, string $expected): void
    {
        self::assertSame($expected, BookMetadata::year($text));
    }

    #[Test]
    public function shortTextIsKept(): void
    {
        self::assertSame('A short text.', BookMetadata::shorten(' A short text. ', 300));
    }

    #[Test]
    public function longTextIsCutAtAWordBoundary(): void
    {
        $shortened = BookMetadata::shorten('Ein Buch über Pflege, Würde und Alltag im Heim', 20);

        self::assertSame('Ein Buch über…', $shortened);
        self::assertLessThanOrEqual(20, mb_strlen($shortened));
    }
}
