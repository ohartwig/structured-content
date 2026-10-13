<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Schema.org-Typen die als `about`-Subject einer Page sinnvoll sind.
// Thing als Fallback fuer alles was nicht in eine spezifischere Klasse passt.
// Reihenfolge: nach erwarteter Nutzungsfrequenz im Content-Editorial-Kontext.
$schemaTypeItems = [
    ['label' => 'Thing (generisch)', 'value' => 'Thing'],
    // Technik / Software
    ['label' => 'SoftwareApplication', 'value' => 'SoftwareApplication'],
    ['label' => 'SoftwareSourceCode', 'value' => 'SoftwareSourceCode'],
    ['label' => 'WebApplication', 'value' => 'WebApplication'],
    ['label' => 'MobileApplication', 'value' => 'MobileApplication'],
    // Akteure
    ['label' => 'Organization', 'value' => 'Organization'],
    ['label' => 'Corporation', 'value' => 'Corporation'],
    ['label' => 'LocalBusiness', 'value' => 'LocalBusiness'],
    ['label' => 'NewsMediaOrganization', 'value' => 'NewsMediaOrganization'],
    ['label' => 'GovernmentOrganization', 'value' => 'GovernmentOrganization'],
    ['label' => 'EducationalOrganization', 'value' => 'EducationalOrganization'],
    ['label' => 'Person', 'value' => 'Person'],
    ['label' => 'Brand', 'value' => 'Brand'],
    // Ort / Zeit
    ['label' => 'Place', 'value' => 'Place'],
    ['label' => 'TouristAttraction', 'value' => 'TouristAttraction'],
    ['label' => 'Event', 'value' => 'Event'],
    // Inhalte / Werke
    ['label' => 'CreativeWork', 'value' => 'CreativeWork'],
    ['label' => 'Article', 'value' => 'Article'],
    ['label' => 'Book', 'value' => 'Book'],
    ['label' => 'Movie', 'value' => 'Movie'],
    ['label' => 'TVSeries', 'value' => 'TVSeries'],
    ['label' => 'MusicAlbum', 'value' => 'MusicAlbum'],
    ['label' => 'Photograph', 'value' => 'Photograph'],
    ['label' => 'WebSite', 'value' => 'WebSite'],
    ['label' => 'WebPage', 'value' => 'WebPage'],
    // Angebote
    ['label' => 'Product', 'value' => 'Product'],
    ['label' => 'Service', 'value' => 'Service'],
    // Begriffe / Klassifikation
    ['label' => 'DefinedTerm', 'value' => 'DefinedTerm'],
    ['label' => 'Taxon', 'value' => 'Taxon'],
];

return [
    'ctrl' => [
        'title' => 'About-Entry (Schema.org-Subject)',
        'label' => 'name',
        'label_alt' => 'schema_type',
        'label_alt_force' => true,
        // Standard-Lifecycle-Felder — Pflicht damit TYPO3 Records anlegen +
        // updaten kann. Ohne `tstamp`/`crdate`/`delete`/`enablecolumns.disabled`
        // schlaegt das Backend-Save mit 'Unknown column' fehl.
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        // i18n: Lokalisierte Records koennen pro Sprache abweichende
        // Schema.org-Subjects pflegen (Wikipedia-URL auf englischer vs.
        // deutscher Variante).
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'translationSource' => 'l10n_source',
        'sortby' => 'sorting',
        // Records duerfen auf doktype=1-Pages (Standard) als IRRE-Kinder
        // angelegt werden — ohne diesen Flag verweigert TYPO3 das Anlegen
        // weil die Tabelle hideTable=true ist.
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'iconfile' => 'EXT:structured_content/Resources/Public/Icons/relation.svg',
        'searchFields' => 'name,same_as,schema_type',
        'hideTable' => true,
    ],
    'types' => [
        '0' => [
            'showitem' => 'schema_type, name, same_as',
        ],
    ],
    'columns' => [
        'schema_type' => [
            'label' => 'Schema.org Type',
            'description' => 'Schema.org-Klasse des Subjects. "Thing" als generischer Fallback wenn keine spezifischere Klasse passt.',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => $schemaTypeItems,
                'default' => 'Thing',
                'required' => true,
            ],
        ],
        'name' => [
            'label' => 'Name',
            'description' => 'Bezeichnung des Subjects (z.B. "TYPO3", "Kubernetes", "Open Source Software").',
            'config' => [
                'type' => 'input',
                'size' => 40,
                'eval' => 'trim',
                'required' => true,
            ],
        ],
        'same_as' => [
            'label' => 'sameAs URLs',
            'description' => 'Externe Identitaeten pro Zeile (z.B. https://de.wikipedia.org/wiki/Kubernetes, https://www.wikidata.org/wiki/Q22661306, eigener Glossar-Eintrag). Eine URL pro Zeile. Hilft Crawlern, das Subject einer bekannten Entity zuzuordnen.',
            'config' => [
                'type' => 'text',
                'cols' => 80,
                'rows' => 4,
                'eval' => 'trim',
                'placeholder' => "https://de.wikipedia.org/wiki/...\nhttps://www.wikidata.org/wiki/...",
            ],
        ],
    ],
];
