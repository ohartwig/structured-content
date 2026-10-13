# Structured Content — TYPO3 Extension

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Schema.org JSON-LD generation, AI-context annotations, and content-relationship management for TYPO3 14. Owns the full Schema.org generator stack plus audience/tone/channel inheritance and typed cross-content relationships.

## Features

- **Schema.org Generator Stack** — Priority-ordered generators emit Article, WebPage, FAQ, Organization (Corporation + ProfessionalService), Service, Product, Person, ProfilePage, ItemList, DefinedTerm/DefinedTermSet, Review, BreadcrumbList, SoftwareApplication, PodcastSeries, PodcastEpisode JSON-LD
- **Corporation / ProfessionalService Organization** (v2.1+) — Multi-Type Organization mit `contactPoint[]` (per-Site CSV `contactPointTypes`), strukturierter `areaServed` (Country/AdministrativeArea-Mapping), `knowsAbout`, `sameAs` (host-basiert ohne eigene Site gefiltert), `foundingDate`, `image`+`logo`. 7 neue Site-Settings: `addressLocality`, `addressCountry`, `email`, `foundingDate`, `sameAs`, `knowsAbout`, `contactPointTypes`.
- **Per-Page Schema Felder** (v2.2+) — TCA-Felder `tx_structuredcontent_same_as` (Textarea, eine URL pro Zeile), `tx_structuredcontent_code_repository` (Input) und `tx_structuredcontent_about` (IRRE-Inline auf `tx_structuredcontent_about_entry`). Generator-Trait `PageLevelExtraSchemaTrait` reicht die Felder an WebPage/Article/Service. sameAs filtert die eigene Site host-basiert raus, about ersetzt die Default-`#organization`-Referenz wenn Subjects gepflegt sind.
- **Standalone Person + Cross-Refs** (v2.3+) — `PersonPageSchemaGenerator` emittiert auf `/autor/<slug>` die volle Person-Entity mit stabiler `@id = <siteBase>/autor/<slug>#person`. Article/SoftwareApplication/PodcastEpisode-Generatoren referenzieren Authoren via `{@id}` statt inline duplizierte Person-Objekte. Inline-Fallback bleibt für slug-lose Authoren (externe Gast-Posts). `PersonSchemaBuilder` füllt `url`, `image` (FAL), `worksFor.@id`, `sameAs`, `knowsAbout`, `knowsLanguage`, `jobTitle`, `alumniOf`.
- **ItemList für Hub-Pages** (v2.3+) — `ItemListSchemaGenerator` erkennt `moselwal_cards`/`smallcards`/`smallcardscarousel`/`teaser`-CTypes und emittiert pro Block eine `ItemList` mit `name` (Block-Header), `description` (Subheader), `itemListElement` (aus `moselwal_card`-Records mit `position`/`name`/`url`). Mehrere Blöcke auf einer Page → ItemLists in `@graph`.
- **Glossar / DefinedTerm + DefinedTermSet** (v2.4+) — Conditional Integration mit `featdd/dpn-glossary` (nur aktiv wenn die Extension geladen ist, `GlossaryTermService::isAvailable()` als Guard). Auf der Listing-Page (`/glossar`) wird `DefinedTermSet` mit `@id = <siteBase>/glossar#termset` und `hasDefinedTerm[]`-Refs emittiert; auf Detail-Pages (`/glossar/<slug>`) ein `DefinedTerm` mit `@id = <siteBase>/glossar/<slug>#term`, `name`, `description` (aus `tx_dpnglossary_domain_model_description`, Fallback `meta_description`/`tooltiptext`), `alternateName` (aus `tx_dpnglossary_domain_model_synonym`), `inDefinedTermSet.@id`. Zusätzlich scannt die `JsonLdInjectorMiddleware` den FE-Response-Body nach dpn-glossary-Auto-Parser-Links (`<a class="dpnglossary link" href="#slug">`) und hängt die referenzierten Terms als `{@id: ...#term}`-Subjects an `about` an — additive zu manuellen `tx_structuredcontent_about_entry`-Einträgen.
- **AI Context Inheritance** — Audience, tone, channel metadata kaskadiert durch den Page-Tree.
- **Content Relationships** — Typed, directional relationships mit Confidence-Scoring.
- **Category Mapping** — `sys_category` slug field + `articleSection` Property in Article JSON-LD + RTE-Description (für Kategorie-Hero-Blocks).
- **Channel-Aware Fields** — Restrict content fields per delivery channel (Website, AI Agent, Voice, Social).
- **AI Content Disclosure** — Article-Schema enthält bei AI-getaggten Hero-Bildern `image: {creator: {SoftwareApplication, name}, creditText: "AI-generated"}` (EU AI Act Art. 50). Pflege via `koh/content-provenance`-TCA-Felder.
- **Reading Time** — Article-Schema enthält `wordCount` + `timeRequired` (PT*M) — byte-identisch zur FE-Reading-Time-Badge (200 WPM).
- **Language-Fallback in Schema-Generators** — Translation-aware: default-Sprache-Records werden geladen + EN-Translations overlay'd. Schema-Output funktioniert auch unter `fallbackType: fallback` ohne Lücken.
- **CLI Tools** — Validation, export, gap analysis.
- **DDD 4-Layer Architecture** — Strict layer separation enforced by deptrac.

