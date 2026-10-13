<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Model;

final readonly class JsonLdDocument
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $schemaType,
        public array $data,
    ) {}

    public function toJsonLd(): string
    {
        $document = array_merge(
            ['@context' => 'https://schema.org', '@type' => $this->schemaType],
            $this->data,
        );

        $result = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP);

        return false !== $result ? $result : '{}';
    }

    public function toScriptTag(): string
    {
        // Replace </ with <\/ to prevent script tag breakout (XSS)
        $sanitizedJson = str_replace('</', '<\/', $this->toJsonLd());

        return '<script type="application/ld+json">' . $sanitizedJson . '</script>';
    }
}
