<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class HowToSchemaGenerator implements SchemaGeneratorInterface
{
    use SanitizesSchemaTrait;

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        $keywords = strtolower((string) ($pageData['keywords'] ?? ''));

        return str_contains($keywords, 'howto')
            || str_contains($keywords, 'anleitung')
            || str_contains($keywords, 'tutorial');
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $steps = [];
        $stepNumber = 1;

        foreach ($contentElements as $element) {
            if (($element['header'] ?? '') === '' && ($element['bodytext'] ?? '') === '') {
                continue;
            }

            $name = $this->sanitizeText($element['header'] ?? '');

            $steps[] = [
                '@type' => 'HowToStep',
                'position' => $stepNumber,
                'name' => '' !== $name ? $name : 'Step ' . $stepNumber,
                'text' => $this->sanitizeText($element['bodytext'] ?? ''),
            ];
            ++$stepNumber;
        }

        $data = [
            'name' => $this->sanitizeText($pageData['title'] ?? ''),
            'description' => $this->sanitizeText($pageData['description'] ?? $pageData['abstract'] ?? ''),
            'step' => $steps,
        ];

        return new JsonLdDocument('HowTo', array_filter($data, SchemaValues::present(...)));
    }

    public function getPriority(): int
    {
        return 65;
    }
}
