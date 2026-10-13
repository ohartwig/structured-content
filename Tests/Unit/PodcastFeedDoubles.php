<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Tests\Unit;

use Doctrine\DBAL\Result;
use PHPUnit\Framework\MockObject\Stub;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

/**
 * Der Feed-Cache, aus dem beide Podcast-Generatoren lesen.
 *
 * Die Daten stammen nicht aus dieser Extension: `content-blocks-collection`
 * holt den RSS-Feed und legt ihn unter einem abgesprochenen Schluessel ab.
 * Genau diese Absprache ist hier das Doppel — der Schluessel wird gebildet wie
 * dort, nicht wie es hier bequem waere. Ein Test, der den Schluessel selbst
 * erfindet, wuerde die eine Sorte Fehler nicht sehen, die in dieser Kopplung
 * schon einmal aufgetreten ist.
 */
trait PodcastFeedDoubles
{
    /** @var array<string, mixed> */
    protected array $cacheEntries = [];

    /** @var list<array<string, mixed>> */
    protected array $rows = [];

    /**
     * Ein Ergebnis je Abfrage, der Reihe nach.
     *
     * `$rows` beantwortet jede Abfrage gleich, was fuer die meisten Faelle
     * genuegt und unabhaengig von der Abfragereihenfolge bleibt. Wo ein Pfad
     * aber *zwei* Abfragen absetzt und die zweite auf der ersten aufbaut — die
     * Uebersetzungsueberlagerung tut genau das — ist "jede Abfrage liefert
     * dasselbe" nicht bloss ungenau, es macht den Pfad untestbar: die
     * Uebersetzung ist dann ihre eigene Standardzeile.
     *
     * Ist diese Folge gesetzt, liefert sie ein Ergebnis pro Abfrage; danach
     * faellt es auf `$rows` zurueck.
     *
     * @var list<list<array<string, mixed>>>
     */
    protected array $rowSets = [];

    /** @var list<array{0: string, 1: string}> Sortierungen, die eine Abfrage angefordert hat */
    protected array $ordering = [];

    /**
     * Der Schluessel, unter dem `PodcastFeedService` einen Feed ablegt.
     */
    protected function feedCacheIdentifier(string $feedUrl): string
    {
        return 'rssfeed_' . hash('sha256', $feedUrl);
    }

    /**
     * @param array<string, mixed>       $meta
     * @param list<array<string, mixed>> $episodes
     */
    protected function feedInCache(string $feedUrl, array $meta = [], array $episodes = []): void
    {
        $this->cacheEntries[$this->feedCacheIdentifier($feedUrl)] = [
            'meta' => $meta,
            'episodes' => $episodes,
        ];
    }

    protected function cacheManager(): CacheManager&Stub
    {
        $cache = $this->createStub(FrontendInterface::class);
        $cache->method('has')->willReturnCallback(fn (string $key): bool => isset($this->cacheEntries[$key]));
        $cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cacheEntries[$key] ?? false);

        $manager = $this->createStub(CacheManager::class);
        $manager->method('getCache')->willReturn($cache);

        return $manager;
    }

    protected function connectionPool(): ConnectionPool&Stub
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchAllAssociative')->willReturnCallback(function (): array {
            if ([] !== $this->rowSets) {
                return array_shift($this->rowSets);
            }

            return $this->rows;
        });
        $result->method('fetchAssociative')->willReturnCallback(fn (): array|false => $this->rows[0] ?? false);
        $result->method('fetchOne')->willReturnCallback(
            fn (): mixed => $this->rows[0]['uid'] ?? $this->rows[0]['slug'] ?? false,
        );

        $queryBuilder = $this->createStub(QueryBuilder::class);
        foreach (['select', 'from', 'where', 'andWhere', 'setMaxResults'] as $chained) {
            $queryBuilder->method($chained)->willReturnSelf();
        }
        $queryBuilder->method('orderBy')->willReturnCallback(
            function (string $field, ?string $direction = null) use ($queryBuilder): QueryBuilder {
                $this->ordering[] = [$field, $direction ?? 'ASC'];

                return $queryBuilder;
            },
        );
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

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    protected function episode(array $overrides = []): array
    {
        return [
            'episode' => '12',
            'title' => 'Wie Suchmaschinen Podcasts lesen',
            'description' => 'Eine Folge ueber strukturierte Daten.',
            'guid' => 'https://example.com/feed/12',
            'link' => 'https://example.com/folge-12',
            'datePublished' => '2026-07-01T09:00:00+02:00',
            'duration' => '00:42:00',
            'enclosureUrl' => 'https://example.com/media/12.mp3',
            'enclosureType' => 'audio/mpeg',
            'imageUrl' => 'https://example.com/media/12.jpg',
            ...$overrides,
        ];
    }
}
