<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class ProfessionalServiceSchemaGenerator implements SchemaGeneratorInterface
{
    use ResolvesStableBrandNameTrait;
    use SanitizesSchemaTrait;

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    /**
     * @deprecated ProfessionalService is a pending schema.org type not supported by Google.
     *             All properties (priceRange, areaServed, openingHours, hasOfferCatalog)
     *             have been merged into OrganizationSchemaGenerator.
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $settings = $pageData['_siteSettings'] ?? [];
        $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
        $entityBase = EntityBase::fromPageData($pageData);

        $data = [
            '@id' => $entityBase . '#professional-service',
            'name' => $this->sanitizeText($this->resolveStableBrandName($settings, $siteBase)),
            'description' => $this->sanitizeText((string) ($pageData['description'] ?? $pageData['abstract'] ?? '')),
            'url' => $siteBase,
        ];

        $logo = $this->getSetting($settings, 'footerLogo');
        if ('' !== $logo) {
            $data['logo'] = $logo;
        }

        $priceRange = $this->getNestedSetting($settings, 'structuredContent', 'schema', 'priceRange');
        if ('' !== $priceRange) {
            $data['priceRange'] = $priceRange;
        }

        $areaServed = $this->getNestedSetting($settings, 'structuredContent', 'schema', 'areaServed');
        if ('' !== $areaServed) {
            $data['areaServed'] = $areaServed;
        }

        $openingHours = $this->getNestedSetting($settings, 'structuredContent', 'schema', 'openingHours');
        if ('' !== $openingHours) {
            $data['openingHoursSpecification'] = [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => $this->parseDays($openingHours),
                'opens' => $this->parseOpens($openingHours),
                'closes' => $this->parseCloses($openingHours),
            ];
        }

        $serviceTypes = $this->getNestedSetting($settings, 'structuredContent', 'schema', 'serviceTypes');
        if ('' !== $serviceTypes) {
            $types = array_map('trim', explode(',', $serviceTypes));
            $data['hasOfferCatalog'] = [
                '@type' => 'OfferCatalog',
                'name' => 'Services',
                'itemListElement' => array_map(
                    static fn(string $type): array => [
                        '@type' => 'Offer',
                        'itemOffered' => [
                            '@type' => 'Service',
                            'name' => $type,
                        ],
                    ],
                    array_filter($types, static fn (string $value): bool => '' !== $value),
                ),
            ];
        }

        $email = $this->getSetting($settings, 'footerEmail');
        $phone = $this->getSetting($settings, 'footerPhone');
        if ('' !== $email || '' !== $phone) {
            $contactPoint = ['@type' => 'ContactPoint', 'contactType' => 'customer service'];
            if ('' !== $email) {
                $contactPoint['email'] = $email;
            }
            if ('' !== $phone) {
                $contactPoint['telephone'] = $phone;
            }
            $data['contactPoint'] = $contactPoint;
        }

        $location = $this->getSetting($settings, 'footerLocation');
        if ('' !== $location) {
            $data['address'] = [
                '@type' => 'PostalAddress',
                'addressLocality' => $location,
                'addressCountry' => '' !== $areaServed ? $areaServed : 'DE',
            ];
        }

        $sameAs = $this->buildSameAs($settings);
        if ([] !== $sameAs) {
            $data['sameAs'] = $sameAs;
        }

        $founding = $this->getSetting($settings, 'footerFoundingDate');
        if ('' !== $founding) {
            $data['foundingDate'] = $founding;
        }

        return new JsonLdDocument('ProfessionalService', array_filter($data, SchemaValues::present(...)));
    }

    public function getPriority(): int
    {
        return 75;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function getSetting(array $settings, string $key): string
    {
        return trim((string) ($settings[$key] ?? ''));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function getNestedSetting(array $settings, string ...$keys): string
    {
        $current = $settings;
        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return '';
            }
            $current = $current[$key];
        }

        return trim((string) $current);
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return list<string>
     */
    private function buildSameAs(array $settings): array
    {
        $sameAs = [];
        $keys = ['footerLinkedIn', 'footerGitHub', 'footerInstagram', 'footerWebsite'];
        foreach ($keys as $key) {
            $url = $this->getSetting($settings, $key);
            if ('' !== $url) {
                $sameAs[] = $url;
            }
        }

        return $sameAs;
    }

    /**
     * @return list<string>
     */
    private function parseDays(string $openingHours): array
    {
        $dayMap = [
            'Mo' => 'Monday', 'Tu' => 'Tuesday', 'We' => 'Wednesday',
            'Th' => 'Thursday', 'Fr' => 'Friday', 'Sa' => 'Saturday', 'Su' => 'Sunday',
        ];

        if (1 === preg_match('/^([A-Za-z]{2})-([A-Za-z]{2})/', $openingHours, $matches)) {
            $start = $matches[1];
            $end = $matches[2];
            $keys = array_keys($dayMap);
            $startIdx = array_search($start, $keys, true);
            $endIdx = array_search($end, $keys, true);
            if (false !== $startIdx && false !== $endIdx) {
                $days = [];
                for ($i = $startIdx; $i <= $endIdx; ++$i) {
                    $days[] = $dayMap[$keys[$i]];
                }

                return $days;
            }
        }

        return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    }

    private function parseOpens(string $openingHours): string
    {
        if (1 === preg_match('/(\d{2}:\d{2})-/', $openingHours, $matches)) {
            return $matches[1];
        }

        return '09:00';
    }

    private function parseCloses(string $openingHours): string
    {
        if (1 === preg_match('/-(\d{2}:\d{2})/', $openingHours, $matches)) {
            return $matches[1];
        }

        return '18:00';
    }
}
