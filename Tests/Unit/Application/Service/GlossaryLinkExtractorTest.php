<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Application\Service;

use Koh\StructuredContent\Application\Service\GlossaryLinkExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Welche Glossar-Begriffe eine gerenderte Seite verlinkt.
 *
 * Der dpn-glossary-Parser schreibt `<a href="…" title="…" class="dpnglossary
 * link">`. Das href ist `#<slug>`, solange er keine Detailseite kennt, sonst
 * die Detail-URL des Begriffs, in jeder Sprache mit ihrem eigenen Pfad.
 */
final class GlossaryLinkExtractorTest extends TestCase
{
    private const PATHS = ['/glossar', '/en/glossary'];

    private const HOSTS = ['example.com'];

    /**
     * @return list<string>
     */
    private function extract(string $html): array
    {
        return new GlossaryLinkExtractor()->extractSlugs($html, self::PATHS, self::HOSTS);
    }

    /**
     * Das Markup, das der Parser heute ausliefert: href zuerst, dann title,
     * dann class. Die alte Erkennung erwartete class vor href.
     */
    #[Test]
    public function fragmentLinksAreFoundWhateverTheAttributeOrder(): void
    {
        $html = '<p><a href="#cve" title="Eine öffentliche Kennung für Schwachstellen" class="dpnglossary link">CVE</a>'
            . ' und <a class="dpnglossary link" title="SBOM" href="#sbom">SBOM</a></p>';

        self::assertSame(['cve', 'sbom'], $this->extract($html));
    }

    #[Test]
    public function detailPageLinksAreFoundRelativeAndAbsoluteInBothLanguages(): void
    {
        $html = '<a href="/glossar/cve" title="…" class="dpnglossary link">CVE</a>'
            . '<a href="https://example.com/glossar/sbom" class="dpnglossary link">SBOM</a>'
            . '<a href="/en/glossary/devsecops" title="…" class="dpnglossary link">DevSecOps</a>'
            . '<a href="https://example.com/en/glossary/supply-chain-attack/" class="dpnglossary link">x</a>';

        self::assertSame(['cve', 'sbom', 'devsecops', 'supply-chain-attack'], $this->extract($html));
    }

    #[Test]
    public function singleQuotesEntitiesAndExtraClassesAreAccepted(): void
    {
        $html = "<a data-x='1' class='link dpnglossary is-term' href='/glossar/cve?foo=1&amp;bar=2#top'>CVE</a>";

        self::assertSame(['cve'], $this->extract($html));
    }

    #[Test]
    public function eachTermIsReportedOnce(): void
    {
        $html = '<a href="#cve" class="dpnglossary link">CVE</a>'
            . '<a href="/glossar/cve" class="dpnglossary link">CVE</a>'
            . '<a href="/en/glossary/cve" class="dpnglossary link">CVE</a>';

        self::assertSame(['cve'], $this->extract($html));
    }

    /**
     * Mit `term_mode = link` zeigt ein Begriff auf eine beliebige URL. Deren
     * letztes Segment sagt nichts ueber den Begriff; ebenso wenig ein Link
     * auf eine fremde Site oder ein Link ohne beide Parser-Klassen.
     */
    #[Test]
    public function linksThatAreNotTermReferencesAreIgnored(): void
    {
        $html = '<a href="/blog/cve" class="dpnglossary link">custom target</a>'
            . '<a href="https://other.example/glossar/cve" class="dpnglossary link">foreign host</a>'
            . '<a href="/glossar/cve" class="link">no parser class</a>'
            . '<a href="/glossar/cve" class="dpnglossary">no link class</a>'
            . '<a href="/glossar" class="dpnglossary link">the glossary itself</a>'
            . '<a href="mailto:cve@example.com" class="dpnglossary link">mail</a>'
            . '<a href="#" class="dpnglossary link">empty fragment</a>'
            . '<a href="/glossar/sub/cve" class="dpnglossary link">deeper path</a>';

        self::assertSame([], $this->extract($html));
    }

    #[Test]
    public function withoutAnyParserLinkNothingIsFound(): void
    {
        self::assertSame([], $this->extract('<html><body><a href="/glossar/cve">CVE</a></body></html>'));
        self::assertSame([], $this->extract(''));
    }
}
