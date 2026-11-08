<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Presentation\Middleware;

use Doctrine\DBAL\Result;
use Koh\StructuredContent\Application\Service\GlossaryTermService;
use Koh\StructuredContent\Application\Service\SchemaOrgService;
use Psr\EventDispatcher\EventDispatcherInterface;
use Koh\StructuredContent\Domain\Contract\FreshnessEnricherInterface;
use Koh\StructuredContent\Domain\Contract\GlossaryTermRepositoryInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Contract\SchemaMappingConfigurableInterface;
use Koh\StructuredContent\Domain\Model\FreshnessSignal;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use Koh\StructuredContent\Presentation\Middleware\JsonLdInjectorMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\AspectInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Aspect\PreviewAspect;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Der Schritt, der aus erzeugten Dokumenten das macht, was im Seitenkopf
 * landet.
 *
 * Er sitzt vor jeder Auslieferung dieser Site, und das ist die erste
 * Eigenschaft, die zaehlt: alles, was keine HTML-Seite ist, geht unveraendert
 * durch. Ein Bild, ein PDF, eine JSON-Antwort — dort ein `<script>` zu
 * suchen und einzusetzen, beschaedigt die Datei.
 *
 * Die zweite ist das Abschalten. `structuredContent.enabled` ist die
 * Sicherung, an der ein Betreiber dreht, wenn etwas Falsches ausgeliefert
 * wird; sie muss vor allem anderen greifen, sonst laeuft die Erzeugung
 * trotzdem und der Schalter beruhigt nur.
 *
 * Und die dritte: ein Dokument ohne Inhalt wird nicht ausgeliefert.
 * `<script>{"@type":"Product"}</script>` ist schlimmer als gar nichts — es
 * sagt einem Crawler, dass hier ein Produkt ist, und dann nichts weiter.
 */
