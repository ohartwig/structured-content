<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Baut einen Generator samt seiner Abhaengigkeiten, ohne dass jeder Test die
 * Konstruktor-Signatur kennen muss.
 *
 * Der Grund ist nicht Bequemlichkeit: mehrere Kollaborateure sind `final`
 * (PersonSchemaBuilder, SchemaTypeResolverService, GlossaryTermService) und
 * lassen sich deshalb nicht doubeln. Sie werden hier echt gebaut — mit
 * gedoubelten eigenen Abhaengigkeiten — statt durch eine Attrappe ersetzt.
 *
 * Das ist auch das Ehrlichere: was ein Generator mit den Zeilen macht, die er
 * bekommt, ist sein Verhalten. Eine Attrappe wuerde sich mit dem Test ueber
 * eine Zeilenform einig, die es in Produktion nie gibt.
 */
final class SchemaGeneratorFactory
{
    public function __construct(
        private readonly TestCase $test,
        /** @var callable(string): (object|null) */
        private $override = null,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function make(string $class): object
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor === null) {
            return $reflection->newInstance();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->argumentFor($parameter);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    private function argumentFor(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            if ($parameter->isDefaultValueAvailable()) {
                return $parameter->getDefaultValue();
            }

            return match ($type instanceof \ReflectionNamedType ? $type->getName() : 'mixed') {
                'string' => '',
                'int' => 0,
                'float' => 0.0,
                'bool' => false,
                'array' => [],
                default => null,
            };
        }

        /** @var class-string $name */
        $name = $type->getName();

        if ($this->override !== null) {
            $supplied = ($this->override)($name);
            if ($supplied !== null) {
                return $supplied;
            }
        }

        $reflection = new \ReflectionClass($name);

        // A final class cannot be doubled, so it is built the same way —
        // recursively, on doubles of its own dependencies.
        if ($reflection->isFinal() && !$reflection->isInterface()) {
            return $this->make($name);
        }

        return $this->stub($name);
    }

    /**
     * @param class-string $name
     */
    private function stub(string $name): Stub
    {
        /** @var \Closure(class-string): Stub $createStub */
        $createStub = \Closure::bind(
            static fn (TestCase $test, string $class): Stub => $test->createStub($class),
            null,
            TestCase::class,
        );

        return $createStub($this->test, $name);
    }
}
