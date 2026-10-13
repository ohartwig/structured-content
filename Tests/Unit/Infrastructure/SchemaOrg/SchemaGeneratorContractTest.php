<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Koh\StructuredContent\Domain\Contract\ContextResolverInterface;
use Koh\StructuredContent\Domain\Contract\SchemaGeneratorInterface;
use Koh\StructuredContent\Domain\Model\JsonLdDocument;
use Koh\StructuredContent\Domain\Model\ResolvedContext;
use Koh\StructuredContent\Tests\Unit\SchemaGeneratorFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Was fuer jeden Generator gilt, geprueft an jedem Generator.
 *
 * Es sind zwei Dutzend Klassen mit derselben Schnittstelle, und die
 * interessanten Eigenschaften sind die, die keine von ihnen verletzen darf —
 * gerade weil eine neue Klasse dazukommt, ohne dass jemand diese Datei
 * oeffnet. Deshalb wird die Liste hier aus dem Verzeichnis gelesen und nicht
 * gepflegt: ein Generator, der morgen hinzukommt, ist ab dem ersten Lauf
 * mitgeprueft.
 *
 * Drei Eigenschaften:
 *
 * `canHandle()` entscheidet auf fremden Daten und darf nicht werfen. Die
 * Seitendaten und Content-Elemente kommen aus der Datenbank einer Kundenseite;
 * ein fehlendes Feld ist der Normalfall, kein Ausnahmefall. Wirft ein
 * Generator hier, faellt die Schema-Erzeugung der ganzen Seite aus — fuer alle
 * Generatoren, nicht nur fuer den einen.
 *
 * `generate()` liefert ein Dokument, dessen JSON gueltig ist. Ein Dokument,
 * das sich nicht kodieren laesst, wird zu `{}` — still, ohne Fehler, und die
 * Seite liefert dann ein leeres Schema aus, das wie ein absichtlich leeres
 * aussieht.
 *
 * Und der Script-Tag laesst sich nicht aufbrechen. Der Inhalt stammt aus
 * redaktionellen Feldern; ein `</script>` darin ist die klassische
 * XSS-Einstiegsluecke, und die Ausgabe landet ungefiltert im Seitenkopf.
 */
