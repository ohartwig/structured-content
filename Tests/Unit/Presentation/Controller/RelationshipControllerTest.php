<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Presentation\Controller;

use Koh\StructuredContent\Application\Service\RelationshipService;
use Koh\StructuredContent\Domain\Contract\EventDispatcherInterface;
use Koh\StructuredContent\Domain\Contract\RelationshipRepositoryInterface;
use Koh\StructuredContent\Domain\Enum\RelationType;
use Koh\StructuredContent\Presentation\Controller\RelationshipController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * Der Endpunkt, ueber den eine Beziehung im Backend angelegt wird.
 *
 * Er nimmt sieben Werte aus einem Formular entgegen und schreibt daraus eine
 * Kante zwischen zwei Inhalten. Jeder dieser Werte wird geprueft, bevor
 * irgendetwas geschrieben wird — und das ist die Eigenschaft, um die es geht:
 * eine Beziehung mit einer 0 als uid, einer erfundenen Richtung oder einem
 * Beziehungstyp, den es nicht gibt, ist keine Beziehung, sondern eine Zeile,
 * die spaeter jeden Leser dieser Kanten in die Irre fuehrt.
 *
 * Antworten sind JSON mit einem Statuscode, weil hier ein Formular per
 * JavaScript spricht: 400 fuer eine Eingabe, die so nicht stimmen kann, 409
 * fuer eine, die stimmt, aber nicht geschrieben werden konnte.
 */
