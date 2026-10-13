<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

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
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     *
     * @return list<JsonLdDocument>
     */
    public function generateAll(array $pageData, array $contentElements): array
    {
        $this->failures = [];
        $documents = [];
        $seenTypes = [];

        foreach ($this->sortedGenerators as $generator) {
            $document = $this->run($generator, $pageData, $contentElements);
            if (null !== $document && !isset($seenTypes[$document->schemaType])) {
                $seenTypes[$document->schemaType] = true;
                $documents[] = $document;
            }
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
