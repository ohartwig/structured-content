<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

use Koh\StructuredContent\Domain\Contract\ContextConfigurationProviderInterface;
use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\PageRootlineProviderInterface;
use Koh\StructuredContent\Domain\Model\ResolvedContext;

final readonly class ContextResolver implements ContextResolverInterface
{
    public function __construct(
        private ContextConfigurationProviderInterface $configurationProvider,
        private PageRootlineProviderInterface $pageRootlineProvider,
    ) {}

    /**
     * Resolves context for a record using the inheritance cascade:
     * record → page → parent page → root → defaults
     *
     * @param array<string, mixed> $record
     */
    public function resolve(string $table, array $record): ResolvedContext
    {
        $audience = $this->extractField(record: $record, field: 'ai_context_audience');
        $tone = $this->extractField(record: $record, field: 'ai_context_tone');
        $channelsRaw = $this->extractField(record: $record, field: 'ai_context_channels');

        $hasRecordOverride = null !== $audience || null !== $tone || null !== $channelsRaw;

        $needsPageTree = null === $audience || null === $tone || null === $channelsRaw;
        $pid = isset($record['pid']) && is_int($record['pid']) ? $record['pid'] : 0;

        if ($needsPageTree && $pid > 0) {
            $pageTreeContext = $this->resolveFieldsFromPageTree(pageUid: $pid);
            $audience ??= $pageTreeContext['audience'];
            $tone ??= $pageTreeContext['tone'];
            $channelsRaw ??= $pageTreeContext['channels'];
        }

        if ($needsPageTree && 'pages' === $table) {
            $pageUid = isset($record['uid']) && is_int($record['uid']) ? $record['uid'] : 0;
            if ($pageUid > 0) {
                $pageTreeContext = $this->resolveFieldsFromPageTree(pageUid: $pageUid);
                $audience ??= $pageTreeContext['audience'];
                $tone ??= $pageTreeContext['tone'];
                $channelsRaw ??= $pageTreeContext['channels'];
            }
        }

        $inheritedFrom = $this->determineInheritedFrom(
            hasRecordOverride: $hasRecordOverride,
            audience: $audience,
            tone: $tone,
            channelsRaw: $channelsRaw,
        );

        $resolvedAudience = $audience ?? $this->configurationProvider->getDefaultAudience();
        $resolvedTone = $tone ?? $this->configurationProvider->getDefaultTone();
        $resolvedChannels = null !== $channelsRaw
            ? $this->parseChannels(channels: $channelsRaw)
            : $this->configurationProvider->getDefaultChannels();

        return new ResolvedContext(
            audience: $resolvedAudience,
            tone: $resolvedTone,
            channels: $resolvedChannels,
            inheritedFrom: $inheritedFrom,
        );
    }

    /**
     * Resolves context from the page tree by walking the rootline from the given
     * page up to the root. For each page, checks ai_context_audience, ai_context_tone,
     * and ai_context_channels. The first non-empty value wins (inheritance cascade).
     * Falls back to extension defaults for any remaining empty values.
     */
    public function resolveFromPageTree(int $pageUid): ResolvedContext
    {
        $pageTreeFields = $this->resolveFieldsFromPageTree(pageUid: $pageUid);

        $audience = $pageTreeFields['audience'] ?? $this->configurationProvider->getDefaultAudience();
        $tone = $pageTreeFields['tone'] ?? $this->configurationProvider->getDefaultTone();
        $channels = null !== $pageTreeFields['channels']
            ? $this->parseChannels(channels: $pageTreeFields['channels'])
            : $this->configurationProvider->getDefaultChannels();

        $inheritedFrom = null !== $pageTreeFields['audience']
            || null !== $pageTreeFields['tone']
            || null !== $pageTreeFields['channels']
            ? 'page'
            : 'defaults';

        return new ResolvedContext(
            audience: $audience,
            tone: $tone,
            channels: $channels,
            inheritedFrom: $inheritedFrom,
        );
    }

    /**
     * Walks the rootline and collects the first non-empty value for each context field.
     *
     * @return array{audience: ?string, tone: ?string, channels: ?string}
     */
    private function resolveFieldsFromPageTree(int $pageUid): array
    {
        $rootline = $this->pageRootlineProvider->getRootline(pageUid: $pageUid);

        $audience = null;
        $tone = null;
        $channels = null;

        foreach ($rootline as $page) {
            $audience ??= $this->extractField(record: $page, field: 'ai_context_audience');
            $tone ??= $this->extractField(record: $page, field: 'ai_context_tone');
            $channels ??= $this->extractField(record: $page, field: 'ai_context_channels');

            if (null !== $audience && null !== $tone && null !== $channels) {
                break;
            }
        }

        return [
            'audience' => $audience,
            'tone' => $tone,
            'channels' => $channels,
        ];
    }

    /**
     * Determines the inheritedFrom label based on what was resolved.
     */
    private function determineInheritedFrom(
        bool $hasRecordOverride,
        ?string $audience,
        ?string $tone,
        ?string $channelsRaw,
    ): string {
        if ($hasRecordOverride) {
            return 'record';
        }

        if (null !== $audience || null !== $tone || null !== $channelsRaw) {
            return 'page';
        }

        return 'defaults';
    }

    /**
     * Parses a comma-separated channel string into an array.
     *
     * @return list<string>
     */
    public function parseChannels(string $channels): array
    {
        if ('' === $channels) {
            return [];
        }

        $parsed = explode(',', $channels);
        $result = [];

        foreach ($parsed as $channel) {
            $trimmed = trim($channel);
            if ('' !== $trimmed) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $record */
    private function extractField(array $record, string $field): ?string
    {
        if (!array_key_exists($field, $record)) {
            return null;
        }

        $value = $record[$field];

        if (!is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
