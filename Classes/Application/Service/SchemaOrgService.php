<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

use Koh\StructuredContent\Domain\Contract\ClaimsPageEntityInterface;
use Koh\StructuredContent\Domain\Contract\PageEntityGeneratorInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

/**
 * Runs the schema generators. Structured data is an addition to the page:
 * a generator that fails is skipped, it must never turn the page into an
 * error page. The failures of the last run are kept for the caller to log
 * (this layer does not depend on a logger).
 */
final class SchemaOrgService
{
    /** @var list<array{generator: class-string, exception: \Throwable}> */
    private array $failures = [];

    /** @var list<SchemaGeneratorInterface> */
    private readonly array $sortedGenerators;

    /**
     * @param iterable<SchemaGeneratorInterface> $generators
     */
    public function __construct(iterable $generators)
    {
        $generatorList = [];
        foreach ($generators as $generator) {
            $generatorList[] = $generator;
        }

        usort($generatorList, static fn(SchemaGeneratorInterface $a, SchemaGeneratorInterface $b): int => $b->getPriority() <=> $a->getPriority());

        $this->sortedGenerators = $generatorList;
    }

    /**
     * Runs every generator that handles the page, in priority order; the
     * first document of a schema type wins.
     *
     * When a generator claims the page entity of the URL
     * (ClaimsPageEntityInterface) and produces data, the documents of the
     * generic page-entity generators (PageEntityGeneratorInterface: WebPage,
     * Article) are dropped — one URL, one page entity. Those that come after
     * the claim are not run at all; the claim also holds against one with a
     * higher priority.
     *
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     *
     * @return list<JsonLdDocument>
     */
    public function generateAll(array $pageData, array $contentElements): array
    {
        $this->failures = [];
        /** @var list<array{document: JsonLdDocument, yields: bool}> $results */
        $results = [];
        $pageEntityClaimed = false;

        foreach ($this->sortedGenerators as $generator) {
            $yields = $generator instanceof PageEntityGeneratorInterface;
            if ($yields && $pageEntityClaimed) {
                continue;
            }
            $document = $this->run($generator, $pageData, $contentElements);
            if (null === $document) {
                continue;
            }
            if ($generator instanceof ClaimsPageEntityInterface && [] !== $document->data) {
                $pageEntityClaimed = true;
            }
            $results[] = ['document' => $document, 'yields' => $yields];
        }

        // Dropped documents take no part in the type check: a ProfilePage
        // claim must not make a dropped node shadow a later one of its type.
        $documents = [];
        $seenTypes = [];
        foreach ($results as ['document' => $document, 'yields' => $yields]) {
            if (($pageEntityClaimed && $yields) || in_array($document->schemaType, $seenTypes, true)) {
                continue;
            }
            $seenTypes[] = $document->schemaType;
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generateFirst(array $pageData, array $contentElements): ?JsonLdDocument
    {
        $this->failures = [];
        foreach ($this->sortedGenerators as $generator) {
            $document = $this->run($generator, $pageData, $contentElements);
            if (null !== $document) {
                return $document;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    private function run(SchemaGeneratorInterface $generator, array $pageData, array $contentElements): ?JsonLdDocument
    {
        try {
            if (!$generator->canHandle($pageData, $contentElements)) {
                return null;
            }

            return $generator->generate($pageData, $contentElements);
        } catch (\Throwable $exception) {
            $this->failures[] = ['generator' => $generator::class, 'exception' => $exception];

            return null;
        }
    }

    /**
     * Generators that threw during the last generateAll()/generateFirst().
     *
     * @return list<array{generator: class-string, exception: \Throwable}>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