## Installation

```bash
composer require koh/structured-content
```

### Requirements

- PHP 8.3+
- TYPO3 14.0

## Schema.org Generators

`Classes/Infrastructure/SchemaOrg/` — alle implementieren `SchemaGeneratorInterface`,
werden via Service-Tag `structured_content.schema_generator` registriert und
nach `priority` gerendert (höher = früher im JSON-LD-Block).

| Generator | Priority | `@type` | Trigger |
|---|---|---|---|
| `BreadcrumbListSchemaGenerator` | 90 | `BreadcrumbList` | Jede Page mit Rootline |
| `DefinedTermSchemaGenerator` | 85 | `DefinedTerm` | `/glossar/{slug}` — dpn-glossary-Detail-Page (conditional auf `isLoaded('dpn_glossary')`); Output enthält `alternateName` aus Synonymen |
| `PersonPageSchemaGenerator` | 80 | `Person` (standalone) | `/autor/{slug}` — emittiert die volle Person-Entity mit `@id` für Cross-Page-Refs |
| `DefinedTermSetSchemaGenerator` | 78 | `DefinedTermSet` | Glossar-Listing-Page (`uid == dpnGlossary.settings.detailPage`); `hasDefinedTerm[]` als `@id`-Refs |
| `ProfilePageSchemaGenerator` | 75 | `ProfilePage` + `mainEntity.@id` + `hasPart` | `/autor/{slug}` — Wrapper-Page mit `@id`-Ref auf die Person (kein Inline) |
| `ReviewSchemaGenerator` | 68 | `Review` | Pages mit Testimonials-CB |
| `PodcastEpisodeSchemaGenerator` | 65 | `PodcastEpisode` | Podcast-Plugin Detail-View (mit `actor[]` als `@id`-Refs für Hosts + Guests) |
| `PodcastSeriesSchemaGenerator` | 60 | `PodcastSeries` | Podcast-Plugin List-View |
| `SoftwareApplicationSchemaGenerator` | 55 | `SoftwareApplication` | Pages mit `tx_structuredcontent_code_repository` gesetzt |
| `ArticleSchemaGenerator` | 50 | `Article` / `BlogPosting` / … | Article-Pages — `author` als `@id`-Ref auf `/autor/<slug>#person` |
| `FaqSchemaGenerator` | 45 | `FAQPage` | Pages mit FAQ-CB |
| `HowToSchemaGenerator` | 45 | `HowTo` | Pages mit Steps-CB |
| `WebPageSchemaGenerator` | 40 | `WebPage` | Fallback für alle Pages — liest Per-Page `same_as`/`code_repository`/`about` |
| `ItemListSchemaGenerator` | 35 | `ItemList` | Pages mit Card-CB (`moselwal_cards`/`smallcards`/`smallcardscarousel`/`teaser`) und non-empty Header |
| `WebSiteSchemaGenerator` | 30 | `WebSite` | Site-Root + Sub-Sites |
| `OrganizationSchemaGenerator` | 25 | `Organization` / `[Corporation,ProfessionalService]` | Site-weit aus settings; `@type` per `organizationTypes`-Setting konfigurierbar (Multi-Type ersetzt das Default `Organization`) |
| `ServiceSchemaGenerator` | 20 | `Service` | Pages mit Service-CB (skipped wenn SoftwareApplication owned) |
| `ProfessionalServiceSchemaGenerator` | 20 | `ProfessionalService` | Service-Pages mit Provider-Org |
| `PackagesGridEnterpriseServiceSchemaGenerator` | 18 | `Service`-Variants | Packages-Grid-CB |
| `PackagesGridProductSchemaGenerator` | 18 | `Product` | Packages-Grid-CB |
| `ProductSchemaGenerator` | 15 | `Product` | Pages mit Product-CB |
| `PersonSchemaGenerator` | 10 | `Person` | Team-CB — listet mehrere Persons als ItemList |
| `AutoDetectSchemaGenerator` | 5 | dynamic | TCA-driven Mapping |

