<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\SchemaOrgService;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaOrgServiceTest extends TestCase
{
    #[Test]
    public function generateAllReturnsDocumentsFromMatchingGenerators(): void
    {
        $generator1 = $this->createMock(SchemaGeneratorInterface::class);
        $generator1->method('canHandle')->willReturn(true);
        $generator1->method('generate')->willReturn(new JsonLdDocument('Article', ['headline' => 'Test']));
        $generator1->method('getPriority')->willReturn(50);

        $generator2 = $this->createMock(SchemaGeneratorInterface::class);
        $generator2->method('canHandle')->willReturn(false);
        $generator2->method('getPriority')->willReturn(60);

        $service = new SchemaOrgService([$generator1, $generator2]);

        $documents = $service->generateAll(['title' => 'Test'], []);

        self::assertCount(1, $documents);
        self::assertSame('Article', $documents[0]->schemaType);
    }

    #[Test]
    public function generateAllSortsByPriorityDescending(): void
    {
        $lowPriority = $this->createMock(SchemaGeneratorInterface::class);
        $lowPriority->method('canHandle')->willReturn(true);
        $lowPriority->method('generate')->willReturn(new JsonLdDocument('Product', []));
        $lowPriority->method('getPriority')->willReturn(30);

        $highPriority = $this->createMock(SchemaGeneratorInterface::class);
        $highPriority->method('canHandle')->willReturn(true);
        $highPriority->method('generate')->willReturn(new JsonLdDocument('FAQPage', []));
        $highPriority->method('getPriority')->willReturn(70);

        $service = new SchemaOrgService([$lowPriority, $highPriority]);

        $documents = $service->generateAll([], []);

        self::assertSame('FAQPage', $documents[0]->schemaType);
        self::assertSame('Product', $documents[1]->schemaType);
    }

    #[Test]
    public function generateFirstReturnsHighestPriorityMatch(): void
    {
        $low = $this->createMock(SchemaGeneratorInterface::class);
        $low->method('canHandle')->willReturn(true);
        $low->method('generate')->willReturn(new JsonLdDocument('Article', []));
        $low->method('getPriority')->willReturn(30);

        $high = $this->createMock(SchemaGeneratorInterface::class);
        $high->method('canHandle')->willReturn(true);
        $high->method('generate')->willReturn(new JsonLdDocument('FAQPage', []));
        $high->method('getPriority')->willReturn(70);

        $service = new SchemaOrgService([$low, $high]);

        $result = $service->generateFirst([], []);

        self::assertNotNull($result);
        self::assertSame('FAQPage', $result->schemaType);
    }

    #[Test]
    public function generateFirstReturnsNullWhenNoMatch(): void
    {
        $generator = $this->createMock(SchemaGeneratorInterface::class);
        $generator->method('canHandle')->willReturn(false);
        $generator->method('getPriority')->willReturn(50);

        $service = new SchemaOrgService([$generator]);

        self::assertNull($service->generateFirst([], []));
    }

    /**
     * A broken generator must never turn the page into an error page: a
     * failing DefinedTerm query took every glossary term page down with a 500.
     */
    #[Test]
    public function aFailingGeneratorIsSkippedAndTheOthersStillRender(): void
    {
        $broken = $this->createMock(SchemaGeneratorInterface::class);
        $broken->method('canHandle')->willReturn(true);
        $broken->method('generate')->willThrowException(new \RuntimeException('Unknown column'));
        $broken->method('getPriority')->willReturn(90);

        $working = $this->createMock(SchemaGeneratorInterface::class);
        $working->method('canHandle')->willReturn(true);
        $working->method('generate')->willReturn(new JsonLdDocument('WebPage', []));
        $working->method('getPriority')->willReturn(10);

        $service = new SchemaOrgService([$broken, $working]);

        self::assertSame(['WebPage'], array_map(static fn (JsonLdDocument $d): string => $d->schemaType, $service->generateAll([], [])));
        self::assertSame('WebPage', $service->generateFirst([], [])?->schemaType);
        self::assertCount(1, $service->failures(), 'the failure is kept for the caller to log');
        self::assertSame('Unknown column', $service->failures()[0]['exception']->getMessage());
    }
}
