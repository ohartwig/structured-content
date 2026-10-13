<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Application\Dto;

/**
 * Alles, was die llms.txt-Erzeugung ueber Site und Sprache wissen muss.
 *
 * Ersetzt TYPO3\CMS\Core\Site\Entity\Site und SiteLanguage in der Signatur von
 * LlmsTxtGeneratorService. Beide gehoeren zur Framework-Schicht, die die
 * Application-Schicht laut deptrac-Regelsatz nicht sehen darf.
 *
 * Der Zuschnitt ist nicht geraten, sondern abgelesen: genau diese acht Werte
 * hat der Service den beiden Objekten entnommen. Ein Wertobjekt, das mehr
 * mitbringt, waere eine Einladung, spaeter mehr Framework in die Anwendungslogik
 * zu ziehen — der Umweg, den dieser Umbau gerade schliesst.
 *
 * Gebaut wird es in der Presentation-Schicht, die auf Framework zeigen darf.
 */
final readonly class SiteContext
{
    /**
     * @param array<string, mixed> $settings Site-Settings, wie sie der Service
     *                                       fuer die llms-Konfiguration liest.
     * @param array<string, mixed> $languageStructuredContent The
     *                                       `structuredContent` block of the
     *                                       language's site configuration. Site
     *                                       settings apply to every language;
     *                                       what must differ per language (the
     *                                       organizationDescription) lives
     *                                       there.
     */
    public function __construct(
        public int $rootPageId,
        public string $siteIdentifier,
        public string $siteWebsiteTitle,
        public array $settings,
        public string $siteBase,
        public int $languageId,
        // Der Base der SPRACHE, nicht der der Site. buildPageMarkdown() nutzt
        // $siteBase, die llms-Erzeugung diesen hier — in mehrsprachigen
        // Installationen sind das verschiedene Praefixe, und sie zu
        // verwechseln erzeugt Links, die auf die falsche Sprachversion zeigen.
        public string $base,
        public string $languageIsoCode,
        public string $languageWebsiteTitle,
        public array $languageStructuredContent = [],
    ) {}
}