### Builder + Traits

| Klasse | Zweck |
|---|---|
| `Application/Service/PersonSchemaBuilder` | `buildFromRow()` — emittiert Person mit `@id = <siteBase>/autor/<slug>#person`, `url`, `image` (FAL), `worksFor[]`, `sameAs[]` (mit `Label\|URL`-Pipe-Format), `knowsAbout`, `knowsLanguage`. `buildPersonReference()` liefert minimal `{@type:Person, @id}` für Cross-Refs. `AUTHOR_PATH = '/autor'` Konstante als single-source-of-truth. |
| `Application/Service/GlossaryTermService` (v2.4+) | Conditional-Integration mit `featdd/dpn-glossary`. `isAvailable()` ist der Guard (kein DB-Zugriff wenn die Extension nicht geladen ist). `findTermBySlug()`/`findAllTerms()`/`loadFirstDescription()`/`loadSynonyms()`/`resolveGlossaryBasePath()` (aus `dpnGlossary.settings.detailPage` → page-slug, Fallback `/glossar`)/`isGlossaryListingPage()`. Singleton mit per-Request-Cache. |
| `Infrastructure/SchemaOrg/PageLevelExtraSchemaTrait` (v2.2+) | `applyPageLevelExtras($data, $pageData)` — fügt `codeRepository`, `sameAs`, `about` an WebPage/Article/Service. sameAs filtert eigene Site host-basiert raus, about merged manuelle `_aboutEntries` mit Glossar-Refs aus `_glossaryRefs` (v2.4+, additive). |
| `Infrastructure/SchemaOrg/PersonRoutingArgumentTrait` | Resolved `person`-Slug aus `_routingArguments` der Person-Plugin-Detail-View. Nutzt PersonPage + ProfilePage. |
| `Infrastructure/SchemaOrg/PodcastFeedCacheReaderTrait` | Liest gecachte Feed-Items für Podcast-Schemata ohne Re-Fetch. Cache-Key `rssfeed_<sha256(feedUrl)>` (synchron mit `PodcastFeedService::cacheIdentifierFor()` — vorher md5, gefixt in v2.0.3). |
| `Infrastructure/SchemaOrg/SanitizesSchemaTrait` | Strip-Filter für leere/nicht-JSON-konforme Werte |
| `Infrastructure/SchemaOrg/ContextAndRelationshipsTrait` | Hängt AI-Context + Relationships an Schema-Output |

## Repositories

`Classes/Domain/Repository/`:

| Repository | Zweck |
|---|---|
| `AuthoredPagesRepository` | `findByPersonUid` — Pages, die `tx_structuredcontent_author_records` mit der Person verknüpfen (für `Person.hasPart`) |
| `CategoryRepository` | `findByPageUid` — `sys_category`-Records via `sys_category_record_mm` (für `Article.articleSection`) |
| `EpisodeGuestsRepository` | Bulk `episode_slug → Person[]` für `PodcastEpisode.actor` |

## Architecture

