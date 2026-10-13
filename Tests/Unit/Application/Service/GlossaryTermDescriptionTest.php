<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Domain\Contract\GlossaryTermRepositoryInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a term page says about its term: the definition for Schema.org, and a
 * short version of it for the meta description.
 *
 * Descriptions are written as labelled paragraphs. Stripping the tags alone
 * made the label the first word of every description ("Definition. RCE …"),
 * and every term page carried the listing page's meta description.
 */
final class GlossaryTermDescriptionTest extends TestCase
{
    private ?string $description = null;

    /** @var array<string, mixed>|null */
    private ?array $term = ['uid' => 7, 'name' => 'RCE', 'url_segment' => 'rce'];

    private function service(): GlossaryTermService
    {
        $repository = $this->createStub(GlossaryTermRepositoryInterface::class);
        $repository->method('isAvailable')->willReturn(true);
        $repository->method('findTermByUid')->willReturnCallback(fn (): ?array => $this->term);
        $repository->method('findTermBySlug')->willReturnCallback(fn (): ?array => $this->term);
        $repository->method('findFirstDescription')->willReturnCallback(fn (): ?string => $this->description);

        return new GlossaryTermService($repository);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function descriptions(): iterable
    {
        yield 'run-in label with full stop' => [
            "<p><strong>Definition.</strong> RCE bezeichnet Schwachstellen.</p>\r\n<p><strong>Warum relevant.</strong> Weil.</p>",
            'RCE bezeichnet Schwachstellen.',
        ];
        yield 'run-in label with colon outside' => [
            '<p><b>Definition</b>: Remote code execution.</p>',
            'Remote code execution.',
        ];
        yield 'heading in front' => [
            '<h3>Definition</h3><p>Ein Vokabular.</p><p>Mehr.</p>',
            'Ein Vokabular.',
        ];
        yield 'bold term without punctuation stays' => [
            '<p><strong>RCE</strong> steht fuer Remote Code Execution.</p>',
            'RCE steht fuer Remote Code Execution.',
        ];
        yield 'label-only paragraph is skipped' => [
            '<p><strong>Definition.</strong></p><p>Der eigentliche Text &amp; mehr.</p>',
            'Der eigentliche Text & mehr.',
        ];
        yield 'plain text without markup' => [
            "Nur Text\nohne Absatz.",
            'Nur Text ohne Absatz.',
        ];
        yield 'nothing but markup' => [
            '<p><strong>Definition.</strong></p><p> </p>',
            '',
        ];
    }

    #[Test]
    #[DataProvider('descriptions')]
    public function theDefinitionIsTheFirstParagraphWithoutItsLabel(string $stored, string $expected): void
    {
        $this->description = $stored;

        self::assertSame($expected, $this->service()->loadFirstDescription(7));
    }

    #[Test]
    public function theEditorsMetaDescriptionComesFirst(): void
    {
        $this->term['meta_description'] = ' Eigene <em>Beschreibung</em>. ';
        $this->term['tooltiptext'] = 'Tooltip.';
        $this->description = '<p><strong>Definition.</strong> Definition.</p>';

        self::assertSame('Eigene Beschreibung.', $this->service()->describeTerm('7'));
    }

    #[Test]
    public function theDefinitionComesBeforeTheTooltip(): void
    {
        $this->term['tooltiptext'] = 'Tooltip.';
        $this->description = '<p><strong>Definition.</strong> Die Definition.</p>';

        self::assertSame('Die Definition.', $this->service()->describeTerm('7'));
    }

    #[Test]
    public function theTooltipIsTheLastResort(): void
    {
        $this->term['meta_description'] = '<p> </p>';
        $this->term['tooltiptext'] = 'Der Tooltip.';
        $this->description = '<p><strong>Definition.</strong></p>';

        self::assertSame('Der Tooltip.', $this->service()->describeTerm('rce'));
    }

    #[Test]
    public function withoutTermOrTextThereIsNoDescription(): void
    {
        self::assertSame('', $this->service()->describeTerm(''));
        self::assertSame('', $this->service()->describeTerm('7'), 'term without any text');

        $this->term = null;
        self::assertSame('', $this->service()->describeTerm('7'));
    }

    #[Test]
    public function wholeSentencesAreKeptWhenTheyFit(): void
    {
        $first = str_repeat('Wort ', 20) . 'Ende.';
        $this->description = '<p>' . $first . ' ' . str_repeat('Weiter ', 30) . '</p>';

        self::assertSame($first, $this->service()->describeTerm('7', 0, 155));
    }

    #[Test]
    public function aLongSentenceIsCutAtAWordWithAnEllipsis(): void
    {
        $this->description = '<p>' . str_repeat('abcdefghi ', 40) . '</p>';

        $description = $this->service()->describeTerm('7', 0, 155);

        self::assertLessThanOrEqual(155, mb_strlen($description));
        self::assertStringEndsWith('abcdefghi…', $description);
    }

    #[Test]
    public function aShortDescriptionIsLeftAlone(): void
    {
        $this->description = '<p>Kurz.</p>';

        self::assertSame('Kurz.', $this->service()->describeTerm('7', 0, 155));
    }
}
