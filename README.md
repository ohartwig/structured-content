# Structured Content — TYPO3 Extension

[![TYPO3 14](https://img.shields.io/badge/TYPO3-14.x-orange.svg)](https://get.typo3.org/)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-blue.svg)](https://php.net/)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Schema.org JSON-LD generation, AI-context annotations, and content-relationship management for TYPO3 14. Owns the full Schema.org generator stack plus audience/tone/channel inheritance and typed cross-content relationships.

## Features

- **Schema.org Generator Stack** — Priority-ordered generators emit Article, WebPage, FAQ, Organization (Corporation + ProfessionalService), Service, Product, Person, ProfilePage, ItemList, DefinedTerm/DefinedTermSet, Review, BreadcrumbList, SoftwareApplication, PodcastSeries, PodcastEpisode JSON-LD
- **Corporation / ProfessionalService Organization** (v2.1+) — multi-type Organization with `contactPoint[]` (per-site CSV `contactPointTypes`), structured `areaServed` (Country/AdministrativeArea mapping), `knowsAbout`, `sameAs` (the own site filtered out by host), `foundingDate`, `image` + `logo`. Seven new site settings: `addressLocality`, `addressCountry`, `email`, `foundingDate`, `sameAs`, `knowsAbout`, `contactPointTypes`.
- **Per-page schema fields** (v2.2+) — TCA fields `tx_structuredcontent_same_as` (textarea, one URL per line), `tx_structuredcontent_code_repository` (input) and `tx_structuredcontent_about` (IRRE inline on `tx_structuredcontent_about_entry`). The generator trait `PageLevelExtraSchemaTrait` passes the fields to WebPage/Article/Service. sameAs filters out the own site by host; about replaces the default `#organization` reference when subjects are maintained.
- **Standalone Person + cross-references** (v2.3+) — `PersonPageSchemaGenerator` emits the full Person entity on `/autor/<slug>` with a stable `@id = <siteBase>/autor/<slug>#person`. The Article, SoftwareApplication and PodcastEpisode generators reference authors via `{@id}` instead of duplicating Person objects inline. The inline fallback stays for authors without a slug (external guest posts). `PersonSchemaBuilder` fills `url`, `image` (FAL), `worksFor.@id`, `sameAs`, `knowsAbout`, `knowsLanguage`, `jobTitle`, `alumniOf`.
- **ItemList for hub pages** (v2.3+) — `ItemListSchemaGenerator` recognises the `moselwal_cards`/`smallcards`/`smallcardscarousel`/`teaser` CTypes and emits one `ItemList` per block with `name` (block header), `description` (subheader) and `itemListElement` (from `moselwal_card` records with `position`/`name`/`url`). Several blocks on one page → several ItemLists in `@graph`.
- **Glossary / DefinedTerm + DefinedTermSet** (v2.4+) — conditional integration with `featdd/dpn-glossary` (active only when that extension is loaded, guarded by `GlossaryTermService::isAvailable()`). The listing page (`/glossar`) emits a `DefinedTermSet` with `@id = <siteBase>/glossar#termset` and `hasDefinedTerm[]` references; detail pages (`/glossar/<slug>`) emit a `DefinedTerm` with `@id = <siteBase>/glossar/<slug>#term`, `name`, `description` (from `tx_dpnglossary_domain_model_description`, falling back to `meta_description`/`tooltiptext`), `alternateName` (from `tx_dpnglossary_domain_model_synonym`) and `inDefinedTermSet.@id`. In addition, `JsonLdInjectorMiddleware` scans the frontend response body for dpn-glossary auto-parser links (`<a class="dpnglossary link" href="#slug">`) and appends the referenced terms to `about` as `{@id: ...#term}` subjects — in addition to manual `tx_structuredcontent_about_entry` entries.
- **AI Context Inheritance** — Audience, tone and channel metadata cascade through the page tree.
- **Content Relationships** — Typed, directional relationships with confidence scoring.
- **Category Mapping** — `sys_category` slug field + `articleSection` property in Article JSON-LD + RTE description (for category hero blocks).
- **Channel-Aware Fields** — Restrict content fields per delivery channel (Website, AI Agent, Voice, Social).
- **AI Content Disclosure** — for AI-tagged hero images the Article schema contains `image: {creator: {SoftwareApplication, name}, creditText: "AI-generated"}` (EU AI Act Art. 50). Maintained through the TCA fields of `koh/content-provenance`.
- **Reading Time** — the Article schema contains `wordCount` + `timeRequired` (PT*M) — byte-identical to the frontend reading-time badge (200 WPM).
- **Language fallback in schema generators** — translation-aware: default-language records are loaded and their translations overlaid. Schema output also works under `fallbackType: fallback` without gaps.
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

`Classes/Infrastructure/SchemaOrg/` — all implement `SchemaGeneratorInterface`,
are registered via the service tag `structured_content.schema_generator` and
rendered by `priority` (higher = earlier in the JSON-LD block).

| Generator | Priority | `@type` | Trigger |
|---|---|---|---|
| `BreadcrumbListSchemaGenerator` | 90 | `BreadcrumbList` | Every page with a rootline |
| `DefinedTermSchemaGenerator` | 85 | `DefinedTerm` | `/glossar/{slug}` — dpn-glossary detail page (conditional on `isLoaded('dpn_glossary')`); output contains `alternateName` from synonyms |
| `PersonPageSchemaGenerator` | 80 | `Person` (standalone) | `/autor/{slug}` — emits the full Person entity with `@id` for cross-page references |
| `DefinedTermSetSchemaGenerator` | 78 | `DefinedTermSet` | Glossary listing page (`uid == dpnGlossary.settings.detailPage`); `hasDefinedTerm[]` as `@id` references |
| `ProfilePageSchemaGenerator` | 75 | `ProfilePage` + `mainEntity.@id` + `hasPart` | `/autor/{slug}` — wrapper page with an `@id` reference to the Person (not inline) |
| `ReviewSchemaGenerator` | 68 | `Review` | Pages with the testimonials content block |
| `PodcastEpisodeSchemaGenerator` | 65 | `PodcastEpisode` | Podcast plugin detail view (with `actor[]` as `@id` references for hosts and guests) |
| `PodcastSeriesSchemaGenerator` | 60 | `PodcastSeries` | Podcast plugin list view |
| `SoftwareApplicationSchemaGenerator` | 55 | `SoftwareApplication` | Pages with `tx_structuredcontent_code_repository` set |
| `ArticleSchemaGenerator` | 50 | `Article` / `BlogPosting` / … | Article pages — `author` as an `@id` reference to `/autor/<slug>#person` |
| `FaqSchemaGenerator` | 45 | `FAQPage` | Pages with the FAQ content block |
| `HowToSchemaGenerator` | 45 | `HowTo` | Pages with the steps content block |
| `WebPageSchemaGenerator` | 40 | `WebPage` | Fallback for all pages — reads the per-page `same_as`/`code_repository`/`about` |
| `ItemListSchemaGenerator` | 35 | `ItemList` | Pages with a card content block (`moselwal_cards`/`smallcards`/`smallcardscarousel`/`teaser`) and a non-empty header |
| `WebSiteSchemaGenerator` | 30 | `WebSite` | Site root + sub-sites |
| `OrganizationSchemaGenerator` | 25 | `Organization` / `[Corporation,ProfessionalService]` | Site-wide from settings; `@type` configurable via the `organizationTypes` setting (multi-type replaces the default `Organization`) |
| `ServiceSchemaGenerator` | 20 | `Service` | Pages with the service content block (skipped when SoftwareApplication owns the page) |
| `ProfessionalServiceSchemaGenerator` | 20 | `ProfessionalService` | Service pages with a provider organization |
| `PackagesGridEnterpriseServiceSchemaGenerator` | 18 | `Service` variants | Packages grid content block |
| `PackagesGridProductSchemaGenerator` | 18 | `Product` | Packages grid content block |
| `ProductSchemaGenerator` | 15 | `Product` | Pages with the product content block |
| `PersonSchemaGenerator` | 10 | `Person` | Team content block — lists several persons as an ItemList |
| `AutoDetectSchemaGenerator` | 5 | dynamic | TCA-driven mapping |

### Builders + traits

| Class | Purpose |
|---|---|
| `Application/Service/PersonSchemaBuilder` | `buildFromRow()` — emits a Person with `@id = <siteBase>/autor/<slug>#person`, `url`, `image` (FAL), `worksFor[]`, `sameAs[]` (in the `Label\|URL` pipe format), `knowsAbout`, `knowsLanguage`. `buildPersonReference()` returns a minimal `{@type:Person, @id}` for cross-references. The constant `AUTHOR_PATH = '/autor'` is the single source of truth. |
| `Application/Service/GlossaryTermService` (v2.4+) | Conditional integration with `featdd/dpn-glossary`. `isAvailable()` is the guard (no database access when the extension is not loaded). `findTermBySlug()`/`findAllTerms()`/`loadFirstDescription()`/`loadSynonyms()`/`resolveGlossaryBasePath()` (from `dpnGlossary.settings.detailPage` → page slug, fallback `/glossar`)/`isGlossaryListingPage()`. Singleton with a per-request cache. |
| `Infrastructure/SchemaOrg/PageLevelExtraSchemaTrait` (v2.2+) | `applyPageLevelExtras($data, $pageData)` — adds `codeRepository`, `sameAs`, `about` to WebPage/Article/Service. sameAs filters out the own site by host; about merges manual `_aboutEntries` with glossary references from `_glossaryRefs` (v2.4+, additive). |
| `Infrastructure/SchemaOrg/PersonRoutingArgumentTrait` | Resolves the `person` slug from the `_routingArguments` of the person plugin detail view. Used by PersonPage + ProfilePage. |
| `Infrastructure/SchemaOrg/PodcastFeedCacheReaderTrait` | Reads cached feed items for podcast schemas without a re-fetch. Cache key `rssfeed_<sha256(feedUrl)>` (in sync with `PodcastFeedService::cacheIdentifierFor()` — previously md5, fixed in v2.0.3). |
| `Infrastructure/SchemaOrg/SanitizesSchemaTrait` | Strip filter for empty or non-JSON-conformant values |
| `Infrastructure/SchemaOrg/ContextAndRelationshipsTrait` | Appends AI context + relationships to the schema output |

## Repositories

`Classes/Domain/Repository/`:

| Repository | Purpose |
|---|---|
| `AuthoredPagesRepository` | `findByPersonUid` — pages that link the person via `tx_structuredcontent_author_records` (for `Person.hasPart`) |
| `CategoryRepository` | `findByPageUid` — `sys_category` records via `sys_category_record_mm` (for `Article.articleSection`) |
| `EpisodeGuestsRepository` | Bulk `episode_slug → Person[]` for `PodcastEpisode.actor` |

## Architecture

```text
Classes/
├── Domain/          # Annotation models, relationship types, contracts, repository interfaces
├── Application/     # Context resolution, relationship services, PersonSchemaBuilder
├── Infrastructure/  # Repositories, Schema.org generators, JSON-LD middleware
└── Presentation/    # Backend module controllers
```

DDD layer rules are enforced by deptrac in CI.

### JSON-LD Cache

`JsonLdInjectorMiddleware` keeps the generated JSON-LD in the `structured_content_schema` cache, keyed on site, language, page and route arguments and tagged `pageId_<uid>`. The cache is only read and written for plain public requests: live workspace, no frontend preview, no hidden, scheduled or deleted records shown, and no logged-in frontend user or frontend groups beyond the anonymous ones. Workspace and hidden-content previews and logged-in frontend users get JSON-LD built for their request alone, so it never reaches the shared entry.

### Database Tables

| Table | Purpose |
|-------|---------|
| `tx_structuredcontent_relation` | Typed content relationships (replaces, belongs_to, related_to, explains) |
| `tx_structuredcontent_about_entry` (v2.2+) | IRRE records for per-page `about` subjects: `schema_type` (Thing/SoftwareApplication/Person/Place/…), `name`, `same_as` (URL) |
| `tx_dpnglossary_domain_model_term` (v2.4+, optional) | From `featdd/dpn-glossary` — read by GlossaryTermService when the extension is installed. Fields: `name`, `url_segment` (Slug), `tooltiptext`, `meta_description`, `seo_title`, plus IRRE on `…_description` (Schema.org `description`) and `…_synonym` (Schema.org `alternateName`). |
| `pages` (extended) | `ai_context_*` + schema fields: `tx_structuredcontent_schema_type`, `tx_structuredcontent_service_type/category`, `tx_structuredcontent_code_repository`, `tx_structuredcontent_programming_language/software_version/software_license/operating_system`, `tx_structuredcontent_author_records` (group → moselwal_person, max 10), `tx_structuredcontent_same_as` (textarea, one URL per line), `tx_structuredcontent_about` (IRRE → about_entry) |
| `tt_content` (extended) | `ai_context_*` element-level metadata |
| `sys_category` (extended) | `slug` field (auto from title) for filter URLs + `articleSection` mapping |

### Relationship Type Mappings (Schema.org)

- `replaces` → `isBasedOn`
- `belongs_to` → `isPartOf`
- `related_to` → `relatedLink`
- `explains` → `about`

### Cross-Page `@id` References

Stable `@id`s let crawlers resolve entities across page boundaries. Conventions per entity:

| Entity | `@id` | Source |
|---|---|---|
| Organization | `<siteBase>#organization` | OrganizationSchemaGenerator (site-wide, every page) |
| WebSite | `<siteBase>#website` | WebSiteSchemaGenerator (site root + sub-pages via `isPartOf`) |
| Person | `<siteBase>/autor/<slug>#person` | PersonPageSchemaGenerator (author detail page) — Article/Podcast reference it via `author.@id` / `actor.@id` |
| DefinedTermSet | `<siteBase>/glossar#termset` | DefinedTermSetSchemaGenerator (glossary listing page) — DefinedTerm references it via `inDefinedTermSet.@id` |
| DefinedTerm | `<siteBase>/glossar/<slug>#term` | DefinedTermSchemaGenerator (glossary detail page) — WebPage/Article/Service reference it via `about[].@id` when the dpn-glossary auto-parser linked the term on the page |
| WebPage | `<siteBase><slug>` | WebPageSchemaGenerator (canonical page URL as `@id`) |

`Article.publisher`, `Service.provider`, `WebPage.about`, `PodcastEpisode.publisher` etc. set `{@id: <siteBase>#organization}` — the Organization is emitted once per page as a full entity (every page contains the Organization node; ~500 bytes of bloat accepted for stable cross-references).

### Site Settings (Schema.org)

Extend the `structuredContent.schema.*` namespace per site. Consumed by OrganizationSchemaGenerator + PageLevelExtraSchemaTrait:

| Setting | Default | Purpose |
|---|---|---|
| `legalName` | `''` | Organization.legalName (e.g. "Moselwal Digitalagentur GmbH") |
| `organizationDescription` | `''` | Stable organization description (NOT the article description) |
| `organizationTypes` | `''` | CSV — replaces `@type: Organization` with a multi-type (e.g. `'Corporation, ProfessionalService'`) |
| `email` (v2.1+) | `''` | Organization email (fallback `footerEmail`) |
| `phone` | `''` | Organization phone in E.164 format |
| `foundingDate` (v2.1+) | `''` | Founding year (YYYY) or ISO date (fallback `footerFoundingDate`) |
| `streetAddress` | `''` | PostalAddress.streetAddress |
| `postalCode` | `''` | PostalAddress.postalCode |
| `addressLocality` (v2.1+) | `''` | City/locality (fallback: `footerLocation` before the first comma) |
| `addressRegion` | `''` | State/region (e.g. NRW) |
| `addressCountry` (v2.1+) | `'DE'` | ISO 3166-1 alpha-2 country code |
| `vatID` | `''` | VAT ID |
| `areaServed` | `''` | CSV of ISO codes — expanded to Country/AdministrativeArea objects (EU as AdministrativeArea) |
| `openingHours` | `''` | Schema.org format (e.g. `'Mo-Fr 09:00-18:00'`) |
| `contactPointTypes` (v2.1+) | `'sales, technical support'` | CSV — one ContactPoint per entry, all sharing email/phone/areaServed |
| `knowsAbout` (v2.1+) | `''` | CSV of knowledge topics |
| `sameAs` (v2.1+) | `''` | CSV of additional sameAs URLs (Reddit, x.com, Bluesky). The own site is filtered out by host |
| `serviceTypes` | `''` | CSV → `hasOfferCatalog.itemListElement[Offer.itemOffered.Service]` |
| `priceRange` | `''` | Price indicator (`€€€`) for ProfessionalService |
| `searchActionUrl` | `''` | URL pattern for the WebSite SearchAction (empty = disabled) |
| `imageLicenseUrl/AcquireLicensePage/CopyrightNotice` | `''` | Hero image licence markup (Google image metadata) |

Footer-Settings (`footerLinkedIn/footerGitHub/footerInstagram/footerTwitter/footerFacebook/footerBluesky`) are also collected into `sameAs` and deduplicated against the schema.sameAs CSV. `footerWebsite` is **NEVER** added to sameAs (the own site belongs in `url`).

### CLI Commands

```bash
vendor/bin/typo3 structured-content:missing-context     # Pages without AI context
vendor/bin/typo3 structured-content:export-relations    # Export as JSON/CSV
vendor/bin/typo3 structured-content:validate-channels   # Validate channel configs
```

## Development

```bash
composer install
composer test                    # Unit + Functional tests
composer phpstan                 # Static analysis (level 8)
composer deptrac                 # DDD layer enforcement
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
| `featdd/dpn-glossary` (v2.4+) | Optional | Conditional on `isLoaded('dpn_glossary')` — enables the DefinedTerm/DefinedTermSet generators + the auto-parser link scan in JsonLdInjectorMiddleware (pages that link a glossary term get the term's `@id` as an `about` subject) |
| `koh/dev` | Dev | Shared QA tooling |

## License

GPL-2.0-or-later — see [LICENSE](LICENSE) for details.
