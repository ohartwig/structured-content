<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Service;

/**
 * Finds the glossary terms a rendered page links to.
 *
 * The dpn-glossary parser wraps every term it finds in the content in a link
 * carrying the classes `dpnglossary` and `link`. Where that link points to
 * depends on the parser's configuration:
 *
 *   <a href="#cve" title="…" class="dpnglossary link">          (no detail page)
 *   <a href="/glossar/cve" title="…" class="dpnglossary link">  (detail page)
 *   <a href="https://example.org/en/glossary/cve" …>             (absolute)
 *
 * Attribute order and quoting are the parser's business and change with the
 * libxml version that serialises the DOM, so the tag is split into attributes
 * rather than matched as one pattern.
 *
 * A link pointing somewhere else than a fragment or a term detail page is not a
 * term reference: with `term_mode = link` an editor can point a term at any
 * URL, and its last path segment says nothing about the term. Candidates are
 * returned in order of appearance, without duplicates; whether a slug belongs
 * to an existing term is for the caller to check.
 */
final class GlossaryLinkExtractor
{
    private const SLUG_PATTERN = '/^[a-z0-9][a-z0-9\-]*$/i';

    /**
     * @param list<string> $glossaryPaths URL paths of the glossary page, one per
     *                                    language (e.g. "/glossar", "/en/glossary")
     * @param list<string> $hosts         hosts an absolute link may point to;
     *                                    empty accepts any host
     *
     * @return list<string>
     */
    public function extractSlugs(string $html, array $glossaryPaths, array $hosts = []): array
    {
        if ('' === $html || !str_contains($html, 'dpnglossary')) {
            return [];
        }
        if (false === preg_match_all('~<a\s[^>]*>~i', $html, $tags)) {
            return [];
        }

        $paths = [];
        foreach ($glossaryPaths as $path) {
            $normalized = $this->normalizePath($path);
            if ('' !== $normalized) {
                $paths[$normalized] = true;
            }
        }
        $allowedHosts = array_map(strtolower(...), $hosts);

        $slugs = [];
        foreach ($tags[0] as $tag) {
            $attributes = $this->parseAttributes($tag);
            $classes = preg_split('/\s+/', strtolower($attributes['class'] ?? ''));
            if (false === $classes) {
                continue;
            }
            if (!in_array('dpnglossary', $classes, true) || !in_array('link', $classes, true)) {
                continue;
            }
            $slug = $this->slugFromHref($attributes['href'] ?? '', $paths, $allowedHosts);
            if (null !== $slug) {
                $slugs[$slug] = true;
            }
        }

        return array_map(strval(...), array_keys($slugs));
    }

    /**
     * @param array<string, true> $glossaryPaths
     * @param list<string>        $hosts
     */
    private function slugFromHref(string $href, array $glossaryPaths, array $hosts): ?string
    {
        $href = trim($href);
        if ('' === $href) {
            return null;
        }

        if (str_starts_with($href, '#')) {
            return $this->validSlug(substr($href, 1));
        }

        $parts = parse_url($href);
        if (false === $parts) {
            return null;
        }
        if (isset($parts['host'])) {
            $scheme = strtolower($parts['scheme'] ?? 'https');
            if (!in_array($scheme, ['http', 'https'], true)) {
                return null;
            }
            if ([] !== $hosts && !in_array(strtolower($parts['host']), $hosts, true)) {
                return null;
            }
        } elseif (isset($parts['scheme'])) {
            // mailto:, tel:, javascript: …
            return null;
        }

        $path = $this->normalizePath(rawurldecode($parts['path'] ?? ''));
        $separator = strrpos($path, '/');
        if (false === $separator || 0 === $separator) {
            return null;
        }
        if (!isset($glossaryPaths[substr($path, 0, $separator)])) {
            return null;
        }

        return $this->validSlug(substr($path, $separator + 1));
    }

    private function validSlug(string $candidate): ?string
    {
        $candidate = strtolower(rawurldecode($candidate));

        return 1 === preg_match(self::SLUG_PATTERN, $candidate) ? $candidate : null;
    }

    /**
     * "/en/glossary/" and "en/glossary" both become "/en/glossary"; the root
     * path becomes "" because no term lives directly below it.
     */
    private function normalizePath(string $path): string
    {
        $trimmed = trim($path, '/');

        return '' === $trimmed ? '' : '/' . strtolower($trimmed);
    }

    /**
     * @return array<string, string> attribute name (lower case) => decoded value
     */
    private function parseAttributes(string $tag): array
    {
        preg_match_all(
            '~([^\s=/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~',
            substr($tag, 2),
            $matches,
            PREG_SET_ORDER,
        );

        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (isset($attributes[$name])) {
                // HTML keeps the first occurrence of a duplicated attribute.
                continue;
            }
            $value = ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
            $attributes[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }
}
