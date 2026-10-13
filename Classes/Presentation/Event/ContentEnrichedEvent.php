<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\Event;

use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class ContentEnrichedEvent
{
    /** @var list<JsonLdDocument> */
    private array $documents;

    /**
     * @param list<JsonLdDocument>       $documents
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function __construct(
        array $documents,
        private readonly array $pageData,
        private readonly int $pageUid,
        private readonly array $contentElements = [],
    ) {
        $this->documents = $documents;
    }

    /** @return list<JsonLdDocument> */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function addDocument(JsonLdDocument $document): void
    {
        $this->documents[] = $document;
    }

    /** @return array<string, mixed> */
    public function getPageData(): array
    {
        return $this->pageData;
    }

    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    /** @return list<array<string, mixed>> */
    public function getContentElements(): array
    {
        return $this->contentElements;
    }
}
