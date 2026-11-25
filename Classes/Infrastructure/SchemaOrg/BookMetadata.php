<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Book facts an editor writes as free text into a card, such as
 * "Erschienen 2026 · ISBN 979-8258303066".
 *
 * There is no field for the ISBN or the publication year; both share one
 * text line. Only values that are unambiguous are taken: an ISBN must pass
 * its check digit, a year must stand on its own and not be part of the ISBN.
 */
final class BookMetadata
{
    /**
     * The ISBN in the text, normalised to digits (and a trailing X for an
     * ISBN-10), or '' when there is none.
     *
     * A number marked "ISBN" (also "ISBN-10:", "ISBN-13:") may be an ISBN-10
     * or ISBN-13; an unmarked number is only taken as a 978/979 ISBN-13.
     * Spaces and dashes are stripped.
     */
    public static function isbn(string $text): string
    {
        return self::findIsbn($text)['isbn'];
    }

    /**
     * The first four-digit year (1450–2099) in the text outside the ISBN, or
     * '' when there is none.
     */
    public static function year(string $text): string
    {
        $match = self::findIsbn($text)['match'];
        if ('' !== $match) {
            $text = str_replace($match, ' ', $text);
        }

        return 1 === preg_match('/(?<![\d\-])(14[5-9]\d|1[5-9]\d{2}|20\d{2})(?![\d\-])/', $text, $matches)
            ? $matches[1]
            : '';
    }

    /**
     * Shortens plain text to at most $maxLength characters, cutting at the
     * last word boundary and marking the cut with an ellipsis.
     */
    public static function shorten(string $text, int $maxLength): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxLength - 1);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space > 0) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n\r\0\x0B,;:-–—") . '…';
    }

    private const ISBN_MARKER = 'ISBN(?:[\s\-]?1[03])?\s*:?\s*';
    private const ISBN13 = '97[89](?:[\s\-]?\d){10}(?!\d)';
    private const ISBN10 = '(?:\d[\s\-]?){9}[\dXx](?![\dXx])';

    /**
     * The ISBN and the text it was read from (removed before looking for the
     * year, so that a group of four digits inside the ISBN is not taken for
     * one).
     *
     * @return array{isbn: string, match: string}
     */
    private static function findIsbn(string $text): array
    {
        $patterns = [
            // Marked: "ISBN", "ISBN-13:", "ISBN 10".
            ['/' . self::ISBN_MARKER . '(' . self::ISBN13 . ')/i', true],
            ['/' . self::ISBN_MARKER . '(' . self::ISBN10 . ')/i', true],
            // Unmarked: only a 978/979 number is distinctive enough.
            ['/(?<![\d\-])(' . self::ISBN13 . ')/', false],
        ];
        foreach ($patterns as [$pattern, $marked]) {
            if (false === preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($all as $match) {
                $isbn = strtoupper((string) preg_replace('/[\s\-]/', '', $match[1]));
                $valid = 13 === strlen($isbn) ? self::isValidIsbn13($isbn) : ($marked && self::isValidIsbn10($isbn));
                if ($valid) {
                    return ['isbn' => $isbn, 'match' => $match[0]];
                }
            }
        }

        return ['isbn' => '', 'match' => ''];
    }

    private static function isValidIsbn13(string $isbn): bool
    {
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $isbn[$i] * (0 === $i % 2 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $isbn[12];
    }

    private static function isValidIsbn10(string $isbn): bool
    {
        $sum = 0;
        for ($i = 0; $i < 10; ++$i) {
            $digit = 'X' === $isbn[$i] ? 10 : (int) $isbn[$i];
            $sum += $digit * (10 - $i);
        }

        return 0 === $sum % 11;
    }
}
