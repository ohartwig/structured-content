<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

trait SanitizesSchemaTrait
{
    private function sanitizeText(string $value): string
    {
        // Decode entities BEFORE stripping tags to prevent double-decoding bypass
        $value = html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // Ensure block-level and line-break tags produce whitespace before stripping
        $value = preg_replace('/<br\s*\/?>/i', ' ', $value) ?? $value;
        $value = preg_replace('/<\/(p|div|h[1-6]|li|tr|td|th|blockquote)>/i', ' ', $value) ?? $value;
        $stripped = strip_tags($value);

        return trim(preg_replace('/\s+/', ' ', $stripped) ?? $stripped);
    }
}
