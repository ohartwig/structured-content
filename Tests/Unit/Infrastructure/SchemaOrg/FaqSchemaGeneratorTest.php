<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\FaqSchemaGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class FaqSchemaGeneratorTest extends TestCase
{
    private FaqSchemaGenerator $generator;

    protected function setUp(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $context = $this->createMock(Context::class);
        $this->generator = new FaqSchemaGenerator($connectionPool, $context);
    }

    #[Test]
    public function canHandleReturnsTrueForFaqContent(): void
    {
        self::assertTrue($this->generator->canHandle(
            [],
            [['CType' => 'text', 'header' => 'FAQ', 'bodytext' => 'What? Answer. Why? Because.']],
        ));
    }

    #[Test]
    public function canHandleReturnsFalseForNonFaqContent(): void
    {
        self::assertFalse($this->generator->canHandle(
            [],
            [['CType' => 'text', 'header' => 'About Us', 'bodytext' => 'We are a company.']],
        ));
    }

    #[Test]
    public function canHandleDetectsMultipleQuestionMarks(): void
    {
        self::assertTrue($this->generator->canHandle(
            [],
            [['CType' => 'text', 'header' => 'Help', 'bodytext' => 'What is this? How does it work? Can I use it? Yes.']],
        ));
    }

    #[Test]
    public function canHandleReturnsTrueForFaqCType(): void
    {
        self::assertTrue($this->generator->canHandle(
            [],
            [['CType' => 'moselwal_faq', 'header' => 'FAQ', 'bodytext' => '']],
        ));
    }

    #[Test]
    public function generateExtractsQaPairsFromText(): void
    {
        $document = $this->generator->generate(
            ['title' => 'FAQ Page'],
            [['CType' => 'text', 'header' => 'FAQ', 'bodytext' => "What is this?\nIt is a tool.\nHow does it work?\nVery well."]],
        );

        self::assertSame('FAQPage', $document->schemaType);
        $data = json_decode($document->toJsonLd(), true);
        self::assertArrayHasKey('mainEntity', $data);
        self::assertNotEmpty($data['mainEntity']);
    }

    #[Test]
    public function generateHandlesEmptyBodytext(): void
    {
        // Spec: FAQPage MUSS mindestens eine Question im mainEntity haben.
        // Wenn der Generator keine echten Q&A-Pairs extrahieren kann,
        // liefert er ein leeres data-Array zurueck — der Middleware-Filter
        // entfernt das Document dann automatisch aus dem JSON-LD-Output.
        $document = $this->generator->generate(
            ['title' => 'Empty FAQ'],
            [['CType' => 'text', 'header' => 'FAQ', 'bodytext' => '']],
        );

        self::assertSame('FAQPage', $document->schemaType);
        self::assertSame([], $document->data);
    }

    #[Test]
    public function generateSanitizesHtmlInTitle(): void
    {
        $document = $this->generator->generate(
            ['title' => '<h1 style="margin-left:0px;">FAQ</h1>'],
            [['CType' => 'text', 'header' => 'FAQ', 'bodytext' => "What?\nAnswer."]],
        );

        $data = json_decode($document->toJsonLd(), true);
        self::assertSame('FAQ', $data['name']);
    }
}
