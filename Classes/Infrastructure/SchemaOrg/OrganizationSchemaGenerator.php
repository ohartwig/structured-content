<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;

final class OrganizationSchemaGenerator implements SchemaGeneratorInterface
{
    use ResolvesStableBrandNameTrait;
    use SanitizesSchemaTrait;

    /**
     * ISO-3166-1 alpha-2 → englischer Country-Name fuer Schema.org Country-Objekte.
     * EU ist kein ISO-Country, wird stattdessen als AdministrativeArea ausgegeben.
     *
     * @var array<string, string>
     */
    private const COUNTRY_NAMES = [
        'DE' => 'Germany',
        'AT' => 'Austria',
        'CH' => 'Switzerland',
        'FR' => 'France',
        'IT' => 'Italy',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'LU' => 'Luxembourg',
        'GB' => 'United Kingdom',
        'IE' => 'Ireland',
        'ES' => 'Spain',
        'PT' => 'Portugal',
        'DK' => 'Denmark',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'FI' => 'Finland',
        'IS' => 'Iceland',
        'PL' => 'Poland',
        'CZ' => 'Czech Republic',
        'US' => 'United States',
        'CA' => 'Canada',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
    ];

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function canHandle(array $pageData, array $contentElements): bool
    {
        // Always emit Organization on every page. Other generators reference
        // the Organization via `@id` (`<siteBase>#organization`) for
        // publisher/provider/about — der referenzierte Knoten MUSS auf
        // jeder Page existieren. Bloat ~500 bytes pro Page.
        return true;
    }