```text
Classes/
├── Domain/          # Annotation models, relationship types, contracts, repository interfaces
├── Application/     # Context resolution, relationship services, PersonSchemaBuilder
├── Infrastructure/  # Repositories, Schema.org generators, JSON-LD middleware
└── Presentation/    # Backend module controllers
```

DDD-Layer-Regeln werden via deptrac in CI enforced.

### Database Tables

| Table | Purpose |
|-------|---------|
| `tx_structuredcontent_relation` | Typed content relationships (replaces, belongs_to, related_to, explains) |
| `tx_structuredcontent_about_entry` (v2.2+) | IRRE-Records für per-Page `about`-Subjects: `schema_type` (Thing/SoftwareApplication/Person/Place/…), `name`, `same_as` (URL) |
| `tx_dpnglossary_domain_model_term` (v2.4+, optional) | Aus `featdd/dpn-glossary` — wird vom GlossaryTermService gelesen wenn die Extension installiert ist. Felder: `name`, `url_segment` (Slug), `tooltiptext`, `meta_description`, `seo_title`, plus IRRE auf `…_description` (Schema.org `description`) und `…_synonym` (Schema.org `alternateName`). |
| `pages` (extended) | `ai_context_*` + Schema-Felder: `tx_structuredcontent_schema_type`, `tx_structuredcontent_service_type/category`, `tx_structuredcontent_code_repository`, `tx_structuredcontent_programming_language/software_version/software_license/operating_system`, `tx_structuredcontent_author_records` (group → moselwal_person, max 10), `tx_structuredcontent_same_as` (Textarea, eine URL/Zeile), `tx_structuredcontent_about` (IRRE → about_entry) |
| `tt_content` (extended) | `ai_context_*` element-level metadata |
| `sys_category` (extended) | `slug` field (auto from title) für Filter-URLs + `articleSection`-Mapping |

### Relationship Type Mappings (Schema.org)

- `replaces` → `isBasedOn`
- `belongs_to` → `isPartOf`
- `related_to` → `relatedLink`
- `explains` → `about`

### Cross-Page `@id` References

Stabile `@id`s erlauben Crawlern Entity-Resolution über Page-Grenzen hinweg. Konventionen pro Domain:

| Entity | `@id` | Quelle |
|---|---|---|
| Organization | `<siteBase>#organization` | OrganizationSchemaGenerator (Site-weit, jede Page) |
| WebSite | `<siteBase>#website` | WebSiteSchemaGenerator (Site-Root + Sub-Pages via `isPartOf`) |
| Person | `<siteBase>/autor/<slug>#person` | PersonPageSchemaGenerator (Author-Detail-Page) — Article/Podcast referenzieren via `author.@id` / `actor.@id` |
| DefinedTermSet | `<siteBase>/glossar#termset` | DefinedTermSetSchemaGenerator (Glossar-Listing-Page) — DefinedTerm referenziert via `inDefinedTermSet.@id` |
| DefinedTerm | `<siteBase>/glossar/<slug>#term` | DefinedTermSchemaGenerator (Glossar-Detail-Page) — WebPage/Article/Service referenzieren via `about[].@id` wenn der dpn-glossary-Auto-Parser den Term auf der Page verlinkt hat |
| WebPage | `<siteBase><slug>` | WebPageSchemaGenerator (kanonische Page-URL als `@id`) |

`Article.publisher`, `Service.provider`, `WebPage.about`, `PodcastEpisode.publisher` etc. setzen `{@id: <siteBase>#organization}` — die Organization wird einmal pro Page als volle Entity emittiert (jede Page enthält den Organization-Knoten, ~500 Bytes Bloat akzeptiert für Cross-Ref-Stabilität).

### Site Settings (Schema.org)

Erweitern Pro-Site das `structuredContent.schema.*`-Namespace. Konsumiert vom OrganizationSchemaGenerator + PageLevelExtraSchemaTrait:

