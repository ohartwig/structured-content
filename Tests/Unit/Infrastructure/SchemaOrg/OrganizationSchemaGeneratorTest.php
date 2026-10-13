<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\OrganizationSchemaGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrganizationSchemaGeneratorTest extends TestCase
{
    private OrganizationSchemaGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new OrganizationSchemaGenerator();
    }

    /**
     * Liefert eine vollstaendige example.org-Settings-Konstellation —
     * Basis fuer alle Tests, einzelne Tests koennen davon abweichen.
     *
     * @return array<string, mixed>
     */
    private function examplePageData(): array
    {
        return [
            '_siteBase' => 'https://example.org/',
            'title' => 'Example Org GmbH',
            '_siteSettings' => [
                'footerBrandName' => 'Example Org GmbH',
                'footerBrandClaim' => 'Software, die entlastet.',
                'footerLogo' => 'https://example.org/Logo.svg',
                'footerLinkedIn' => 'https://www.linkedin.com/company/example',
                'footerGitHub' => 'https://github.com/example',
                'footerWebsite' => 'https://example.org',
                'structuredContent' => [
                    'schema' => [
                        'legalName' => 'Example Org GmbH',
                        'organizationTypes' => 'Corporation, ProfessionalService',
                        'organizationDescription' => 'Example entwickelt sichere Plattformen.',
                        'email' => 'kontakt@example.org',
                        'phone' => '+4921738519929',
                        'vatID' => 'DE335028333',
                        'foundingDate' => '2012',
                        'streetAddress' => 'Mittelstraße 11',
                        'postalCode' => '40789',
                        'addressLocality' => 'Monheim am Rhein',
                        'addressRegion' => 'NRW',
                        'addressCountry' => 'DE',
                        'areaServed' => 'DE, AT, CH, EU',
                        'knowsAbout' => 'AI-ready CMS, TYPO3, DevSecOps',
                        'sameAs' => 'https://x.com/example, https://bsky.app/profile/example.org',
                        'contactPointTypes' => 'sales, technical support',
                        'openingHours' => 'Mo-Fr 09:00-18:00',
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function canHandleAlwaysReturnsTrue(): void
    {
        // Andere Generatoren referenzieren #organization per @id, daher
        // muss Organization auf jeder Page emittiert werden.
        self::assertTrue($this->generator->canHandle([], []));
        self::assertTrue($this->generator->canHandle(['title' => 'Foo'], [['CType' => 'text']]));
    }

    #[Test]
    public function typeIsArrayWithoutOrganizationPrefixWhenOrganizationTypesIsSet(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        // User-Anforderung: ["Corporation", "ProfessionalService"] — kein
        // implizites "Organization" davor. Schema.org Inheritance-Hierarchie
        // deckt Organization ueber Corporation/ProfessionalService bereits ab.
        self::assertSame(['Corporation', 'ProfessionalService'], $doc->data['@type']);
    }

    #[Test]
    public function typeIsPlainOrganizationStringWhenOrganizationTypesIsEmpty(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['organizationTypes'] = '';

        $doc = $this->generator->generate($pageData, []);

        self::assertSame('Organization', $doc->data['@type']);
    }

    #[Test]
    public function typeIsSingleStringWhenOnlyOneTypeConfigured(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['organizationTypes'] = 'Corporation';

        $doc = $this->generator->generate($pageData, []);

        self::assertSame('Corporation', $doc->data['@type']);
    }

    #[Test]
    public function idIsSiteBasePlusOrganizationFragment(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertSame('https://example.org#organization', $doc->data['@id']);
    }

    #[Test]
    public function logoAndImageAreParallelImageObjectsWithIdenticalUrl(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertSame(['@type' => 'ImageObject', 'url' => 'https://example.org/Logo.svg'], $doc->data['logo']);
        self::assertSame($doc->data['logo'], $doc->data['image']);
    }

    #[Test]
    public function emailAndTelephoneAreTopLevelFieldsInAdditionToContactPoint(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertSame('kontakt@example.org', $doc->data['email']);
        self::assertSame('+4921738519929', $doc->data['telephone']);
    }

    #[Test]
    public function emailFallsBackToFooterEmailIfSchemaEmailEmpty(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['email'] = '';
        $pageData['_siteSettings']['footerEmail'] = 'fallback@example.com';

        $doc = $this->generator->generate($pageData, []);

        self::assertSame('fallback@example.com', $doc->data['email']);
    }

    #[Test]
    public function addressContainsAllFiveFields(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertSame([
            '@type' => 'PostalAddress',
            'streetAddress' => 'Mittelstraße 11',
            'addressLocality' => 'Monheim am Rhein',
            'postalCode' => '40789',
            'addressRegion' => 'NRW',
            'addressCountry' => 'DE',
        ], $doc->data['address']);
    }

    #[Test]
    public function addressLocalityFallsBackToFooterLocationTrimmedAtFirstComma(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['addressLocality'] = '';
        $pageData['_siteSettings']['footerLocation'] = 'Monheim am Rhein, Deutschland';

        $doc = $this->generator->generate($pageData, []);

        self::assertSame('Monheim am Rhein', $doc->data['address']['addressLocality']);
    }

    #[Test]
    public function addressCountryDefaultsToDEIfNotConfigured(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['addressCountry'] = '';

        $doc = $this->generator->generate($pageData, []);

        self::assertSame('DE', $doc->data['address']['addressCountry']);
    }

    #[Test]
    public function defaultContactPointTypesProduceTwoEntries(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertIsArray($doc->data['contactPoint']);
        self::assertCount(2, $doc->data['contactPoint']);
        self::assertSame('sales', $doc->data['contactPoint'][0]['contactType']);
        self::assertSame('technical support', $doc->data['contactPoint'][1]['contactType']);
        // Beide ContactPoints teilen Email/Phone/areaServed (geteilte Org-Defaults).
        self::assertSame('kontakt@example.org', $doc->data['contactPoint'][0]['email']);
        self::assertSame('kontakt@example.org', $doc->data['contactPoint'][1]['email']);
        self::assertSame(['DE', 'AT', 'CH', 'EU'], $doc->data['contactPoint'][0]['areaServed']);
    }

    #[Test]
    public function singleContactPointTypeProducesObjectNotArray(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['contactPointTypes'] = 'press inquiries';

        $doc = $this->generator->generate($pageData, []);

        // 1 Eintrag → als Objekt (nicht Array of one) — sauberer JSON-Output.
        self::assertSame('ContactPoint', $doc->data['contactPoint']['@type']);
        self::assertSame('press inquiries', $doc->data['contactPoint']['contactType']);
    }

    #[Test]
    public function emptyContactPointTypesOmitsContactPointField(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['contactPointTypes'] = '';

        $doc = $this->generator->generate($pageData, []);

        self::assertArrayNotHasKey('contactPoint', $doc->data);
    }

    #[Test]
    public function areaServedIsStructuredCountryAndAdministrativeAreaObjects(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertSame([
            ['@type' => 'Country', 'name' => 'Germany'],
            ['@type' => 'Country', 'name' => 'Austria'],
            ['@type' => 'Country', 'name' => 'Switzerland'],
            ['@type' => 'AdministrativeArea', 'name' => 'European Union'],
        ], $doc->data['areaServed']);
    }

    #[Test]
    public function unknownAreaServedCodeIsPassedThroughAsString(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['areaServed'] = 'Worldwide';

        $doc = $this->generator->generate($pageData, []);

        // Single area → Objekt (resp. String), nicht Array of one.
        self::assertSame('Worldwide', $doc->data['areaServed']);
    }

    #[Test]
    public function knowsAboutIsParsedFromCsvIntoArrayOfStrings(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertSame(['AI-ready CMS', 'TYPO3', 'DevSecOps'], $doc->data['knowsAbout']);
    }

    #[Test]
    public function sameAsCombinesFooterSocialKeysAndSchemaSetting(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        self::assertContains('https://www.linkedin.com/company/example', $doc->data['sameAs']);
        self::assertContains('https://github.com/example', $doc->data['sameAs']);
        self::assertContains('https://x.com/example', $doc->data['sameAs']);
        self::assertContains('https://bsky.app/profile/example.org', $doc->data['sameAs']);
    }

    #[Test]
    public function sameAsNeverContainsOwnSiteEvenIfFooterWebsiteIsSet(): void
    {
        $doc = $this->generator->generate($this->examplePageData(), []);

        // footerWebsite ist in examplePageData() bewusst gesetzt — darf
        // trotzdem NICHT in sameAs auftauchen (User-Anforderung). Filter
        // arbeitet host-basiert, also darf z.B. bsky.app/profile/example.org
        // (anderer Host) sehr wohl drin sein.
        foreach ($doc->data['sameAs'] as $url) {
            $host = parse_url((string) $url, PHP_URL_HOST);
            self::assertNotSame('example.org', $host);
        }
    }

    #[Test]
    public function sameAsFiltersAccidentalOwnSiteInSchemaSetting(): void
    {
        // Editor traegt aus Versehen die eigene Site in schema.sameAs ein —
        // der Generator MUSS sie defensiv rausfiltern.
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['sameAs']
            = 'https://x.com/example, https://example.org/about';

        $doc = $this->generator->generate($pageData, []);

        self::assertContains('https://x.com/example', $doc->data['sameAs']);
        self::assertNotContains('https://example.org/about', $doc->data['sameAs']);
    }

    #[Test]
    public function foundingDateFromSchemaSettingsOverridesFooterValue(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['footerFoundingDate'] = '1999';

        $doc = $this->generator->generate($pageData, []);

        // schema.foundingDate gewinnt gegen Footer-Fallback.
        self::assertSame('2012', $doc->data['foundingDate']);
    }

    #[Test]
    public function foundingDateFallsBackToFooterValue(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_siteSettings']['structuredContent']['schema']['foundingDate'] = '';
        $pageData['_siteSettings']['footerFoundingDate'] = '1999';

        $doc = $this->generator->generate($pageData, []);

        self::assertSame('1999', $doc->data['foundingDate']);
    }

    #[Test]
    public function emptySiteSettingsProduceMinimalDocument(): void
    {
        $doc = $this->generator->generate(['_siteBase' => 'https://example.com/', 'title' => 'Foo'], []);

        // Mit leerem Setting-Block bleibt nur das Skelett — kein contactPoint,
        // kein address, kein vatID, kein sameAs, kein knowsAbout.
        self::assertSame('Organization', $doc->data['@type']);
        self::assertSame('https://example.com#organization', $doc->data['@id']);
        self::assertArrayNotHasKey('contactPoint', $doc->data);
        self::assertArrayNotHasKey('address', $doc->data);
        self::assertArrayNotHasKey('knowsAbout', $doc->data);
    }

    /**
     * Regression: der site-weite `#organization`-Knoten trug ohne
     * footerBrandName den Titel der jeweils aktuellen Seite. Auf
     * ole-hartwig.eu ergab das 41 verschiedene Organization-Namen unter
     * identischer @id — Entity-Konsolidierung faellt damit aus.
     */
    #[Test]
    public function organizationNameIsIdenticalAcrossPagesWhenBrandNameIsUnset(): void
    {
        $settings = ['structuredContent' => ['schema' => []]];

        $home = $this->generator->generate(
            ['_siteBase' => 'https://ole-hartwig.eu/', 'uid' => 2, 'title' => 'ole-hartwig.eu | DevSecOps', '_siteSettings' => $settings],
            [],
        );
        $post = $this->generator->generate(
            ['_siteBase' => 'https://ole-hartwig.eu/', 'uid' => 4711, 'title' => 'Die Illusion des Multi-Agenten-Vorteils', '_siteSettings' => $settings],
            [],
        );
        $glossary = $this->generator->generate(
            ['_siteBase' => 'https://ole-hartwig.eu/', 'uid' => 1499, 'title' => 'Glossar', '_siteSettings' => $settings],
            [],
        );

        self::assertSame($home->data['name'], $post->data['name']);
        self::assertSame($home->data['name'], $glossary->data['name']);
        // Kein Seitentitel darf durchschlagen.
        self::assertSame('ole-hartwig.eu', $home->data['name']);
    }

    #[Test]
    public function organizationNameFallsBackToLegalNameBeforeHost(): void
    {
        $doc = $this->generator->generate([
            '_siteBase' => 'https://ole-hartwig.eu/',
            'title' => 'Irgendein Blogartikel',
            '_siteSettings' => ['structuredContent' => ['schema' => ['legalName' => 'Kai Ole Hartwig']]],
        ], []);

        self::assertSame('Kai Ole Hartwig', $doc->data['name']);
    }

    #[Test]
    public function footerBrandNameStillWinsOverEverythingElse(): void
    {
        $doc = $this->generator->generate([
            '_siteBase' => 'https://ole-hartwig.eu/',
            'title' => 'Irgendein Blogartikel',
            '_siteSettings' => [
                'footerBrandName' => 'Kai Ole Hartwig — DevSecOps',
                'structuredContent' => ['schema' => ['legalName' => 'Kai Ole Hartwig']],
            ],
        ], []);

        self::assertSame('Kai Ole Hartwig — DevSecOps', $doc->data['name']);
    }

    /**
     * Regression: der Person-Knoten auf /autor/<slug> war eine Insel — die
     * site-weite Organization referenzierte ihn nirgends, obwohl bei einer
     * Ein-Personen-Marke genau diese Kante die Entity-Aufloesung traegt.
     */
    #[Test]
    public function organizationLinksTheFounderPersonWhenSlugIsConfigured(): void
    {
        $doc = $this->generator->generate([
            '_siteBase' => 'https://ole-hartwig.eu/',
            'title' => 'Irgendeine Seite',
            '_siteSettings' => ['structuredContent' => ['schema' => ['founderPersonSlug' => 'kai-ole-hartwig']]],
        ], []);

        self::assertSame(
            ['@type' => 'Person', '@id' => 'https://ole-hartwig.eu/autor/kai-ole-hartwig#person'],
            $doc->data['founder'],
        );
    }

    #[Test]
    public function organizationOmitsFounderWhenSlugIsUnset(): void
    {
        $doc = $this->generator->generate([
            '_siteBase' => 'https://ole-hartwig.eu/',
            'title' => 'Irgendeine Seite',
            '_siteSettings' => ['structuredContent' => ['schema' => []]],
        ], []);

        self::assertArrayNotHasKey('founder', $doc->data);
    }

    #[Test]
    public function aLanguageOverrideReplacesTheSiteWideDescription(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_languageSchema'] = ['organizationDescription' => 'Example builds secure platforms.'];

        $result = $this->generator->generate($pageData, []);

        self::assertSame('Example builds secure platforms.', $result->data['description']);
    }

    #[Test]
    public function withoutALanguageOverrideTheSiteWideDescriptionApplies(): void
    {
        $pageData = $this->examplePageData();
        $pageData['_languageSchema'] = ['organizationDescription' => '   '];

        $result = $this->generator->generate($pageData, []);

        self::assertSame('Example entwickelt sichere Plattformen.', $result->data['description']);
    }
}