final class JsonLdInjectorMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cacheEntries = [];

    /** @var array<string, list<string>> */
    private array $cacheTags = [];

    /** @var list<JsonLdDocument> */
    private array $documents = [];

    private ?\Throwable $generatorFailure = null;

    /** @var array<string, mixed> what the generators were given */
    private array $generatedFrom = [];

    /** @var list<string>|null term slugs the glossary knows; null: extension not installed */
    private ?array $glossaryTerms = null;

    /** @var array{findPageSlug: int, findTermBySlug: int, findTermsBySlugs: int} glossary repository calls, i.e. queries */
    private array $glossaryQueries = ['findPageSlug' => 0, 'findTermBySlug' => 0, 'findTermsBySlugs' => 0];

    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->context = new Context();

        // Die Middleware baut Restriction-Container ueber `new`, und deren
        // Konstruktor zieht die TCA-Schema-Fabrik. Sie ist hier vorab
        // gedoubelt — die Warteschlange gibt jeweils eine heraus.
        for ($i = 0; $i < 256; ++$i) {
            GeneralUtility::addInstance(
                TcaSchemaFactory::class,
                $this->createStub(TcaSchemaFactory::class),
            );
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();

        parent::tearDown();
    }

    private function middleware(): JsonLdInjectorMiddleware
    {
        $generator = $this->createStub(SchemaGeneratorInterface::class);
        $generator->method('canHandle')->willReturn(true);
        $generator->method('getPriority')->willReturn(100);
        $generator->method('generate')->willReturnCallback(
            function (array $pageData): JsonLdDocument {
                $this->generatedFrom = $pageData;

                return null !== $this->generatorFailure
                    ? throw $this->generatorFailure
                    : ($this->documents[0] ?? new JsonLdDocument('WebPage', []));
            },
        );

        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cacheEntries[$key] ?? false);
        $cache->method('set')->willReturnCallback(function (string $key, mixed $value, array $tags = []): void {
            $this->cacheEntries[$key] = $value;
            $this->cacheTags[$key] = $tags;
        });

        // `FreshnessSignal` ist final, also kann ein gedoubelter Anreicherer
        // keinen Rueckgabewert erfinden. Er bekommt ein echtes Signal.
        $freshness = $this->createStub(FreshnessEnricherInterface::class);
        $freshness->method('enrich')->willReturn(new FreshnessSignal(
            new \DateTimeImmutable('@1785200000'),
            new \DateTimeImmutable('@1785300000'),
        ));

        return new JsonLdInjectorMiddleware(
            new SchemaOrgService([$generator]),
            $freshness,
            $this->createStub(EventDispatcherInterface::class),
            $cache,
            $this->connectionPool(),
            $this->createStub(SchemaMappingConfigurableInterface::class),
            // Der Glossardienst ist final; er wird echt gebaut, auf einem
            // gedoubelten Repository. Ohne installierte dpn_glossary-Extension
            // meldet er sich als nicht verfuegbar, und das ist der Normalfall
            // hier; die Glossar-Tests setzen $glossaryTerms.
            new GlossaryTermService($this->glossaryRepository()),
            $this->context,
        );
    }

    private function glossaryRepository(): GlossaryTermRepositoryInterface
    {
        $repository = $this->createStub(GlossaryTermRepositoryInterface::class);
        $repository->method('isAvailable')->willReturnCallback(fn (): bool => null !== $this->glossaryTerms);
        $repository->method('findPageSlug')->willReturnCallback(function (): string {
            ++$this->glossaryQueries['findPageSlug'];

            return '/glossar';
        });
        $repository->method('findTermBySlug')->willReturnCallback(
            function (string $slug): ?array {
                ++$this->glossaryQueries['findTermBySlug'];

                return in_array($slug, $this->glossaryTerms ?? [], true)
                    ? ['uid' => 1, 'name' => $slug, 'url_segment' => $slug]
                    : null;
            },
        );
        $repository->method('findTermsBySlugs')->willReturnCallback(
            function (array $slugs): array {
                ++$this->glossaryQueries['findTermsBySlugs'];
                $terms = [];
                foreach ($slugs as $slug) {
                    if (in_array($slug, $this->glossaryTerms ?? [], true)) {
                        $terms[$slug] = ['uid' => 1, 'name' => $slug, 'url_segment' => $slug];
                    }
                }

                return $terms;
            },
        );

        return $repository;
    }

    private function connectionPool(): ConnectionPool
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturn([]);
        $result->method('fetchAssociative')->willReturn(false);

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'setMaxResults'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $restrictions = $this->createStub(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();
        $restrictions->method('add')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('expr')->willReturn($this->createStub(ExpressionBuilder::class));
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return $pool;
    }

    private function site(bool $enabled = true, bool $schemaEnabled = true, int $glossaryPage = 0): Site
    {
        return new Site('example', 1, [
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/'],
                ...(0 !== $glossaryPage ? [['languageId' => 1, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/en/']] : []),
            ],
            'settings' => [
                'structuredContent' => [
                    'enabled' => $enabled,
                    'schema' => ['enabled' => $schemaEnabled],
                ],
                ...(0 !== $glossaryPage ? ['dpn-glossary' => ['glossaryPage' => $glossaryPage]] : []),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $pageRecord
     */
    private function request(
        ?Site $site = null,
        int $pageUid = 42,
        array $pageRecord = ['uid' => 42, 'title' => 'About us'],
        bool $withPageInformation = true,
        bool $withRouting = true,
    ): ServerRequestInterface {
        $request = new ServerRequest('https://example.com/about', 'GET');
        $site ??= $this->site();

        $request = $request->withAttribute('site', $site);
        $request = $request->withAttribute('language', $site->getDefaultLanguage());

        if ($withRouting) {
            $request = $request->withAttribute('routing', new PageArguments($pageUid, '0', []));
        }

        if ($withPageInformation) {
            $pageInformation = new PageInformation();
            $pageInformation->setId($pageUid);
            $pageInformation->setPageRecord($pageRecord);
            $pageInformation->setRootLine([['uid' => 1, 'title' => 'Root']]);
            $request = $request->withAttribute('frontend.page.information', $pageInformation);
        }

        return $request;
    }

    private function handler(string $body = '<html><head></head><body>Hi</body></html>', string $contentType = 'text/html; charset=utf-8'): RequestHandlerInterface
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static fn (): ResponseInterface => (new HtmlResponse($body))->withHeader('Content-Type', $contentType),
        );

        return $handler;
    }

    /**
     * Die erste Eigenschaft: was keine HTML-Seite ist, geht unveraendert
     * durch. In einer Bilddatei nach `</head>` zu suchen und dort etwas
     * einzusetzen, beschaedigt sie.
     */
    #[Test]
    public function anythingThatIsNotHtmlPassesThroughUntouched(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        foreach (['application/json', 'image/png', 'application/pdf', ''] as $contentType) {
            $response = $this->middleware()->process($this->request(), $this->handler('binary', $contentType));

            self::assertSame('binary', (string) $response->getBody(), $contentType);
        }
    }

    /**
     * Die zweite: der Schalter greift vor allem anderen. Er ist das, woran
     * ein Betreiber dreht, wenn etwas Falsches ausgeliefert wird.
     */
    #[Test]
    public function theSwitchSuppressesEverything(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        foreach ([
            'the extension is off' => $this->site(enabled: false),
            'the schema output is off' => $this->site(schemaEnabled: false),
        ] as $why => $site) {
            $response = $this->middleware()->process($this->request($site), $this->handler());

            self::assertStringNotContainsString('application/ld+json', (string) $response->getBody(), $why);
        }
    }

    /**
     * Ohne Site gibt es keine Einstellungen, also auch keine Entscheidung —
     * die Antwort geht durch. Das ist der Fall bei Anfragen ausserhalb einer
     * Site, etwa im Backend.
     */
    #[Test]
    public function aRequestWithoutASitePassesThrough(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        $request = (new ServerRequest('https://example.com/about', 'GET'));
        $response = $this->middleware()->process($request, $this->handler());

        self::assertStringNotContainsString('application/ld+json', (string) $response->getBody());
    }

    /**
     * Ohne Routing oder Seiteninformation fehlt das, woraus ein Schema
     * gebaut wuerde. Ein Abbruch waere hier ein Fehler auf einer Seite, die
     * sonst funktioniert.
     */
    #[Test]
    public function aRequestWithoutRoutingOrPageInformationPassesThrough(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        foreach ([
            'no routing' => $this->request(withRouting: false),
            'no page information' => $this->request(withPageInformation: false),
            'the page id is zero' => $this->request(pageUid: 0),
        ] as $why => $request) {
            $response = $this->middleware()->process($request, $this->handler());

            self::assertStringNotContainsString('application/ld+json', (string) $response->getBody(), $why);
        }
    }

    /**
     * Der gute Fall: das Dokument landet als JSON-LD-Graph im Kopf der Seite.
     */
    #[Test]
    public function theDocumentIsInjectedIntoTheHead(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        $response = $this->middleware()->process($this->request(), $this->handler());
        $body = (string) $response->getBody();

        self::assertStringContainsString('<script type="application/ld+json">', $body);
        self::assertStringContainsString('"@context": "https://schema.org"', $body);
        self::assertStringContainsString('About us', $body);
        self::assertStringContainsString('</head>', $body, 'the page itself is still intact');
    }

    /**
     * Die dritte Eigenschaft: ein Dokument ohne Inhalt wird nicht
     * ausgeliefert. `{"@type":"Product"}` allein sagt einem Crawler, dass
     * hier ein Produkt ist, und dann nichts — das ist schlechter als
     * Schweigen.
     */
    #[Test]
    public function anEmptyDocumentIsNotEmitted(): void
    {
        $this->documents = [new JsonLdDocument('Product', [])];

        $response = $this->middleware()->process($this->request(), $this->handler());

        self::assertStringNotContainsString('application/ld+json', (string) $response->getBody());
    }

    /**
     * Das Ergebnis wird gemerkt und beim naechsten Aufruf ohne Neubau
     * ausgeliefert. Der Aufbau liest die Seite, ihre Inhaltselemente und
     * mehrere Beziehungstabellen — pro Auslieferung, sonst.
     */
    #[Test]
    public function theResultIsRememberedForTheNextRequest(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];
        $middleware = $this->middleware();

        $middleware->process($this->request(), $this->handler());

        self::assertNotSame([], $this->cacheEntries);

        // Der zweite Aufruf baut nichts mehr: der Generator liefert jetzt
        // etwas anderes, und die Antwort zeigt trotzdem das Gemerkte.
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'Something else entirely'])];

        $body = (string) $middleware->process($this->request(), $this->handler())->getBody();

        self::assertStringContainsString('About us', $body);
        self::assertStringNotContainsString('Something else entirely', $body);
    }

    /**
     * Der Eintrag traegt das Tag der Seite. TYPO3 leert die Cache-Gruppe
     * "pages" ueber pageId_<uid>, sobald etwas auf der Seite gespeichert
     * oder veroeffentlicht wird; ohne Tag bliebe das alte JSON-LD bis zum
     * Ablauf stehen.
     */
    #[Test]
    public function theRememberedResultIsTaggedWithThePage(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        $this->middleware()->process($this->request(pageUid: 42), $this->handler());

        self::assertSame([['pageId_42']], array_values($this->cacheTags));
    }

    /**
     * Der Schluessel traegt Site, Sprache und Seite. Faellt eines davon weg,
     * bekommt eine Sprache die Ausgabe der anderen — und zwar die, die
     * zuerst angefragt wurde.
     */
    #[Test]
    public function theRememberedResultIsScopedToSiteLanguageAndPage(): void
    {
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        $this->middleware()->process($this->request(pageUid: 42), $this->handler());
        $this->middleware()->process($this->request(pageUid: 43, pageRecord: ['uid' => 43]), $this->handler());

        self::assertCount(2, $this->cacheEntries, 'two pages, two entries');

        foreach (array_keys($this->cacheEntries) as $key) {
            self::assertStringStartsWith('jsonld_example_0_', $key);
        }
    }

    /**
     * A generator that throws must not break the page. The page is delivered
     * without that node, and the failure is logged so it does not go unseen.
     */
    #[Test]
    public function aFailingGeneratorIsLoggedAndThePageStillRenders(): void
    {
        $this->generatorFailure = new \RuntimeException("Unknown column 'description'");
        $logger = new class () extends \Psr\Log\AbstractLogger {
            /** @var list<string> */
            public array $errors = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ('error' === $level) {
                    $this->errors[] = (string) $message;
                }
            }
        };
        $middleware = $this->middleware();
        $middleware->setLogger($logger);

        $response = $middleware->process($this->request(), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Hi', (string) $response->getBody());
        self::assertCount(1, $logger->errors);
        self::assertStringContainsString('failed and was skipped', $logger->errors[0]);
    }

    /**
     * Die Begriffe, auf die der Glossar-Parser im Inhalt verlinkt, gehen als
     * `_glossaryRefs` an die Generatoren. Der Parser schreibt `href` vor
     * `class`, und je nach Konfiguration zeigt der Link auf `#<slug>` oder auf
     * die Detailseite des Begriffs. Die alte Erkennung erwartete `class` vor
     * `href` und nur `#<slug>` und fand deshalb keinen einzigen Link.
     */
    #[Test]
    public function glossaryTermLinksBecomeReferencesInBothMarkups(): void
    {
        $this->glossaryTerms = ['cve', 'sbom', 'devsecops'];
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

        foreach ([
            'fragment links' => '<p><a href="#cve" title="Common Vulnerabilities" class="dpnglossary link">CVE</a> und '
                . '<a href="#sbom" title="Software Bill of Materials" class="dpnglossary link">SBOM</a></p>',
            'detail page links' => '<p><a href="/glossar/cve" title="Common Vulnerabilities" class="dpnglossary link">CVE</a> und '
                . '<a href="https://example.com/glossar/sbom" title="Software Bill of Materials" class="dpnglossary link">SBOM</a></p>',
        ] as $markup => $content) {
            $this->cacheEntries = [];
            $this->generatedFrom = [];

            $this->middleware()->process(
                $this->request(),
                $this->handler('<html><head></head><body>' . $content . '</body></html>'),
            );

            self::assertSame(['cve', 'sbom'], $this->generatedFrom['_glossaryRefs'] ?? null, $markup);
        }
    }

    /**
     * Jeder Begriff zaehlt einmal, egal wie oft er verlinkt ist; und ein Link,
     * dessen Slug zu keinem Begriff gehoert, ist keine Referenz.
     */
    #[Test]
    public function glossaryReferencesAreDeduplicatedAndMustExist(): void
    {
        $this->glossaryTerms = ['cve'];
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];
        $content = '<a class="dpnglossary link" href="#cve">CVE</a>'
            . '<a href="/glossar/cve" class="dpnglossary link">CVE</a>'
            . '<a href="/glossar/unknown" class="dpnglossary link">?</a>';

        $this->middleware()->process(
            $this->request(),
            $this->handler('<html><head></head><body>' . $content . '</body></html>'),
        );

        self::assertSame(['cve'], $this->generatedFrom['_glossaryRefs'] ?? null);
    }

    /**
     * One query for all term links of a page, however many there are. The
     * lookup per link cost a query each — dozens on a glossary-heavy page.
     */
    #[Test]
    public function allTermLinksOfAPageAreLookedUpInOneQuery(): void
    {
        $this->glossaryTerms = ['cve', 'sbom', 'devsecops', 'slsa'];
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];
        $content = '<a href="#cve" class="dpnglossary link">CVE</a>'
            . '<a href="/glossar/sbom" class="dpnglossary link">SBOM</a>'
            . '<a href="/glossar/unknown" class="dpnglossary link">?</a>'
            . '<a href="/glossar/devsecops" class="dpnglossary link">DevSecOps</a>'
            . '<a href="#slsa" class="dpnglossary link">SLSA</a>';

        $this->middleware()->process(
            $this->request(),
            $this->handler('<html><head></head><body>' . $content . '</body></html>'),
        );

        self::assertSame(['cve', 'sbom', 'devsecops', 'slsa'], $this->generatedFrom['_glossaryRefs'] ?? null, 'same references, same order');
        self::assertSame(1, $this->glossaryQueries['findTermsBySlugs']);
        self::assertSame(0, $this->glossaryQueries['findTermBySlug'], 'no lookup per link');
    }

    /**
     * Where the glossary page lives does not depend on the page being
     * rendered. It is resolved once and kept, tagged with the glossary page so
     * that editing that page flushes it; the next page reads it from the cache
     * and gets exactly what the first one built.
     */
    #[Test]
    public function theGlossaryLocationIsResolvedOncePerSiteLanguage(): void
    {
        $this->glossaryTerms = ['cve'];
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];
        $site = $this->site(glossaryPage: 7);
        $html = '<html><head></head><body><a href="/glossar/cve" class="dpnglossary link">CVE</a>'
            . '<a href="/en/glossar/cve" class="dpnglossary link">CVE</a></body></html>';

        $first = (string) $this->middleware()->process($this->request($site), $this->handler($html))->getBody();
        $firstPageData = $this->generatedFrom;

        $locationKeys = array_values(array_filter(
            array_keys($this->cacheEntries),
            static fn (string $key): bool => str_starts_with($key, 'glossary_location_'),
        ));
        self::assertCount(1, $locationKeys);
        self::assertSame(['pageId_7'], $this->cacheTags[$locationKeys[0]], 'flushed with the glossary page');
        self::assertSame(
            ['basePath' => '/glossar', 'pagePaths' => ['/glossar', '/en/glossar']],
            $this->cacheEntries[$locationKeys[0]],
        );
        self::assertSame(1, $this->glossaryQueries['findPageSlug']);

        // Another page of the same site language: its own JSON-LD, the
        // stored glossary location.
        unset($this->cacheEntries['jsonld_example_0_42']);
        $this->generatedFrom = [];
        $second = (string) $this->middleware()->process($this->request($site), $this->handler($html))->getBody();

        self::assertSame(1, $this->glossaryQueries['findPageSlug'], 'the slug is not queried again');
        self::assertSame($first, $second, 'byte-identical output');
        self::assertSame('/glossar', $this->generatedFrom['_glossaryBasePath'] ?? null);
        self::assertSame(['cve'], $this->generatedFrom['_glossaryRefs'] ?? null);
        self::assertSame($firstPageData, $this->generatedFrom);
    }

    /**
     * The key carries the site, the language and the site configuration: a
     * changed language base or route enhancer is a new entry, not the old
     * paths, and the English base path is not the German one.
     */
    #[Test]
    public function theGlossaryLocationKeyFollowsSiteLanguageAndConfiguration(): void
    {
        $this->glossaryTerms = ['cve'];
        $site = $this->site(glossaryPage: 7);
        $otherSite = new Site('other', 1, $site->getConfiguration());
        $english = $this->request($site)->withAttribute('language', $site->getLanguageById(1));

        $this->middleware()->process($this->request($site), $this->handler());
        $this->middleware()->process($english, $this->handler());
        $this->middleware()->process($this->request($otherSite), $this->handler());
        $this->middleware()->process($this->request($this->site(glossaryPage: 8)), $this->handler());
        $this->middleware()->process($this->request($site), $this->handler());
        $this->middleware()->process($english, $this->handler());

        $locationTags = array_filter(
            $this->cacheTags,
            static fn (string $key): bool => str_starts_with($key, 'glossary_location_'),
            ARRAY_FILTER_USE_KEY,
        );
        self::assertSame([['pageId_7'], ['pageId_7'], ['pageId_7'], ['pageId_8']], array_values($locationTags));
        self::assertSame(4, $this->glossaryQueries['findPageSlug'], 'the repeated requests are served from the cache');
    }

    /**
     * What is stored is what is used: term links are recognised under the
     * stored glossary page paths, not under freshly built ones.
     */
    #[Test]
    public function theStoredGlossaryPagePathsAreUsed(): void
    {
        $this->glossaryTerms = ['cve', 'sbom'];
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];
        $site = $this->site(glossaryPage: 7);
        $this->middleware()->process($this->request($site), $this->handler());
        $key = (string) array_key_last(array_filter(
            $this->cacheEntries,
            static fn (string $key): bool => str_starts_with($key, 'glossary_location_'),
            ARRAY_FILTER_USE_KEY,
        ));
        // A base path that would build other page paths than the stored ones.
        $this->cacheEntries = [$key => ['basePath' => '/begriffe', 'pagePaths' => ['/lexikon']]];

        $this->middleware()->process(
            $this->request($site),
            $this->handler('<html><head></head><body><a href="/lexikon/cve" class="dpnglossary link">CVE</a>'
                . '<a href="/glossar/sbom" class="dpnglossary link">SBOM</a></body></html>'),
        );

        self::assertSame('/begriffe', $this->generatedFrom['_glossaryBasePath'] ?? null);
        self::assertSame(['cve'], $this->generatedFrom['_glossaryRefs'] ?? null);
    }

    /**
     * A stored entry of the wrong shape (another version, a broken write) is
     * not trusted; the location is resolved anew.
     */
    #[Test]
    public function aMalformedGlossaryLocationEntryIsRebuilt(): void
    {
        $this->glossaryTerms = ['cve'];
        $site = $this->site(glossaryPage: 7);
        $this->middleware()->process($this->request($site), $this->handler());
        $key = (string) array_key_last(array_filter(
            $this->cacheEntries,
            static fn (string $key): bool => str_starts_with($key, 'glossary_location_'),
            ARRAY_FILTER_USE_KEY,
        ));

        foreach ([
            'not an array' => 'garbage',
            'no base path' => ['pagePaths' => ['/x']],
            'a base path that is no string' => ['basePath' => 5, 'pagePaths' => ['/x']],
            'no page paths' => ['basePath' => '/x'],
            'page paths that are no list' => ['basePath' => '/x', 'pagePaths' => '/x'],
            'page paths with keys' => ['basePath' => '/x', 'pagePaths' => [3 => '/x']],
            'a page path that is no string' => ['basePath' => '/x', 'pagePaths' => ['/x', 5]],
        ] as $why => $entry) {
            $this->cacheEntries = [$key => $entry];
            $before = $this->glossaryQueries['findPageSlug'];

            $this->middleware()->process($this->request($site), $this->handler());

            self::assertSame($before + 1, $this->glossaryQueries['findPageSlug'], $why);
            self::assertSame(['basePath' => '/glossar', 'pagePaths' => ['/glossar', '/en/glossar']], $this->cacheEntries[$key], $why);
        }
    }

    /**
     * Without a glossary page or without dpn_glossary there is nothing to
     * look up, and nothing is stored.
     */
    #[Test]
    public function withoutAGlossaryPageNoLocationIsStored(): void
    {
        foreach ([
            'no glossary page' => [['cve'], $this->site()],
            'no dpn_glossary' => [null, $this->site(glossaryPage: 7)],
        ] as $why => [$terms, $site]) {
            $this->glossaryTerms = $terms;
            $this->cacheEntries = [];
            $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];

            $this->middleware()->process($this->request($site), $this->handler());

            self::assertSame(['jsonld_example_0_42'], array_keys($this->cacheEntries), $why);
            self::assertSame(0, $this->glossaryQueries['findPageSlug'], $why);
        }
    }

    /**
     * A preview may see another glossary page than the public; it resolves
     * the location itself and stores nothing, and a stored entry is not used.
     */
    #[Test]
    public function aNonPublicContextResolvesTheGlossaryLocationItself(): void
    {
        $this->glossaryTerms = ['cve'];
        $site = $this->site(glossaryPage: 7);
        $html = '<html><head></head><body><a href="/glossar/cve" class="dpnglossary link">CVE</a></body></html>';

        foreach ($this->nonPublicContexts() as $why => [$aspectName, $aspect]) {
            $this->context = new Context();
            $this->context->setAspect($aspectName, $aspect);
            $this->cacheEntries = [];
            $this->cacheTags = [];
            $this->generatedFrom = [];
            $before = $this->glossaryQueries['findPageSlug'];

            $this->middleware()->process($this->request($site), $this->handler($html));

            self::assertSame([], $this->cacheEntries, $why . ': nothing stored');
            self::assertSame($before + 1, $this->glossaryQueries['findPageSlug'], $why);
            self::assertSame('/glossar', $this->generatedFrom['_glossaryBasePath'] ?? null, $why);
            self::assertSame(['cve'], $this->generatedFrom['_glossaryRefs'] ?? null, $why);
        }
    }

    /**
     * Contexts in which the page shows more than an anonymous visitor of the
     * live site may see. The cache key carries none of them.
     *
     * @return array<string, array{string, AspectInterface}>
     */
    private function nonPublicContexts(): array
    {
        $loggedInUser = $this->createStub(FrontendUserAuthentication::class);
        $loggedInUser->user = ['uid' => 5];

        return [
            'a workspace preview' => ['workspace', new WorkspaceAspect(3)],
            'hidden content shown' => ['visibility', new VisibilityAspect(includeHiddenContent: true)],
            'hidden pages shown' => ['visibility', new VisibilityAspect(includeHiddenPages: true)],
            'scheduled records shown' => ['visibility', new VisibilityAspect(includeScheduledRecords: true)],
            'deleted records shown' => ['visibility', new VisibilityAspect(includeDeletedRecords: true)],
            'a frontend preview' => ['frontend.preview', new PreviewAspect(true)],
            'a logged-in frontend user' => ['frontend.user', new UserAspect($loggedInUser)],
            'frontend groups without a login' => ['frontend.user', new UserAspect(null, [0, -2, 7])],
        ];
    }

    /**
     * AUD-061-F01: outside the plain public context the cache is neither read
     * nor written. A stored entry is not served (the request may see more
     * than it holds), and what the request builds is not stored (it may hold
     * what the public must not see).
     */
    #[Test]
    public function aNonPublicContextNeitherReadsNorWritesTheCache(): void
    {
        foreach ($this->nonPublicContexts() as $why => [$aspectName, $aspect]) {
            $this->context = new Context();
            $this->context->setAspect($aspectName, $aspect);
            $this->cacheEntries = ['jsonld_example_0_42' => '<script type="application/ld+json">{"name":"Stored for the public"}</script>'];
            $this->cacheTags = [];
            $this->documents = [new JsonLdDocument('WebPage', ['name' => 'Workspace draft'])];

            $body = (string) $this->middleware()->process($this->request(), $this->handler())->getBody();

            self::assertStringNotContainsString('Stored for the public', $body, $why . ': the cache was read');
            self::assertStringContainsString('Workspace draft', $body, $why . ': the page still gets its own JSON-LD');
            self::assertSame(
                ['jsonld_example_0_42' => '<script type="application/ld+json">{"name":"Stored for the public"}</script>'],
                $this->cacheEntries,
                $why . ': the cache was written',
            );
            self::assertSame([], $this->cacheTags, $why . ': the cache was written');
        }
    }

    /**
     * The reproduction from AUD-061-F01: a preview renders the page first,
     * then an anonymous visitor asks for it. The visitor gets the live
     * JSON-LD, not the preview's.
     */
    #[Test]
    public function aPreviewDoesNotLeakIntoTheNextAnonymousRequest(): void
    {
        foreach ($this->nonPublicContexts() as $why => [$aspectName, $aspect]) {
            $this->cacheEntries = [];
            $this->context = new Context();
            $this->context->setAspect($aspectName, $aspect);
            $this->documents = [new JsonLdDocument('FAQPage', ['name' => 'Unpublished question'])];
            $this->middleware()->process($this->request(), $this->handler());

            $this->context = new Context();
            $this->documents = [new JsonLdDocument('WebPage', ['name' => 'Live page'])];
            $body = (string) $this->middleware()->process($this->request(), $this->handler())->getBody();

            self::assertStringNotContainsString('Unpublished question', $body, $why);
            self::assertStringContainsString('Live page', $body, $why);
        }
    }

    /**
     * The plain public context still caches: the live workspace, default
     * visibility, no preview, and an anonymous frontend user with the groups
     * TYPO3 gives one (0, -1).
     */
    #[Test]
    public function thePlainPublicContextStillCaches(): void
    {
        $anonymous = $this->createStub(FrontendUserAuthentication::class);
        $this->context->setAspect('workspace', new WorkspaceAspect(0));
        $this->context->setAspect('visibility', VisibilityAspect::create());
        $this->context->setAspect('frontend.preview', new PreviewAspect(false));
        $this->context->setAspect('frontend.user', new UserAspect($anonymous));
        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'About us'])];
        $middleware = $this->middleware();

        $middleware->process($this->request(), $this->handler());

        self::assertSame(['jsonld_example_0_42'], array_keys($this->cacheEntries));

        $this->documents = [new JsonLdDocument('WebPage', ['name' => 'Something else entirely'])];
        $body = (string) $middleware->process($this->request(), $this->handler())->getBody();

        self::assertStringContainsString('About us', $body, 'the second request is served from the cache');
    }
}