final class SchemaGeneratorContractTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: SchemaGeneratorInterface}>
     */
    private function generators(): array
    {
        // `ResolvedContext` is final, so a stubbed resolver cannot invent a
        // return value for it. It gets the real defaults instead.
        $factory = new SchemaGeneratorFactory($this, function (string $class): ?object {
            if ($class !== ContextResolverInterface::class) {
                return null;
            }

            $resolver = $this->createStub(ContextResolverInterface::class);
            $resolver->method('resolve')->willReturn(ResolvedContext::withDefaults());
            $resolver->method('resolveFromPageTree')->willReturn(ResolvedContext::withDefaults());

            return $resolver;
        });
        $directory = dirname(__DIR__, 4) . '/Classes/Infrastructure/SchemaOrg';

        $generators = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if (!str_ends_with($entry, 'SchemaGenerator.php')) {
                continue;
            }

            /** @var class-string $class */
            $class = 'Koh\\StructuredContent\\Infrastructure\\SchemaOrg\\' . substr($entry, 0, -4);
            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || !$reflection->implementsInterface(SchemaGeneratorInterface::class)) {
                continue;
            }

            /** @var SchemaGeneratorInterface $generator */
            $generator = $factory->make($class);
            $generators[] = [$reflection->getShortName(), $generator];
        }

        self::assertGreaterThan(10, count($generators), 'the directory scan found no generators');

        return $generators;
    }

    /**
     * @return list<array{0: string, 1: array<string, mixed>, 2: list<array<string, mixed>>}>
     */
    private function pages(): array
    {
        return [
            ['an empty page', [], []],
            ['a page with nothing but a uid', ['uid' => 42], []],
            [
                'an ordinary page',
                [
                    'uid' => 42,
                    'title' => 'About us',
                    'description' => 'Who we are.',
                    'canonical_link' => 'https://example.com/about',
                    'slug' => '/about',
                    '_siteBase' => 'https://example.com',
                    'sys_language_uid' => 0,
                ],
                [
                    ['uid' => 1, 'CType' => 'text', 'header' => 'About us', 'bodytext' => '<p>Who we are.</p>'],
                    ['uid' => 2, 'CType' => 'textmedia', 'header' => 'Our work', 'bodytext' => 'What we do.'],
                ],
            ],
            [
                'a page whose fields are the wrong type',
                ['uid' => 'not a number', 'title' => ['an', 'array'], 'sys_language_uid' => null],
                [['uid' => null, 'CType' => 42, 'header' => false]],
            ],
        ];
    }

    /**
     * Die erste Eigenschaft: `canHandle()` wirft nicht. Es entscheidet auf
     * Daten aus einer fremden Datenbank, in der ein Feld fehlen kann — und
     * eine Ausnahme hier nimmt die Schema-Erzeugung der ganzen Seite mit,
     * nicht nur die dieses einen Generators.
     */
    #[Test]
    public function noGeneratorThrowsWhileDecidingWhetherItApplies(): void
    {
        foreach ($this->generators() as [$name, $generator]) {
            foreach ($this->pages() as [$why, $pageData, $contentElements]) {
                $answer = $generator->canHandle($pageData, $contentElements);

                self::assertIsBool($answer, $name . ' on ' . $why);
            }
        }
    }

    /**
     * Die Prioritaet ordnet die Generatoren gegeneinander. Sie muss stabil
     * sein — dieselbe Instanz zweimal gefragt, dieselbe Antwort —, sonst
     * haengt es vom Zufall ab, welcher Generator eine Seite bekommt.
     */
    #[Test]
    public function everyGeneratorHasAStablePriority(): void
    {
        foreach ($this->generators() as [$name, $generator]) {
            $priority = $generator->getPriority();

            self::assertSame($priority, $generator->getPriority(), $name);
            self::assertGreaterThan(0, $priority, $name . ' — 0 sorts unpredictably against the default');
        }
    }

    /**
     * Die zweite: was `generate()` liefert, laesst sich kodieren. Ein
     * Dokument, das das nicht tut, wird still zu `{}` — die Seite liefert dann
     * ein leeres Schema aus, das wie ein absichtlich leeres aussieht.
     */
    #[Test]
    public function everythingAGeneratorProducesIsEncodableJson(): void
    {
        foreach ($this->generators() as [$name, $generator]) {
            foreach ($this->pages() as [$why, $pageData, $contentElements]) {
                if (!$generator->canHandle($pageData, $contentElements)) {
                    continue;
                }

                $document = $generator->generate($pageData, $contentElements);
                $json = $document->toJsonLd();

                self::assertInstanceOf(JsonLdDocument::class, $document, $name . ' on ' . $why);
                self::assertNotSame('{}', $json, $name . ' on ' . $why . ' — the encode fell back');

                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

                self::assertSame('https://schema.org', $decoded['@context'], $name . ' on ' . $why);
                self::assertNotSame('', $decoded['@type'] ?? '', $name . ' on ' . $why);
            }
        }
    }

    /**
     * Und die dritte: der Script-Tag laesst sich nicht aufbrechen. Der Inhalt
     * kommt aus redaktionellen Feldern, die Ausgabe landet ungefiltert im
     * Seitenkopf, und `</script>` darin ist die klassische Einstiegsluecke.
     */
    #[Test]
    public function noGeneratorCanBeTalkedOutOfItsScriptTag(): void
    {
        $hostile = [
            'uid' => 42,
            'title' => '</script><script>alert(1)</script>',
            'description' => 'Ends the tag: </SCRIPT >',
            'canonical_link' => 'https://example.com/</script>',
            'slug' => '/</script>',
            '_siteBase' => 'https://example.com',
        ];
        $hostileElements = [[
            'uid' => 1,
            'CType' => 'text',
            'header' => '</script><img src=x onerror=alert(1)>',
            'bodytext' => 'Question? </script> Answer. Another? </script> Yes.',
        ]];

        foreach ($this->generators() as [$name, $generator]) {
            if (!$generator->canHandle($hostile, $hostileElements)) {
                continue;
            }

            $tag = $generator->generate($hostile, $hostileElements)->toScriptTag();

            self::assertStringStartsWith('<script type="application/ld+json">', $tag, $name);
            self::assertStringEndsWith('</script>', $tag, $name);
            self::assertSame(
                1,
                substr_count($tag, '</script>'),
                $name . ' — the payload closed the tag early',
            );
            self::assertStringNotContainsString('<img', $tag, $name);
        }
    }
}
