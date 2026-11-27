<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\SchemaOrgService;
use Koh\StructuredContent\Domain\Contract\ClaimsPageEntityInterface;
use Koh\StructuredContent\Domain\Contract\PageEntityGeneratorInterface;
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

    /**
     * @template T of SchemaGeneratorInterface
     *
     * @param class-string<T>      $interface
     * @param array<string, mixed> $data
     *
     * @return T
     */
    private function generator(string $interface, string $type, array $data, int $priority): SchemaGeneratorInterface
    {
        $generator = $this->createStub($interface);
        $generator->method('canHandle')->willReturn(true);
        $generator->method('generate')->willReturn(new JsonLdDocument($type, $data));
        $generator->method('getPriority')->willReturn($priority);

        return $generator;
    }

    /**
     * @param list<JsonLdDocument> $documents
     *
     * @return list<string>
     */
    private static function types(array $documents): array
    {
        return array_map(static fn (JsonLdDocument $document): string => $document->schemaType, $documents);
    }

    /**
     * One URL, one page entity: when the ProfilePage applies, the WebPage
     * and the Article of the container page are not emitted next to it.
     */
    #[Test]
    public function aClaimedPageEntityDropsTheGenericPageEntities(): void
    {
        $service = new SchemaOrgService([
            $this->generator(SchemaGeneratorInterface::class, 'Person', ['name' => 'Ada'], 80),
            $this->generator(ClaimsPageEntityInterface::class, 'ProfilePage', ['name' => 'Ada'], 75),
            $this->generator(PageEntityGeneratorInterface::class, 'BlogPosting', ['headline' => 'Authors'], 50),
            $this->generator(PageEntityGeneratorInterface::class, 'WebPage', ['name' => 'Authors'], 40),
            $this->generator(SchemaGeneratorInterface::class, 'WebSite', ['name' => 'Site'], 30),
        ]);

        self::assertSame(['Person', 'ProfilePage', 'WebSite'], self::types($service->generateAll([], [])));
    }

    /**
     * The claim does not depend on the order: a page-entity generator with a
     * higher priority than the claiming one is dropped as well.
     */
    #[Test]
    public function theClaimAlsoDropsAPageEntityOfHigherPriority(): void
    {
        $service = new SchemaOrgService([
            $this->generator(PageEntityGeneratorInterface::class, 'WebPage', ['name' => 'Authors'], 90),
            $this->generator(ClaimsPageEntityInterface::class, 'ProfilePage', ['name' => 'Ada'], 75),
        ]);

        self::assertSame(['ProfilePage'], self::types($service->generateAll([], [])));
    }

    /**
     * An empty ProfilePage (the person was not found) is no page entity and
     * claims nothing; the page keeps its WebPage.
     */
    #[Test]
    public function anEmptyDocumentClaimsNothing(): void
    {
        $service = new SchemaOrgService([
            $this->generator(ClaimsPageEntityInterface::class, 'ProfilePage', [], 75),
            $this->generator(PageEntityGeneratorInterface::class, 'WebPage', ['name' => 'Authors'], 40),
        ]);

        self::assertSame(['ProfilePage', 'WebPage'], self::types($service->generateAll([], [])));
    }

    /**
     * Without a claim the page entities are emitted as before.
     */
    #[Test]
    public function withoutAClaimThePageEntitiesStay(): void
    {
        $service = new SchemaOrgService([
            $this->generator(PageEntityGeneratorInterface::class, 'BlogPosting', ['headline' => 'Post'], 50),
            $this->generator(PageEntityGeneratorInterface::class, 'WebPage', ['name' => 'Post'], 40),
        ]);

        self::assertSame(['BlogPosting', 'WebPage'], self::types($service->generateAll([], [])));
    }

    /**
     * A dropped node does not shadow a later document of the same type.
     */
    #[Test]
    public function aDroppedDocumentDoesNotShadowALaterOneOfItsType(): void
    {
        $service = new SchemaOrgService([
            $this->generator(ClaimsPageEntityInterface::class, 'ProfilePage', ['name' => 'Ada'], 75),
            $this->generator(PageEntityGeneratorInterface::class, 'Article', ['headline' => 'Authors'], 50),
            $this->generator(SchemaGeneratorInterface::class, 'Article', ['headline' => 'Other'], 45),
        ]);

        $documents = $service->generateAll([], []);

        self::assertSame(['ProfilePage', 'Article'], self::types($documents));
        self::assertSame(['headline' => 'Other'], $documents[1]->data);
    }
}
