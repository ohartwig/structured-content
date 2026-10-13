<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\SchemaOrg;

/**
 * Shared Schema-Anreicherung aus Per-Page-TCA-Feldern:
 * - tx_structuredcontent_code_repository → codeRepository
 * - tx_structuredcontent_same_as         → sameAs (one URL per line)
 * - _aboutEntries (vom Middleware vorab geladen) → about-Array mit
 *   Schema.org-Subjects {@type, name, sameAs}.
 *
 * Wird von WebPage/Article/Service-Generatoren verwendet, damit Redaktion
 * dieselben Felder im Backend pflegt und alle drei Schema-Typen konsistent
 * angereichert werden.
 *
 * Hinweis: Die `about`-Liste ersetzt eine eventuell vorhandene Default-
 * Referenz auf #organization, weil Schema.org `about` thematische Subjects
 * meint — die Organization ist via `publisher`/`provider` schon im Graph.
 */
trait PageLevelExtraSchemaTrait
{
    /**
     * @param array<string, mixed> $data      Aktueller Schema-Knoten — wird mutiert
     * @param array<string, mixed> $pageData  $pageData aus dem Generator
     *
     * @return array<string, mixed> angereicherter Knoten
     */
    private function applyPageLevelExtras(array $data, array $pageData): array
    {
        $repo = trim((string) ($pageData['tx_structuredcontent_code_repository'] ?? ''));
        if ('' !== $repo) {
            $data['codeRepository'] = $repo;
        }

        $sameAs = $this->parsePageSameAs($pageData);
        if ([] !== $sameAs) {
            // Bei 1 URL: String statt Array — Schema.org-konform, sauberer JSON.
            $data['sameAs'] = 1 === count($sameAs) ? $sameAs[0] : $sameAs;
        }

        $about = $this->buildAboutSubjects($pageData);
        if ([] !== $about) {
            // Ueberschreibt die Default-Organization-Referenz mit thematischen
            // Subjects — Spec-konform (Organization gehoert zu publisher, nicht
            // about). Bei nur 1 Subject: als Objekt statt Array.
            $data['about'] = 1 === count($about) ? $about[0] : $about;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $pageData
     *
     * @return list<string>
     */
    private function parsePageSameAs(array $pageData): array
    {
        return $this->parseSameAsLines((string) ($pageData['tx_structuredcontent_same_as'] ?? ''));
    }

    /**
     * Parse a newline-separated list of URLs into a deduplicated list of
     * http(s) URIs. Shared between page-level `tx_structuredcontent_same_as`
     * (Article/WebPage `sameAs`) and per-about-entry `same_as` (the Thing
     * subjects in `about[]`). One URL per line — more robust than CSV given
     * how long URLs with query strings get.
     *
     * Skips lines without a `http(s)://` prefix because Schema.org requires
     * a complete URI for sameAs.
     *
     * @return list<string>
     */
    private function parseSameAsLines(string $raw): array
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $urls = array_values(array_filter(
            array_map('trim', $lines === false ? [] : $lines),
            static fn (string $value): bool => '' !== $value,
        ));

        $valid = array_values(array_filter(
            $urls,
            static fn(string $url): bool => 1 === preg_match('#^https?://#i', $url),
        ));

        return array_values(array_unique($valid));
    }

    /**
     * @param array<string, mixed> $pageData
     *
     * @return list<array<string, mixed>>
     */
    private function buildAboutSubjects(array $pageData): array
    {
        $subjects = [];

        // Manuell gepflegte Subjects aus tx_structuredcontent_about_entry.
        $entries = $pageData['_aboutEntries'] ?? [];
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $name = trim((string) ($entry['name'] ?? ''));
                if ('' === $name) {
                    continue;
                }
                $type = trim((string) ($entry['schema_type'] ?? 'Thing'));
                if ('' === $type) {
                    $type = 'Thing';
                }
                $subject = [
                    '@type' => $type,
                    'name' => $this->sanitizeText($name),
                ];
                // sameAs is one URL per line in the textarea — collapse to a
                // single string when only one URL remains, emit an array for
                // multiple. Omit the field entirely when no valid URL is set.
                $sameAsList = $this->parseSameAsLines((string) ($entry['same_as'] ?? ''));
                if ($sameAsList !== []) {
                    $subject['sameAs'] = count($sameAsList) === 1 ? $sameAsList[0] : $sameAsList;
                }
                $subjects[] = $subject;
            }
        }

        // Glossary-Refs aus dem JsonLdInjectorMiddleware-HTML-Scan
        // (dpn-glossary-Auto-Parser-Links). Werden ANGEHAENGT, nicht
        // ersetzt — Editor-gepflegte about-Subjects haben den Vorrang in der
        // Reihenfolge, Glossar-Begriffe ergaenzen.
        $glossaryRefs = $pageData['_glossaryRefs'] ?? [];
        if (is_array($glossaryRefs) && [] !== $glossaryRefs) {
            $siteBase = rtrim((string) ($pageData['_siteBase'] ?? ''), '/');
            $glossaryBase = '/' . trim((string) ($pageData['_glossaryBasePath'] ?? '/glossar'), '/');
            foreach ($glossaryRefs as $slug) {
                if (!is_string($slug) || '' === $slug) {
                    continue;
                }
                $subjects[] = [
                    '@id' => $siteBase . $glossaryBase . '/' . $slug . '#term',
                ];
            }
        }

        return $subjects;
    }
}
