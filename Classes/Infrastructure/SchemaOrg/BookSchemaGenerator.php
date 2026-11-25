<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;

/**
 * Emits a Schema.org/Book per card of a book showcase element.
 *
 * Which content elements show books is site-specific: the site setting
 * `structuredContent.schema.bookShowcases` lists them as "CType:inlineField"
 * pairs, the inline field being the moselwal_card collection that holds the
 * books. Card fields map to the book as follows:
 *
 *  - header       → name
 *  - bodytext2    → alternativeHeadline (subtitle)
 *  - bodytext     → description (plain text, shortened)
 *  - image        → image
 *  - cta_link     → url (where to buy the book)
 *  - works_for    → isbn and datePublished, read from text such as
 *                   "Erschienen 2026 · ISBN 979-8258303066"
 *
 * The author is the site's founder (`founderPersonSlug`), referenced by the
 * @id under which OrganizationSchemaGenerator emits the Person node.
 *
 * Several books go out as a @graph of Book nodes, which the middleware
 * flattens into the page graph.
 */
final class BookSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;
    use PackagesGridCardLoaderTrait;

    private const DESCRIPTION_MAX_LENGTH = 300;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
        private readonly FileRepository $fileRepository,
    ) {}

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $showcases = $this->showcases($pageData);
        if ([] === $showcases) {
            return false;
        }
        foreach ($contentElements as $element) {
            if (null !== $this->inlineFieldOf($element, $showcases)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $showcases = $this->showcases($pageData);
        $pageUrl = $this->pageUrl($pageData);
        $fileBase = SiteFileUrl::baseFromPageData($pageData);
        $language = trim((string) ($pageData['_language'] ?? ''));
        $author = $this->founderReference($pageData);

        $books = [];
        foreach ($contentElements as $element) {
            $field = $this->inlineFieldOf($element, $showcases);
            if (null === $field) {
                continue;
            }
            foreach ($this->loadCards($element) as $card) {
                if (!self::isVisibleCard($card, $field)) {
                    continue;
                }
                $book = $this->cardToBook($card, $pageUrl, $fileBase, $language, $author);
                if (null !== $book) {
                    $books[] = $book;
                }
            }
        }

        if ([] === $books) {
            return new JsonLdDocument('Book', []);
        }
        if (1 === count($books)) {
            return new JsonLdDocument('Book', $books[0]);
        }

        return new JsonLdDocument('Book', ['@graph' => $books]);
    }

    public function getPriority(): int
    {
        return 36;
    }

    /**
     * The configured showcases as CType => inline field.
     *
     * The setting is a stringlist of "CType:inlineField" pairs; a
     * comma-separated string is accepted as well. Entries without both parts
     * are ignored.
     *
     * @param array<string, mixed> $pageData
     *
     * @return array<string, string>
     */
    private function showcases(array $pageData): array
    {
        $raw = $pageData['_siteSettings']['structuredContent']['schema']['bookShowcases'] ?? [];
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $showcases = [];
        foreach ($raw as $entry) {
            if (!is_string($entry) || !str_contains($entry, ':')) {
                continue;
            }
            [$cType, $field] = array_map('trim', explode(':', $entry, 2));
            if ('' !== $cType && '' !== $field) {
                $showcases[$cType] = $field;
            }
        }

        return $showcases;
    }

    /**
     * The inline field holding the books when the element is a visible book
     * showcase, null otherwise.
     *
     * @param array<string, mixed>  $element
     * @param array<string, string> $showcases
     */
    private function inlineFieldOf(array $element, array $showcases): ?string
    {
        $field = $showcases[(string) ($element['CType'] ?? '')] ?? null;
        if (null === $field || self::isHiddenOrDeleted($element)) {
            return null;
        }

        return $field;
    }

    /**
     * The query restrictions already drop hidden and deleted rows; this is
     * the guard for rows that reach the generator by another path.
     *
     * @param array<string, mixed> $row
     */
    private static function isHiddenOrDeleted(array $row): bool
    {
        return 1 === (int) ($row['hidden'] ?? 0) || 1 === (int) ($row['deleted'] ?? 0);
    }

    /**
     * A card belongs to the showcase when it hangs on the configured inline
     * field: an element may carry more than one moselwal_card collection.
     *
     * @param array<string, mixed> $card
     */
    private static function isVisibleCard(array $card, string $field): bool
    {
        if (self::isHiddenOrDeleted($card)) {
            return false;
        }
        $fieldname = (string) ($card['fieldname'] ?? '');

        return '' === $fieldname || $fieldname === $field;
    }

    /**
     * @param array<string, mixed>      $card
     * @param array<string, mixed>|null $author
     *
     * @return array<string, mixed>|null
     */
    private function cardToBook(array $card, string $pageUrl, string $fileBase, string $language, ?array $author): ?array
    {
        $name = $this->sanitizeText((string) ($card['header'] ?? ''));
        if ('' === $name) {
            return null;
        }

        $facts = (string) ($card['works_for'] ?? '');
        $isbn = BookMetadata::isbn($facts);
        $uid = (int) ($card['uid'] ?? 0);
        $anchor = '' !== $isbn ? $isbn : trim(self::slugify($name) . ($uid > 0 ? '-' . $uid : ''), '-');

        $book = [
            '@type' => 'Book',
            '@id' => $pageUrl . '#book-' . $anchor,
            'name' => $name,
            'alternativeHeadline' => $this->sanitizeText((string) ($card['bodytext2'] ?? '')),
            'description' => BookMetadata::shorten(
                $this->sanitizeText((string) ($card['bodytext'] ?? '')),
                self::DESCRIPTION_MAX_LENGTH,
            ),
            'image' => $this->resolveCardImage($uid, $fileBase),
            'url' => self::purchaseUrl((string) ($card['cta_link'] ?? '')),
            'isbn' => $isbn,
            'datePublished' => BookMetadata::year($facts),
            'inLanguage' => $language,
            'author' => $author,
        ];

        return array_filter($book, SchemaValues::present(...));
    }

    /**
     * The founder as the books' author, by reference: the full Person node
     * is emitted by OrganizationSchemaGenerator under the same @id.
     *
     * @param array<string, mixed> $pageData
     *
     * @return array<string, string>|null
     */
    private function founderReference(array $pageData): ?array
    {
        $slug = trim((string) ($pageData['_siteSettings']['structuredContent']['schema']['founderPersonSlug'] ?? ''), " \t\n\r\0\x0B/");
        $entityBase = EntityBase::fromPageData($pageData);
        if ('' === $slug || '' === $entityBase) {
            return null;
        }

        return [
            '@type' => 'Person',
            '@id' => $entityBase . PersonSchemaBuilder::AUTHOR_PATH . '/' . $slug . '#person',
        ];
    }

    /**
     * The absolute purchase URL of a TYPO3 link field value. The value may
     * carry target, class and title after the URL ("https://… _blank - ");
     * only an absolute http(s) URL is used — a t3:// reference cannot be
     * resolved here and would mislead a crawler.
     */
    private static function purchaseUrl(string $link): string
    {
        $url = trim(explode(' ', trim($link), 2)[0]);

        return 1 === preg_match('#^https?://#i', $url) ? $url : '';
    }

    /**
     * @param array<string, mixed> $pageData
     */
    private function pageUrl(array $pageData): string
    {
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $slug = (string) ($pageData['slug'] ?? '/');

        return '' === $slug || '/' === $slug ? $siteBase . '/' : $siteBase . '/' . ltrim($slug, '/');
    }

    /**
     * The absolute URL of the card's first image.
     */
    private function resolveCardImage(int $cardUid, string $fileBase): string
    {
        if ($cardUid <= 0) {
            return '';
        }

        try {
            $references = $this->fileRepository->findByRelation(self::CARD_TABLE, 'moselwal_card_image', $cardUid);
        } catch (\Throwable) {
            return '';
        }
        if ([] === $references) {
            return '';
        }

        return SiteFileUrl::resolve((string) $references[0]->getPublicUrl(), $fileBase);
    }
}