    /**
     * @param array<string, mixed>       $pageData
     * @param list<array<string, mixed>> $contentElements
     */
    public function generate(array $pageData, array $contentElements): JsonLdDocument
    {
        $settings = $pageData['_siteSettings'] ?? [];
        $siteBase = $pageData['_siteBase'] ?? '';
        $entityBase = EntityBase::fromPageData($pageData);

        $schemaSettings = $settings['structuredContent']['schema'] ?? [];

        $legalName = $this->getSchemaSettingValue($schemaSettings, 'legalName');
        // Organization.name MUSS wie die Description stabil sein und darf NICHT
        // von der aktuellen Page abhaengen — sonst traegt der site-weite
        // `#organization`-Knoten auf jeder Seite einen anderen Namen bei
        // identischer @id, was die Entity-Konsolidierung zerstoert.
        $displayName = $this->resolveStableBrandName($settings, (string) $siteBase);

        // Organization-Description MUSS stabil sein und darf NICHT von der
        // aktuellen Page abhaengen — sonst wird auf jedem Blog-Artikel die
        // Article-Description zur Org-Description (Spec-Verletzung, schwaecht
        // Entity-Erkennung). Fallback: footerBrandClaim (Slogan).
        $orgDescription = $this->getLocalizedDescription($pageData);
        if ('' === $orgDescription) {
            $orgDescription = $this->getSchemaSettingValue($schemaSettings, 'organizationDescription');
        }
        if ('' === $orgDescription) {
            $orgDescription = $this->getSetting($settings, 'footerBrandClaim');
        }

        $data = [
            '@type' => $this->determineTypes($pageData),
            '@id' => $entityBase . '#organization',
            'name' => $this->sanitizeText($displayName),
            'description' => $this->sanitizeText($orgDescription),
            'url' => $siteBase,
        ];

        if ('' !== $legalName) {
            $data['legalName'] = $legalName;
        }

        $logo = $this->getSetting($settings, 'footerLogo');
        if ('' === $logo && isset($pageData['og_image']) && '' !== $pageData['og_image']) {
            $logo = (string) $pageData['og_image'];
        }
        if ('' !== $logo) {
            // Spec: Organization.logo erwartet entweder URL oder ImageObject.
            // Wir geben ImageObject, weil sonst Google-Knowledge-Graph die
            // Logo-Dimensionen nicht ohne extra Fetch ableiten kann.
            $data['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
            // Organization.image kann zusaetzlich gesetzt werden — das User-
            // Zielbild zeigt beide Felder mit identischer URL. image[] wird
            // von einigen LLM-Crawlern bevorzugt indiziert.
            $data['image'] = ['@type' => 'ImageObject', 'url' => $logo];
        }

        // Email: bevorzugt schema.email-Setting (stabiles Org-Feld), Fallback
        // auf das Footer-Setting damit bestehende Sites ohne Migration
        // weiter funktionieren.
        $email = $this->getSchemaSettingValue($schemaSettings, 'email');
        if ('' === $email) {
            $email = $this->getSetting($settings, 'footerEmail');
        }
        $phone = $this->getSchemaSettingValue($schemaSettings, 'phone');
        if ('' === $phone) {
            $phone = $this->getSetting($settings, 'footerPhone');
        }

        if ('' !== $email) {
            $data['email'] = $email;
        }
        // Telephone als Top-Level-Feld zusaetzlich zu ContactPoint.telephone —
        // entspricht dem User-Zielbild und ist bei Schema.org Spec erlaubt
        // (Organization.telephone existiert nativ).
        if ('' !== $phone) {
            $data['telephone'] = $phone;
        }

        $areaServedCodes = $this->parseAreaServedCodes($schemaSettings);

        $contactPoints = $this->buildContactPoints($schemaSettings, $email, $phone, $areaServedCodes);
        if ([] !== $contactPoints) {
            // Bei nur einem ContactPoint: als Objekt statt Array — Google-
            // Validator akzeptiert beides, aber Single-Object liest sich
            // sauberer.
            $data['contactPoint'] = 1 === count($contactPoints) ? $contactPoints[0] : $contactPoints;
        }

        $address = $this->buildAddress($settings, $schemaSettings);
        if (null !== $address) {
            $data['address'] = $address;
        }

        $vatID = $this->getSchemaSettingValue($schemaSettings, 'vatID');
        if ('' !== $vatID) {
            $data['vatID'] = $vatID;
        }

        $claim = $this->getSetting($settings, 'footerBrandClaim');
        if ('' !== $claim) {
            $data['slogan'] = $claim;
        }

        $founding = $this->getSchemaSettingValue($schemaSettings, 'foundingDate');
        if ('' === $founding) {
            $founding = $this->getSetting($settings, 'footerFoundingDate');
        }
        if ('' !== $founding) {
            $data['foundingDate'] = $founding;
        }

        $priceRange = $this->getSchemaSettingValue($schemaSettings, 'priceRange');
        if ('' !== $priceRange) {
            $data['priceRange'] = $priceRange;
        }

        $areaServed = $this->buildAreaServed($areaServedCodes);
        if ([] !== $areaServed) {
            $data['areaServed'] = 1 === count($areaServed) ? $areaServed[0] : $areaServed;
        }

        $knowsAbout = $this->buildKnowsAbout($schemaSettings);
        if ([] !== $knowsAbout) {
            $data['knowsAbout'] = $knowsAbout;
        }

        $sameAs = $this->buildSameAs($settings, $schemaSettings, (string) $siteBase);
        if ([] !== $sameAs) {
            $data['sameAs'] = $sameAs;
        }

        // Organization -> Person. Ohne diese Kante bleibt die Person-Entity auf
        // /autor/<slug> eine Insel: Blogposts referenzieren sie als author, aber
        // der site-weite Organization-Knoten kennt sie nicht. Fuer Ein-Personen-
        // Marken (Freelancer, Beratung) ist genau diese Verbindung der Kern der
        // Entity-Aufloesung — sie verknuepft Reputation der Person mit der Marke.
        $founderSlug = $this->getSchemaSettingValue($schemaSettings, 'founderPersonSlug');
        if ('' !== $founderSlug && '' !== $entityBase) {
            $data['founder'] = [
                '@type' => 'Person',
                '@id' => $entityBase . PersonSchemaBuilder::AUTHOR_PATH . '/' . ltrim($founderSlug, '/') . '#person',
            ];
        }

        $openingHours = $this->getSchemaSettingValue($schemaSettings, 'openingHours');
        if ('' !== $openingHours) {
            $data['openingHoursSpecification'] = $this->buildOpeningHours($openingHours);
        }

        $serviceTypes = $this->getSchemaSettingValue($schemaSettings, 'serviceTypes');
        if ('' !== $serviceTypes) {
            $types = array_filter(array_map('trim', explode(',', $serviceTypes)), static fn (string $value): bool => '' !== $value);
            if ([] !== $types) {
                $data['hasOfferCatalog'] = [
                    '@type' => 'OfferCatalog',
                    'name' => 'Services',
                    'itemListElement' => array_map(
                        static fn(string $type): array => [
                            '@type' => 'Offer',
                            'itemOffered' => ['@type' => 'Service', 'name' => $type],
                        ],
                        array_values($types),
                    ),
                ];
            }
        }

        return new JsonLdDocument('Organization', array_filter($data, SchemaValues::present(...)));
    }

    public function getPriority(): int
    {
        return 40;
    }

    /**
     * @param array<string, mixed> $pageData
     *
     * @return string|list<string>
     */
    private function determineTypes(array $pageData): array|string
    {
        $settings = $pageData['_siteSettings'] ?? [];
        $extraTypesRaw = trim((string) ($settings['structuredContent']['schema']['organizationTypes'] ?? ''));
        if ('' === $extraTypesRaw) {
            return 'Organization';
        }
        $types = array_values(array_filter(
            array_map('trim', explode(',', $extraTypesRaw)),
            static fn(string $t): bool => '' !== $t,
        ));
        if ([] === $types) {
            return 'Organization';
        }

        // Wenn der User explizit Corporation/ProfessionalService konfiguriert,
        // wollen wir GENAU diese Typen — kein implizites 'Organization'-Prefix.
        // Schema.org-Inheritance: Corporation/ProfessionalService sind beide
        // Subklassen von Organization, also bleibt die Org-Identitaet erhalten.
        // (Vorher: array_merge(['Organization'], $extras) → 3 Typen, doppelt.)
        return 1 === count($types) ? $types[0] : $types;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schemaSettings
     *
     * @return array<string, string>|null
     */
    private function buildAddress(array $settings, array $schemaSettings): ?array
    {
        // addressLocality bevorzugt aus eigenem Setting; Fallback auf
        // footerLocation und davon nur der Teil vor dem ersten Komma
        // ("Monheim am Rhein, Deutschland" → "Monheim am Rhein").
        $locality = $this->getSchemaSettingValue($schemaSettings, 'addressLocality');
        if ('' === $locality) {
            $rawLocation = $this->getSetting($settings, 'footerLocation');
            if ('' !== $rawLocation) {
                $locality = trim(explode(',', $rawLocation)[0]);
            }
        }
        $street = $this->getSchemaSettingValue($schemaSettings, 'streetAddress');
        $postalCode = $this->getSchemaSettingValue($schemaSettings, 'postalCode');
        $region = $this->getSchemaSettingValue($schemaSettings, 'addressRegion');
        $country = $this->getSchemaSettingValue($schemaSettings, 'addressCountry');
        if ('' === $country) {
            $country = 'DE';
        }

        if ('' === $locality && '' === $street && '' === $postalCode) {
            return null;
        }

        $address = ['@type' => 'PostalAddress'];

        if ('' !== $street) {
            $address['streetAddress'] = $street;
        }
        if ('' !== $locality) {
            $address['addressLocality'] = $locality;
        }
        if ('' !== $postalCode) {
            $address['postalCode'] = $postalCode;
        }
        if ('' !== $region) {
            $address['addressRegion'] = $region;
        }
        $address['addressCountry'] = $country;

        return $address;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function getSetting(array $settings, string $key): string
    {
        return trim((string) ($settings[$key] ?? ''));
    }

    /**
     * The organization description of the page language, from the language's
     * site configuration (`structuredContent.organizationDescription`).
     *
     * @param array<string, mixed> $pageData
     */
    private function getLocalizedDescription(array $pageData): string
    {
        $languageSchema = $pageData['_languageSchema'] ?? [];

        return is_array($languageSchema) ? trim((string) ($languageSchema['organizationDescription'] ?? '')) : '';
    }

    /**
     * @param array<string, mixed> $schemaSettings
     */
    private function getSchemaSettingValue(array $schemaSettings, string $key): string
    {
        return trim((string) ($schemaSettings[$key] ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOpeningHours(string $openingHours): array
    {
        return [
            '@type' => 'OpeningHoursSpecification',
            'dayOfWeek' => $this->parseDays($openingHours),
            'opens' => $this->parseTime($openingHours, '/(\d{2}:\d{2})-/'),
            'closes' => $this->parseTime($openingHours, '/-(\d{2}:\d{2})/'),
        ];
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
            $keys = array_keys($dayMap);
            $startIdx = array_search($matches[1], $keys, true);
            $endIdx = array_search($matches[2], $keys, true);
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

    private function parseTime(string $openingHours, string $pattern): string
    {
        if (1 === preg_match($pattern, $openingHours, $matches)) {
            return $matches[1];
        }

        return str_contains($pattern, ')-') ? '18:00' : '09:00';
    }

    /**
     * Sammelt sameAs-URLs aus Footer-Social-Keys + dem neuen
     * schema.sameAs-CSV-Setting; entfernt die eigene Site-Base (Spec:
     * sameAs ist fuer EXTERNE Identitaeten gedacht, fuer die eigene
     * Website ist `url` da).
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $schemaSettings
     *
     * @return list<string>
     */
    private function buildSameAs(array $settings, array $schemaSettings, string $siteBase): array
    {
        // footerWebsite bewusst NICHT in dieser Liste — fuer die eigene
        // Site existiert `url`. Erzwingt: sameAs verweist immer auf
        // EXTERNE Profile (Social Networks, GitHub, Wikidata, ...).
        $footerKeys = ['footerLinkedIn', 'footerGitHub', 'footerInstagram', 'footerTwitter', 'footerFacebook', 'footerBluesky'];
        $urls = [];
        foreach ($footerKeys as $key) {
            $url = $this->getSetting($settings, $key);
            if ('' !== $url) {
                $urls[] = $url;
            }
        }

        $extraCsv = $this->getSchemaSettingValue($schemaSettings, 'sameAs');
        if ('' !== $extraCsv) {
            foreach (explode(',', $extraCsv) as $url) {
                $url = trim($url);
                if ('' !== $url) {
                    $urls[] = $url;
                }
            }
        }

        // Defensive: falls trotzdem die eigene Site-Base via Setting
        // hereinkommt (z.B. Editor traegt sie aus Versehen ein), filtern.
        $siteHost = parse_url($siteBase, PHP_URL_HOST);
        if (is_string($siteHost) && '' !== $siteHost) {
            $urls = array_filter($urls, static function (string $url) use ($siteHost): bool {
                $host = parse_url($url, PHP_URL_HOST);

                return $host !== $siteHost;
            });
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<string, mixed> $schemaSettings
     *
     * @return list<string>
     */
    private function parseAreaServedCodes(array $schemaSettings): array
    {
        $raw = $this->getSchemaSettingValue($schemaSettings, 'areaServed');
        if ('' === $raw) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn(string $v): string => trim($v),
                explode(',', $raw),
            ),
            static fn (string $value): bool => '' !== $value,
        ));
    }

    /**
     * Konvertiert ISO-Codes in Schema.org Country/AdministrativeArea-Objekte.
     * Unbekannte Codes werden als String durchgereicht (defensive — User
     * koennte z.B. "EMEA" oder "Worldwide" eintragen).
     *
     * @param list<string> $codes
     *
     * @return list<array<string, string>|string>
     */
    private function buildAreaServed(array $codes): array
    {
        $out = [];
        foreach ($codes as $code) {
            $upper = strtoupper($code);
            if ('EU' === $upper) {
                $out[] = ['@type' => 'AdministrativeArea', 'name' => 'European Union'];

                continue;
            }
            if (isset(self::COUNTRY_NAMES[$upper])) {
                $out[] = ['@type' => 'Country', 'name' => self::COUNTRY_NAMES[$upper]];

                continue;
            }
            $out[] = $code;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $schemaSettings
     * @param list<string>         $areaServedCodes
     *
     * @return list<array<string, mixed>>
     */
    private function buildContactPoints(array $schemaSettings, string $email, string $phone, array $areaServedCodes): array
    {
        if ('' === $email && '' === $phone) {
            return [];
        }
        $rawTypes = $this->getSchemaSettingValue($schemaSettings, 'contactPointTypes');
        if ('' === $rawTypes) {
            return [];
        }
        $types = array_values(array_filter(array_map('trim', explode(',', $rawTypes)), static fn (string $value): bool => '' !== $value));
        if ([] === $types) {
            return [];
        }

        $points = [];
        foreach ($types as $contactType) {
            $point = [
                '@type' => 'ContactPoint',
                'contactType' => $contactType,
            ];
            if ('' !== $email) {
                $point['email'] = $email;
            }
            if ('' !== $phone) {
                $point['telephone'] = $phone;
            }
            $point['availableLanguage'] = ['de', 'en'];
            // ContactPoint.areaServed nimmt simple Strings (ISO-Codes) —
            // im Gegensatz zu Organization.areaServed das wir mit Country-
            // Objekten anreichern. Das matched das User-Zielbild.
            if ([] !== $areaServedCodes) {
                $point['areaServed'] = $areaServedCodes;
            }
            $points[] = $point;
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $schemaSettings
     *
     * @return list<string>
     */
    private function buildKnowsAbout(array $schemaSettings): array
    {
        $raw = $this->getSchemaSettingValue($schemaSettings, 'knowsAbout');
        if ('' === $raw) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                fn(string $topic): string => $this->sanitizeText(trim($topic)),
                explode(',', $raw),
            ),
            static fn (string $value): bool => '' !== $value,
        ));
    }
}