final class RelationshipControllerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $added = [];

    private bool $addFails = false;

    private function controller(): RelationshipController
    {
        $repository = $this->createStub(RelationshipRepositoryInterface::class);
        $repository->method('findBySource')->willReturn([]);
        $repository->method('findBidirectionalByTarget')->willReturn([]);
        $repository->method('exists')->willReturnCallback(fn (): bool => $this->addFails);
        $repository->method('insert')->willReturnCallback(
            function (string $sourceTable, int $sourceUid, string $targetTable, int $targetUid, RelationType $type, string $direction, float $confidence, string $createdBy): void {
                $this->added[] = [
                    'sourceTable' => $sourceTable,
                    'sourceUid' => $sourceUid,
                    'targetTable' => $targetTable,
                    'targetUid' => $targetUid,
                    'type' => $type,
                    'direction' => $direction,
                    'confidence' => $confidence,
                    'createdBy' => $createdBy,
                ];
            },
        );

        return new RelationshipController(
            // Der Dienst ist final und laeuft echt auf einem gedoubelten
            // Repository — was der Endpunkt entscheidet, ist die Pruefung
            // davor, und die soll gegen das echte Verhalten laufen.
            new RelationshipService($repository, $this->createStub(EventDispatcherInterface::class)),
            // `ModuleTemplateFactory` ist final und zieht den ganzen
            // Backend-Renderstack nach. `listAction()` rendert und ist hier
            // nicht abgedeckt; `addAction()` antwortet mit JSON.
            (new \ReflectionClass(ModuleTemplateFactory::class))->newInstanceWithoutConstructor(),
        );
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(array $body): array
    {
        $request = (new ServerRequest('https://example.com/typo3/module/relations', 'POST'))->withParsedBody($body);

        $response = $this->controller()->addAction($request);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return ['status' => $response->getStatusCode(), 'body' => $decoded, 'response' => $response];
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return [
            'sourceTable' => 'pages',
            'sourceUid' => 42,
            'targetTable' => 'pages',
            'targetUid' => 43,
            'relationType' => 'explains',
            'direction' => 'unidirectional',
            'confidence' => 0.8,
        ];
    }

    #[Test]
    public function aCompleteRequestWritesTheRelation(): void
    {
        $result = $this->post($this->validBody());

        self::assertSame(200, $result['status']);
        self::assertTrue($result['body']['success']);
        self::assertCount(1, $this->added);

        $added = $this->added[0];
        self::assertSame('pages', $added['sourceTable']);
        self::assertSame(42, $added['sourceUid']);
        self::assertSame(43, $added['targetUid']);
        self::assertSame(RelationType::EXPLAINS, $added['type']);
        self::assertSame(0.8, $added['confidence']);
    }

    #[Test]
    public function aRequestWithoutAFormBodyIsRefused(): void
    {
        $request = new ServerRequest('https://example.com/typo3/module/relations', 'POST');

        $response = $this->controller()->addAction($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([], $this->added);
    }

    /**
     * Die Eigenschaft, um die es geht: jeder Wert wird geprueft, bevor
     * irgendetwas geschrieben wird. Eine Beziehung mit einer 0 als uid oder
     * einer erfundenen Richtung ist keine Beziehung, sondern eine Zeile, die
     * spaeter jeden Leser dieser Kanten in die Irre fuehrt.
     */
    #[Test]
    public function everyValueIsCheckedBeforeAnythingIsWritten(): void
    {
        foreach ([
            'no source table' => ['sourceTable' => ''],
            'no target table' => ['targetTable' => ''],
            'a source uid that is no record' => ['sourceUid' => 0],
            'a target uid that is no record' => ['targetUid' => -1],
            'an invented direction' => ['direction' => 'sideways'],
            'a confidence above one' => ['confidence' => 1.5],
            'a negative confidence' => ['confidence' => -0.1],
            'a relation type that does not exist' => ['relationType' => 'erklaert-irgendwie'],
            'no relation type at all' => ['relationType' => ''],
        ] as $why => $override) {
            $result = $this->post([...$this->validBody(), ...$override]);

            self::assertSame(400, $result['status'], $why);
            self::assertArrayHasKey('error', $result['body'], $why);
            self::assertSame([], $this->added, $why . ' — and nothing was written');
        }
    }

    /**
     * Die Grenzen der Zuversicht gehoeren noch dazu: 0 und 1 sind gueltige
     * Werte, kein Sonderfall.
     */
    #[Test]
    public function theEndsOfTheConfidenceRangeAreValid(): void
    {
        foreach ([0.0, 1.0] as $confidence) {
            $this->added = [];

            $result = $this->post([...$this->validBody(), 'confidence' => $confidence]);

            self::assertSame(200, $result['status'], (string) $confidence);
            self::assertSame($confidence, $this->added[0]['confidence']);
        }
    }

    #[Test]
    public function bothDirectionsAreAccepted(): void
    {
        foreach (['unidirectional', 'bidirectional'] as $direction) {
            $this->added = [];

            $result = $this->post([...$this->validBody(), 'direction' => $direction]);

            self::assertSame(200, $result['status'], $direction);
            self::assertSame($direction, $this->added[0]['direction']);
        }
    }

    #[Test]
    public function everyKnownRelationTypeIsAccepted(): void
    {
        foreach (RelationType::cases() as $type) {
            $this->added = [];

            $result = $this->post([...$this->validBody(), 'relationType' => $type->value]);

            self::assertSame(200, $result['status'], $type->value);
            self::assertSame($type, $this->added[0]['type']);
        }
    }

    /**
     * Eine Beziehung, die es schon gibt, ist ein Konflikt und kein Fehler in
     * der Eingabe — die Unterscheidung ist das, woran ein Formular erkennt, ob
     * es den Benutzer korrigieren oder ihm sagen soll, dass es die Kante
     * bereits gibt.
     */
    #[Test]
    public function anExistingRelationIsAConflictRatherThanABadRequest(): void
    {
        $this->addFails = true;

        $result = $this->post($this->validBody());

        self::assertSame(409, $result['status']);
        self::assertArrayHasKey('error', $result['body']);
    }

    /**
     * Die Antwort ist JSON — hier spricht ein Formular per JavaScript, und
     * eine HTML-Fehlerseite waere fuer den Aufrufer unlesbar.
     */
    #[Test]
    public function everyAnswerIsJson(): void
    {
        $result = $this->post($this->validBody());

        /** @var ResponseInterface $response */
        $response = $result['response'];

        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }
}
