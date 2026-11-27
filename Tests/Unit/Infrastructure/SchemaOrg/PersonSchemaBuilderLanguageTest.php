<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit\Infrastructure\SchemaOrg;

use Doctrine\DBAL\Result;
use Koh\StructuredContent\Infrastructure\SchemaOrg\PersonSchemaBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The person in the language of the request.
 *
 * The lookup reads the default-language record and overlays it, as the
 * frontend does for every record; DE and EN share the slug, so reading by
 * slug alone returned whichever row came first — the German one. The
 * overlay keeps the default-language slug, because the slug is part of the
 * Person @id that every page in every language refers to.
 */
final class PersonSchemaBuilderLanguageTest extends TestCase
{
    /** @var list<array<string, mixed>|false> rows returned by fetchAssociative(), in order */
    private array $rows = [];

    /** @var mixed returned by fetchOne() */
    private mixed $one = false;

    /** @var list<string> every query builder call and expression, in order */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        for ($i = 0; $i < 16; ++$i) {
            GeneralUtility::addInstance(
                FrontendRestrictionContainer::class,
                $this->createStub(FrontendRestrictionContainer::class),
            );
        }
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        unset($GLOBALS['TCA']['moselwal_person']);

        parent::tearDown();
    }

    private static function registerTranslatableTca(): void
    {
        $GLOBALS['TCA']['moselwal_person'] = [
            'ctrl' => ['languageField' => 'sys_language_uid', 'transOrigPointerField' => 'l10n_parent'],
            'columns' => [],
        ];
    }

    private static function renderIn(int $languageId): LanguageAspect
    {
        $aspect = new LanguageAspect($languageId, $languageId, LanguageAspect::OVERLAYS_MIXED, [0]);
        GeneralUtility::makeInstance(Context::class)->setAspect('language', $aspect);

        return $aspect;
    }

    /**
     * @param \Closure(string, array<string, mixed>, LanguageAspect): (array<string, mixed>|null) $overlay
     */
    private function overlayWith(\Closure $overlay): void
    {
        $pageRepository = $this->createStub(PageRepository::class);
        $pageRepository->method('getLanguageOverlay')->willReturnCallback($overlay);
        GeneralUtility::addInstance(PageRepository::class, $pageRepository);
    }

    private function builder(): PersonSchemaBuilder
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAssociative')->willReturnCallback(fn (): array|false => [] === $this->rows ? false : array_shift($this->rows));
        $result->method('fetchOne')->willReturnCallback(fn (): mixed => $this->one);

        $expr = $this->createStub(ExpressionBuilder::class);
        foreach (['eq', 'in', 'gt'] as $operator) {
            $expr->method($operator)->willReturnCallback(function (string $field) use ($operator): string {
                $this->calls[] = $operator . ':' . $field;

                return $operator . ':' . $field;
            });
        }

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults', 'setRestrictions'] as $method) {
            $queryBuilder->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method, $queryBuilder): QueryBuilder {
                $first = $arguments[0] ?? null;
                $this->calls[] = $method . (is_string($first) ? ':' . $first : '');

                return $queryBuilder;
            });
        }
        $queryBuilder->method('expr')->willReturn($expr);
        $queryBuilder->method('createNamedParameter')->willReturn(':p');
        $queryBuilder->method('executeQuery')->willReturn($result);

        $pool = $this->createStub(ConnectionPool::class);
        $pool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        return new PersonSchemaBuilder($pool);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function germanRow(array $overrides = []): array
    {
        return [
            'uid' => 7,
            'sys_language_uid' => 0,
            'moselwal_person_firstname' => 'Ada',
            'moselwal_person_lastname' => 'Lovelace',
            'moselwal_person_slug' => 'ada-lovelace',
            'moselwal_person_job_title' => 'Mathematikerin',
            ...$overrides,
        ];
    }

    #[Test]
    public function theSlugLookupReadsTheDefaultLanguageRecordsOfTheTable(): void
    {
        self::registerTranslatableTca();
        self::renderIn(0);
        $this->rows = [self::germanRow()];

        $this->builder()->findBySlug('ada-lovelace');

        self::assertSame(
            ['setRestrictions', 'select:*', 'from:moselwal_person', 'eq:moselwal_person_slug', 'where:eq:moselwal_person_slug', 'in:sys_language_uid', 'andWhere:in:sys_language_uid', 'setMaxResults'],
            $this->calls,
        );
    }

    #[Test]
    public function withoutTranslationSupportTheSlugAloneDecides(): void
    {
        self::renderIn(1);
        $this->rows = [self::germanRow()];

        $row = $this->builder()->findBySlug('ada-lovelace');

        self::assertNotContains('andWhere:in:sys_language_uid', $this->calls);
        self::assertSame('Mathematikerin', $row['moselwal_person_job_title'] ?? null, 'no overlay without a language field');
    }

    #[Test]
    public function anEnglishRequestGetsTheOverlayWithTheDefaultSlug(): void
    {
        self::registerTranslatableTca();
        $english = self::renderIn(1);
        $seen = [];
        $this->overlayWith(static function (string $table, array $row, LanguageAspect $language) use (&$seen): array {
            $seen = [$table, $row['uid'], $language->getContentId()];

            return [...$row, '_LOCALIZED_UID' => 8, 'moselwal_person_slug' => 'ada-lovelace-en', 'moselwal_person_job_title' => 'Mathematician'];
        });
        $this->rows = [self::germanRow()];

        $row = $this->builder()->findBySlug('ada-lovelace');

        self::assertSame(['moselwal_person', 7, $english->getContentId()], $seen);
        self::assertSame('Mathematician', $row['moselwal_person_job_title'] ?? null);
        self::assertSame('ada-lovelace', $row['moselwal_person_slug'] ?? null);
        self::assertSame(8, $row['_LOCALIZED_UID'] ?? null);
    }

    #[Test]
    public function aLanguageThatHidesUntranslatedRecordsStillShowsThePerson(): void
    {
        self::registerTranslatableTca();
        self::renderIn(1);
        $this->overlayWith(static fn (): ?array => null);
        $this->rows = [self::germanRow()];

        self::assertSame('Mathematikerin', $this->builder()->findBySlug('ada-lovelace')['moselwal_person_job_title'] ?? null);
    }

    #[Test]
    public function theUidLookupIsOverlaidAsWell(): void
    {
        self::registerTranslatableTca();
        self::renderIn(1);
        $this->overlayWith(static fn (string $table, array $row): array => [...$row, 'moselwal_person_job_title' => 'Mathematician']);
        $this->rows = [self::germanRow()];

        self::assertSame('Mathematician', $this->builder()->fetchPersonRow(7)['moselwal_person_job_title'] ?? null);
    }

    #[Test]
    public function theDefaultLanguageIsNotOverlaid(): void
    {
        self::registerTranslatableTca();
        self::renderIn(0);
        $this->overlayWith(static fn (string $table, array $row): array => [...$row, 'moselwal_person_job_title' => 'Mathematician']);
        $this->rows = [self::germanRow()];

        self::assertSame('Mathematikerin', $this->builder()->findBySlug('ada-lovelace')['moselwal_person_job_title'] ?? null);
    }

    #[Test]
    public function aTranslationWithASlugOfItsOwnLeadsToItsDefaultRecord(): void
    {
        self::registerTranslatableTca();
        self::renderIn(1);
        $this->overlayWith(static fn (string $table, array $row): array => [...$row, 'moselwal_person_job_title' => 'Mathematician']);
        $this->rows = [false, self::germanRow()];
        $this->one = '7';

        $row = $this->builder()->findBySlug('ada-lovelace-en');

        self::assertSame(7, $row['uid'] ?? null);
        self::assertSame('Mathematician', $row['moselwal_person_job_title'] ?? null);
        self::assertContains('eq:sys_language_uid', $this->calls);
        self::assertContains('gt:l10n_parent', $this->calls);
        self::assertContains('select:l10n_parent', $this->calls);
    }

    #[Test]
    public function anUnknownSlugFindsNobody(): void
    {
        self::registerTranslatableTca();
        self::renderIn(1);
        $this->rows = [false];
        $this->one = false;

        self::assertNull($this->builder()->findBySlug('nobody'));
    }

    /**
     * The translation lookup only runs where it can find something: in a
     * translation language, on a table with language and pointer fields.
     */
    #[Test]
    public function theTranslationLookupNeedsALanguageAndATranslatableTable(): void
    {
        self::registerTranslatableTca();
        self::renderIn(0);
        $this->rows = [false];
        $this->one = '7';

        self::assertNull($this->builder()->findBySlug('ada-lovelace-en'), 'the default language has no translations to search');
        self::assertSame([], $this->translationLookups());

        unset($GLOBALS['TCA']['moselwal_person']['ctrl']['transOrigPointerField']);
        self::renderIn(1);
        $this->calls = [];
        $this->rows = [false];

        self::assertNull($this->builder()->findBySlug('ada-lovelace-en'), 'without a pointer field there is no default record to reach');
        self::assertSame([], $this->translationLookups());

        unset($GLOBALS['TCA']['moselwal_person']);
        $this->calls = [];
        $this->rows = [false];

        self::assertNull($this->builder()->findBySlug('ada-lovelace-en'), 'without a language field there are no translations');
        self::assertSame([], $this->translationLookups());
    }

    /**
     * @return list<string>
     */
    private function translationLookups(): array
    {
        return array_values(array_filter($this->calls, static fn (string $call): bool => str_starts_with($call, 'gt:')));
    }

    #[Test]
    public function aBlankSlugFindsNobodyWithoutAQuery(): void
    {
        self::assertNull($this->builder()->findBySlug('  '));
        self::assertSame([], $this->calls);
    }
}
