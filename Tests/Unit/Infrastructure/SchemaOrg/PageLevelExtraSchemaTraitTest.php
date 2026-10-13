<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Infrastructure\SchemaOrg\PageLevelExtraSchemaTrait;
use Koh\StructuredContent\Infrastructure\SchemaOrg\SanitizesSchemaTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageLevelExtraSchemaTraitTest extends TestCase
{
    /**
     * Anonymous test-double that exposes the private trait-method as a
     * public seam — keeps the test laser-focused on the trait logic and
     * avoids dragging the full WebPage/Article/Service-Generator setup
     * (with all its DB-dependencies) into the unit test.
     */
    private object $subject;

    protected function setUp(): void
    {
        $this->subject = new class {
            use SanitizesSchemaTrait;
            use PageLevelExtraSchemaTrait;

            /** @param array<string, mixed> $data
             *  @param array<string, mixed> $pageData
             *  @return array<string, mixed>
             */
            public function apply(array $data, array $pageData): array
            {
                return $this->applyPageLevelExtras($data, $pageData);
            }
        };
    }

    #[Test]
    public function codeRepositoryIsAddedWhenPageFieldSet(): void
    {
        $out = $this->subject->apply([], [
            'tx_structuredcontent_code_repository' => 'https://github.com/example/foo',
        ]);

        self::assertSame('https://github.com/example/foo', $out['codeRepository']);
    }

    #[Test]
    public function codeRepositoryIsOmittedWhenFieldEmpty(): void
    {
        $out = $this->subject->apply([], []);

        self::assertArrayNotHasKey('codeRepository', $out);
    }

    #[Test]
    public function sameAsParsesNewlineSeparatedUrls(): void
    {
        $out = $this->subject->apply([], [
            'tx_structuredcontent_same_as' => "https://extensions.typo3.org/extension/foo\nhttps://packagist.org/packages/example/foo",
        ]);

        self::assertSame([
            'https://extensions.typo3.org/extension/foo',
            'https://packagist.org/packages/example/foo',
        ], $out['sameAs']);
    }

    #[Test]
    public function sameAsCollapsesSingleUrlToString(): void
    {
        $out = $this->subject->apply([], [
            'tx_structuredcontent_same_as' => 'https://github.com/example/foo',
        ]);

        // 1 URL → String (nicht Array of one) für sauberen JSON.
        self::assertSame('https://github.com/example/foo', $out['sameAs']);
    }

    #[Test]
    public function sameAsDropsInvalidUrlsLikingNonHttp(): void
    {
        // Defensive: nur http(s)-URLs sind Schema.org-konforme sameAs-Werte.
        $out = $this->subject->apply([], [
            'tx_structuredcontent_same_as' => "https://valid.example/\nnot-a-url\nftp://old.example/",
        ]);

        self::assertSame('https://valid.example/', $out['sameAs']);
    }

    #[Test]
    public function aboutBuildsListFromEntriesWithDefaultThingType(): void
    {
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                ['schema_type' => 'SoftwareApplication', 'name' => 'TYPO3', 'same_as' => 'https://en.wikipedia.org/wiki/TYPO3'],
                ['schema_type' => 'Thing', 'name' => 'Kubernetes', 'same_as' => 'https://en.wikipedia.org/wiki/Kubernetes'],
            ],
        ]);

        self::assertSame([
            ['@type' => 'SoftwareApplication', 'name' => 'TYPO3', 'sameAs' => 'https://en.wikipedia.org/wiki/TYPO3'],
            ['@type' => 'Thing', 'name' => 'Kubernetes', 'sameAs' => 'https://en.wikipedia.org/wiki/Kubernetes'],
        ], $out['about']);
    }

    #[Test]
    public function aboutCollapsesSingleSubjectToObject(): void
    {
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                ['schema_type' => 'Thing', 'name' => 'Kubernetes', 'same_as' => 'https://en.wikipedia.org/wiki/Kubernetes'],
            ],
        ]);

        // Bei 1 Subject: als Objekt, nicht Array of one.
        self::assertSame([
            '@type' => 'Thing',
            'name' => 'Kubernetes',
            'sameAs' => 'https://en.wikipedia.org/wiki/Kubernetes',
        ], $out['about']);
    }

    #[Test]
    public function aboutOverridesExistingAboutValueInData(): void
    {
        // Wenn aboutEntries vorhanden sind, ersetzen sie eine eventuelle
        // Default-Referenz auf #organization, weil Schema.org `about`
        // thematische Subjects meint — Organization gehoert zu publisher.
        $out = $this->subject->apply(
            ['about' => ['@id' => 'https://example.org#organization']],
            ['_aboutEntries' => [['schema_type' => 'Thing', 'name' => 'Kubernetes']]],
        );

        self::assertSame('Thing', $out['about']['@type']);
        self::assertSame('Kubernetes', $out['about']['name']);
    }

    #[Test]
    public function aboutSkipsEntriesWithEmptyName(): void
    {
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                ['schema_type' => 'Thing', 'name' => '', 'same_as' => 'https://example.com'],
                ['schema_type' => 'Thing', 'name' => 'Valid'],
            ],
        ]);

        self::assertSame(['@type' => 'Thing', 'name' => 'Valid'], $out['about']);
    }

    #[Test]
    public function aboutOmitsSameAsForNonHttpUrl(): void
    {
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                ['schema_type' => 'Thing', 'name' => 'Foo', 'same_as' => 'not-a-url'],
            ],
        ]);

        self::assertArrayNotHasKey('sameAs', $out['about']);
    }

    #[Test]
    public function aboutDefaultsToThingWhenSchemaTypeBlank(): void
    {
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                ['schema_type' => '', 'name' => 'Foo'],
            ],
        ]);

        self::assertSame('Thing', $out['about']['@type']);
    }

    #[Test]
    public function emptyAboutEntriesKeepsExistingAboutInData(): void
    {
        // Spec-Verhalten: wenn keine aboutEntries gepflegt, bleibt das
        // existing `about => #organization`-Default vom Generator unangetastet.
        $existing = ['about' => ['@id' => 'https://example.org#organization']];

        $out = $this->subject->apply($existing, []);

        self::assertSame($existing, $out);
    }

    #[Test]
    public function glossaryRefsAreAppendedToManualAboutEntries(): void
    {
        // dpn-glossary-Integration: Glossar-Refs aus dem Middleware-HTML-Scan
        // werden ergaenzend zu manuellen About-Subjects emittiert (nicht
        // ersetzend). Editor pflegt Schema-Subjects, Auto-Parser fuegt
        // referenzierte Terms dazu.
        $out = $this->subject->apply([], [
            '_siteBase' => 'https://example.org/',
            '_aboutEntries' => [
                ['schema_type' => 'Thing', 'name' => 'Kubernetes', 'same_as' => 'https://en.wikipedia.org/wiki/Kubernetes'],
            ],
            '_glossaryRefs' => ['ai-ready-cms', 'devsecops'],
            '_glossaryBasePath' => '/glossar',
        ]);

        self::assertSame([
            ['@type' => 'Thing', 'name' => 'Kubernetes', 'sameAs' => 'https://en.wikipedia.org/wiki/Kubernetes'],
            ['@id' => 'https://example.org/glossar/ai-ready-cms#term'],
            ['@id' => 'https://example.org/glossar/devsecops#term'],
        ], $out['about']);
    }

    #[Test]
    public function glossaryRefsAloneStillEmitAboutArray(): void
    {
        // Auch ohne manuell gepflegte aboutEntries muss `about` aus
        // _glossaryRefs alleine gefuellt werden.
        $out = $this->subject->apply([], [
            '_siteBase' => 'https://example.org/',
            '_glossaryRefs' => ['typo3'],
            '_glossaryBasePath' => '/glossar',
        ]);

        self::assertSame(['@id' => 'https://example.org/glossar/typo3#term'], $out['about']);
    }

    #[Test]
    public function emptyGlossaryRefsDoesNotInfluenceOutput(): void
    {
        // Wenn weder aboutEntries noch glossaryRefs gepflegt sind, bleibt das
        // existing `about` (z.B. Default-Organization-Ref vom WebPage-Generator)
        // unveraendert.
        $existing = ['about' => ['@id' => 'https://example.org#organization']];

        $out = $this->subject->apply($existing, ['_glossaryRefs' => []]);

        self::assertSame($existing, $out);
    }

    #[Test]
    public function glossaryRefBasePathDefaultsToGlossarWhenMissing(): void
    {
        // Wenn _glossaryBasePath nicht im pageData ist (Service-Resolve war
        // nicht erfolgreich), fallt der Trait auf '/glossar' zurueck.
        $out = $this->subject->apply([], [
            '_siteBase' => 'https://example.org/',
            '_glossaryRefs' => ['kubernetes'],
        ]);

        self::assertSame(['@id' => 'https://example.org/glossar/kubernetes#term'], $out['about']);
    }

    #[Test]
    public function aboutSameAsRendersAsArrayWhenMultipleUrls(): void
    {
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                [
                    'schema_type' => 'Thing',
                    'name' => 'Kubernetes',
                    'same_as' => "https://de.wikipedia.org/wiki/Kubernetes\nhttps://www.wikidata.org/wiki/Q22661306\nhttps://example.org/glossar/kubernetes",
                ],
            ],
        ]);

        self::assertSame([
            '@type' => 'Thing',
            'name' => 'Kubernetes',
            'sameAs' => [
                'https://de.wikipedia.org/wiki/Kubernetes',
                'https://www.wikidata.org/wiki/Q22661306',
                'https://example.org/glossar/kubernetes',
            ],
        ], $out['about']);
    }

    #[Test]
    public function aboutSameAsRemainsStringWhenSingleUrl(): void
    {
        // Backward compatibility: existing single-URL entries keep emitting
        // sameAs as a string so consumers built against the old format keep
        // working. Only multi-URL entries upgrade to an array.
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                ['schema_type' => 'Thing', 'name' => 'TYPO3', 'same_as' => 'https://typo3.org'],
            ],
        ]);

        self::assertSame([
            '@type' => 'Thing',
            'name' => 'TYPO3',
            'sameAs' => 'https://typo3.org',
        ], $out['about']);
    }

    #[Test]
    public function aboutSameAsOmittedWhenAllUrlsInvalid(): void
    {
        // Non-http lines + blank lines → no valid URLs remain → sameAs must
        // be absent entirely (not [] and not the rejected strings).
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                [
                    'schema_type' => 'Thing',
                    'name' => 'Kubernetes',
                    'same_as' => "ftp://example.org/no-scheme\n   \njavascript:alert(1)",
                ],
            ],
        ]);

        self::assertSame([
            '@type' => 'Thing',
            'name' => 'Kubernetes',
        ], $out['about']);
        self::assertArrayNotHasKey('sameAs', $out['about']);
    }

    #[Test]
    public function aboutSameAsFiltersInvalidLines(): void
    {
        // Mix of valid + invalid URLs → only the valid http(s) URLs survive.
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                [
                    'schema_type' => 'Thing',
                    'name' => 'Kubernetes',
                    'same_as' => "https://de.wikipedia.org/wiki/Kubernetes\nnot-a-url\nhttps://www.wikidata.org/wiki/Q22661306",
                ],
            ],
        ]);

        self::assertSame([
            'https://de.wikipedia.org/wiki/Kubernetes',
            'https://www.wikidata.org/wiki/Q22661306',
        ], $out['about']['sameAs']);
    }

    #[Test]
    public function aboutSameAsDedupesIdenticalEntries(): void
    {
        // Editor mistakenly pastes the same URL twice — output should still
        // contain it once. Two distinct URLs make it through.
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                [
                    'schema_type' => 'Thing',
                    'name' => 'Kubernetes',
                    'same_as' => "https://de.wikipedia.org/wiki/Kubernetes\nhttps://www.wikidata.org/wiki/Q22661306\nhttps://de.wikipedia.org/wiki/Kubernetes",
                ],
            ],
        ]);

        self::assertSame([
            'https://de.wikipedia.org/wiki/Kubernetes',
            'https://www.wikidata.org/wiki/Q22661306',
        ], $out['about']['sameAs']);
    }

    #[Test]
    public function aboutSameAsTrimsWhitespacePerLine(): void
    {
        // Whitespace around URLs (leading/trailing spaces, mixed line endings)
        // must be normalised before validation.
        $out = $this->subject->apply([], [
            '_aboutEntries' => [
                [
                    'schema_type' => 'Thing',
                    'name' => 'Kubernetes',
                    'same_as' => "  https://de.wikipedia.org/wiki/Kubernetes  \r\n\t https://www.wikidata.org/wiki/Q22661306\t",
                ],
            ],
        ]);

        self::assertSame([
            'https://de.wikipedia.org/wiki/Kubernetes',
            'https://www.wikidata.org/wiki/Q22661306',
        ], $out['about']['sameAs']);
    }
}
