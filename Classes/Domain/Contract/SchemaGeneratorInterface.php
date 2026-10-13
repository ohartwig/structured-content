<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

use Koh\StructuredContent\Domain\Model\JsonLdDocument;

interface SchemaGeneratorInterface
{
    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool;

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument;

    public function getPriority(): int;
}
