<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Configuration;

use Koh\StructuredContent\Domain\Contract\ContextConfigurationProviderInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final readonly class SiteConfigurationProvider implements ContextConfigurationProviderInterface
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    public function getDefaultAudience(): string
    {
        return $this->getSetting(key: 'structuredContent.defaults.audience', fallback: 'customer');
    }

    public function getDefaultTone(): string
    {
        return $this->getSetting(key: 'structuredContent.defaults.tone', fallback: 'informational');
    }

    /**
     * @return list<string>
     */
    public function getDefaultChannels(): array
    {
        $channels = $this->getSetting(key: 'structuredContent.defaults.channels', fallback: '');

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

    /**
     * @return list<string>|null
     */
    public function getFieldRestrictionsForTable(string $table, string $channel): ?array
    {
        $key = sprintf('structuredContent.fieldRestrictions.%s.%s', $table, $channel);
        $value = $this->getSetting(key: $key, fallback: '');

        if ('' === $value) {
            return null;
        }

        $fields = explode(',', $value);
        $result = [];

        foreach ($fields as $field) {
            $trimmed = trim($field);
            if ('' !== $trimmed) {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    private function getSetting(string $key, string $fallback): string
    {
        $sites = $this->siteFinder->getAllSites();

        foreach ($sites as $site) {
            $settings = $site->getSettings();
            if ($settings->has($key)) {
                $value = $settings->get($key);
                if (is_string($value)) {
                    return $value;
                }
            }
        }

        return $fallback;
    }
}
