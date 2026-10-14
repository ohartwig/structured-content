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

    protected function setUp(): void
    {
        parent::setUp();

        // Die Middleware baut Restriction-Container ueber `new`, und deren
        // Konstruktor zieht die TCA-Schema-Fabrik. Sie ist hier vorab
        // gedoubelt — die Warteschlange gibt jeweils eine heraus.
        for ($i = 0; $i < 64; ++$i) {
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
        );
    }

    private function glossaryRepository(): GlossaryTermRepositoryInterface
    {
        $repository = $this->createStub(GlossaryTermRepositoryInterface::class);
        $repository->method('isAvailable')->willReturnCallback(fn (): bool => null !== $this->glossaryTerms);
        $repository->method('findPageSlug')->willReturn('/glossar');
        $repository->method('findTermBySlug')->willReturnCallback(
            fn (string $slug): ?array => in_array($slug, $this->glossaryTerms ?? [], true)
                ? ['uid' => 1, 'name' => $slug, 'url_segment' => $slug]
                : null,
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

    private function site(bool $enabled = true, bool $schemaEnabled = true): Site
    {
        return new Site('example', 1, [
            'base' => 'https://example.com/',
            'languages' => [['languageId' => 0, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/']],
            'settings' => [
                'structuredContent' => [
                    'enabled' => $enabled,
                    'schema' => ['enabled' => $schemaEnabled],
                ],
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
}
