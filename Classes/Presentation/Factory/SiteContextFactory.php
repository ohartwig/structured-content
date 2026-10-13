<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Presentation\Factory;

use Koh\StructuredContent\Application\Dto\SiteContext;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Baut das SiteContext-Wertobjekt aus TYPO3s Site und SiteLanguage.
 *
 * Steht in der Presentation-Schicht, weil nur sie und Infrastructure laut
 * deptrac-Regelsatz auf Framework zeigen duerfen. Genau hier endet der
 * TYPO3-Anteil: was weiter nach innen geht, ist ein Wertobjekt ohne
 * Framework-Bezug.
 *
 * Eine eigene Klasse und keine private Methode in den beiden Middlewares: sonst
 * traegt jede ihre eigene Uebersetzung, und die zweite Kopie ist die, die beim
 * naechsten Feld vergessen wird.
 */
final readonly class SiteContextFactory
{
    public function create(Site $site, ?SiteLanguage $language = null): SiteContext
    {
        // Die Vorbelegung stand vorher im Service. Sie gehoert hierher: dass die
        // Standardsprache einspringt, wenn keine gewaehlt ist, ist eine
        // Eigenschaft von TYPO3s Site-Objekt und keine der llms-Erzeugung.
        $language ??= $site->getDefaultLanguage();

        // The same source JsonLdInjectorMiddleware hands to the schema
        // generators as `_languageSchema`, so llms.txt describes the site with
        // the same sentence as Organization.description.
        $languageStructuredContent = $language->toArray()['structuredContent'] ?? [];

        return new SiteContext(
            rootPageId: $site->getRootPageId(),
            siteIdentifier: $site->getIdentifier(),
            siteWebsiteTitle: (string) ($site->getConfiguration()['websiteTitle'] ?? ''),
            settings: $site->getSettings()->getAll(),
            siteBase: (string) $site->getBase(),
            languageId: $language->getLanguageId(),
            base: (string) $language->getBase(),
            languageIsoCode: $language->getLocale()->getLanguageCode(),
            languageWebsiteTitle: $language->getWebsiteTitle(),
            languageStructuredContent: is_array($languageStructuredContent) ? $languageStructuredContent : [],
        );
    }
}
