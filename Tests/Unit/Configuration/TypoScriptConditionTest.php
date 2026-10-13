<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use TYPO3\CMS\Core\ExpressionLanguage\FunctionsProvider\DefaultFunctionsProvider;
use TYPO3\CMS\Core\Routing\PageArguments;

/**
 * Every TypoScript condition this extension ships has to survive a request
 * without page arguments. The frontend TypoScript is also built in backend
 * requests, and a condition that dereferences request.getPageArguments()
 * there takes the whole backend module down with a 500 (2026-10-02).
 */
final class TypoScriptConditionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conditions(): iterable
    {
        $root = \dirname(__DIR__, 3) . '/Configuration';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('typoscript' !== $file->getExtension()) {
                continue;
            }
            foreach (file($file->getPathname(), \FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
                $line = trim($line);
                if (preg_match('/^\[(.+)\]$/', $line, $match) && !\in_array(strtoupper($match[1]), ['END', 'GLOBAL', 'ELSE'], true)) {
                    $where = substr($file->getPathname(), \strlen($root) + 1) . ':' . ($number + 1);
                    yield $where => [$where, $match[1]];
                }
            }
        }
    }

    private function language(): ExpressionLanguage
    {
        return new ExpressionLanguage(null, [new DefaultFunctionsProvider()]);
    }

    private function request(?PageArguments $pageArguments): object
    {
        return new class ($pageArguments) {
            public function __construct(private readonly ?PageArguments $pageArguments) {}

            public function getPageArguments(): ?PageArguments
            {
                return $this->pageArguments;
            }
        };
    }

    #[Test]
    public function theExtensionShipsConditionsAtAll(): void
    {
        // An empty provider would make the test below pass for nothing.
        self::assertNotEmpty(iterator_to_array(self::conditions()));
    }

    #[Test]
    #[DataProvider('conditions')]
    public function aConditionEvaluatesInARequestWithoutPageArguments(string $where, string $condition): void
    {
        try {
            $this->language()->evaluate($condition, ['request' => $this->request(null)]);
        } catch (\Throwable $e) {
            self::fail($where . ': ' . $e->getMessage());
        }
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theGlossaryConditionStillMatchesATermPage(): void
    {
        $glossary = array_values(array_filter(
            iterator_to_array(self::conditions()),
            static fn (array $c): bool => str_contains($c[1], 'tx_dpnglossary_glossary/term'),
        ));
        self::assertCount(1, $glossary, 'the glossary condition in setup.typoscript');
        $condition = $glossary[0][1];
        $termPage = new PageArguments(42, '0', ['tx_dpnglossary_glossary' => ['term' => '7']]);
        $otherPage = new PageArguments(42, '0', []);

        self::assertTrue($this->language()->evaluate($condition, ['request' => $this->request($termPage)]));
        self::assertFalse($this->language()->evaluate($condition, ['request' => $this->request($otherPage)]));
        self::assertFalse($this->language()->evaluate($condition, ['request' => $this->request(null)]));
    }
}