| Setting | Default | Purpose |
|---|---|---|
| `legalName` | `''` | Organization.legalName (z.B. "Moselwal Digitalagentur GmbH") |
| `organizationDescription` | `''` | Stable Org-Description (NICHT die Article-Description) |
| `organizationTypes` | `''` | CSV — ersetzt `@type: Organization` mit Multi-Type (z.B. `'Corporation, ProfessionalService'`) |
| `email` (v2.1+) | `''` | Org-Email (Fallback `footerEmail`) |
| `phone` | `''` | Org-Telefon im E.164-Format |
| `foundingDate` (v2.1+) | `''` | Gründungsjahr (YYYY) oder ISO-Datum (Fallback `footerFoundingDate`) |
| `streetAddress` | `''` | PostalAddress.streetAddress |
| `postalCode` | `''` | PostalAddress.postalCode |
| `addressLocality` (v2.1+) | `''` | City/Locality (Fallback `footerLocation` vor erstem Komma) |
| `addressRegion` | `''` | State/Region (z.B. NRW) |
| `addressCountry` (v2.1+) | `'DE'` | ISO-3166-1 alpha-2 Country Code |
| `vatID` | `''` | USt-Identnummer |
| `areaServed` | `''` | CSV ISO-Codes — werden zu Country/AdministrativeArea-Objekten expandiert (EU als AdministrativeArea) |
| `openingHours` | `''` | Schema.org-Format (z.B. `'Mo-Fr 09:00-18:00'`) |
| `contactPointTypes` (v2.1+) | `'sales, technical support'` | CSV — ein ContactPoint pro Eintrag, alle teilen email/phone/areaServed |
| `knowsAbout` (v2.1+) | `''` | CSV Wissens-Topics |
| `sameAs` (v2.1+) | `''` | CSV zusätzlicher sameAs-URLs (Reddit, x.com, Bsky). Eigene Site wird host-basiert gefiltert |
| `serviceTypes` | `''` | CSV → `hasOfferCatalog.itemListElement[Offer.itemOffered.Service]` |
| `priceRange` | `''` | Preis-Indikator (`€€€`) für ProfessionalService |
| `searchActionUrl` | `''` | URL-Pattern für WebSite SearchAction (leer = disabled) |
| `imageLicenseUrl/AcquireLicensePage/CopyrightNotice` | `''` | Hero-Image-Lizenz-Markup (Google Image-Metadata) |

Footer-Settings (`footerLinkedIn/footerGitHub/footerInstagram/footerTwitter/footerFacebook/footerBluesky`) werden zusätzlich in `sameAs` eingesammelt und mit dem schema.sameAs-CSV dedupliziert. `footerWebsite` wird **NIE** in sameAs aufgenommen (eigene Site gehört zu `url`).

### CLI Commands

```bash
vendor/bin/typo3 structured-content:missing-context     # Pages ohne AI-Context
vendor/bin/typo3 structured-content:export-relations    # Export als JSON/CSV
vendor/bin/typo3 structured-content:validate-channels   # Validate Channel-Configs
```

## Development

```bash
composer install
composer test                    # Unit + Functional tests
composer phpstan                 # Static analysis (level 8)
composer deptrac                 # DDD-Layer enforcement
vendor/bin/php-cs-fixer fix      # Code style (PER-CS3x0)
```

### Conventions

- All classes `final` or `final readonly`
- Named arguments everywhere
- Namespace: `Koh\StructuredContent\`
- Domain layer: immutable value objects, no TYPO3/PSR/Doctrine imports
- `empty()` is forbidden — use strict comparisons (`=== ''`, `=== []`, `=== null`)
- `declare(strict_types=1)` in every PHP file
- DB queries: `createNamedParameter()` with explicit `ParameterType`
- Tests mock interfaces, not final classes

## Dependencies

| Package | Type | Purpose |
|---------|------|---------|
| `koh/content-provenance` | Optional | Content integrity verification |
| `koh/business-agent` | Optional | AI agent content access |
| `featdd/dpn-glossary` (v2.4+) | Optional | Conditional auf `isLoaded('dpn_glossary')` — aktiviert DefinedTerm/DefinedTermSet-Generators + Auto-Parser-Link-Scan im JsonLdInjectorMiddleware (Pages die einen Glossar-Term verlinken bekommen die Term-`@id` als `about`-Subject) |
| `koh/dev` | Dev | Shared QA tooling |

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.
