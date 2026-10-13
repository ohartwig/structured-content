<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\StructuredContent\Domain\Contract\EpisodeGuestsRepositoryInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class DoctrineEpisodeGuestsRepository implements EpisodeGuestsRepositoryInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findGuestUidsByPluginAndSlug(
        int $pluginUid,
        string $episodeSlug,
        int $languageId = 0,
    ): array {
        if ($pluginUid <= 0 || '' === trim($episodeSlug)) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('moselwal_podcast_episode_meta');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $rows = $qb->select('moselwal_podcastepisodemeta_guests')
            ->from('moselwal_podcast_episode_meta')
            ->where(
                $qb->expr()->eq(
                    'foreign_table_parent_uid',
                    $qb->createNamedParameter($pluginUid, ParameterType::INTEGER),
                ),
                $qb->expr()->eq(
                    'tablenames',
                    $qb->createNamedParameter('tt_content'),
                ),
                $qb->expr()->eq(
                    'fieldname',
                    $qb->createNamedParameter('moselwal_videopodcastfeed_episode_meta'),
                ),
                $qb->expr()->eq(
                    'moselwal_podcastepisodemeta_episode_slug',
                    $qb->createNamedParameter($episodeSlug),
                ),
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageId, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $uids = [];
        foreach ($rows as $row) {
            $raw = (string) ($row['moselwal_podcastepisodemeta_guests'] ?? '');
            if ('' === $raw) {
                continue;
            }
            foreach (explode(',', $raw) as $part) {
                $uid = (int) trim($part);
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        }

        return array_values(array_unique($uids));
    }

    public function findAbsentHostUidsByPluginAndSlug(
        int $pluginUid,
        string $episodeSlug,
        int $languageId = 0,
    ): array {
        if ($pluginUid <= 0 || '' === trim($episodeSlug)) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('moselwal_podcast_episode_meta');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $rows = $qb->select('moselwal_podcastepisodemeta_absent_hosts')
            ->from('moselwal_podcast_episode_meta')
            ->where(
                $qb->expr()->eq(
                    'foreign_table_parent_uid',
                    $qb->createNamedParameter($pluginUid, ParameterType::INTEGER),
                ),
                $qb->expr()->eq(
                    'tablenames',
                    $qb->createNamedParameter('tt_content'),
                ),
                $qb->expr()->eq(
                    'fieldname',
                    $qb->createNamedParameter('moselwal_videopodcastfeed_episode_meta'),
                ),
                $qb->expr()->eq(
                    'moselwal_podcastepisodemeta_episode_slug',
                    $qb->createNamedParameter($episodeSlug),
                ),
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageId, ParameterType::INTEGER),
                ),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $uids = [];
        foreach ($rows as $row) {
            $raw = (string) ($row['moselwal_podcastepisodemeta_absent_hosts'] ?? '');
            if ('' === $raw) {
                continue;
            }
            foreach (explode(',', $raw) as $part) {
                $uid = (int) trim($part);
                if ($uid > 0) {
                    $uids[] = $uid;
                }
            }
        }

        return array_values(array_unique($uids));
    }

    public function findTranscriptByPluginAndSlug(
        int $pluginUid,
        string $episodeSlug,
        int $languageId = 0,
    ): string {
        if ($pluginUid <= 0 || '' === trim($episodeSlug)) {
            return '';
        }

        $qb = $this->connectionPool->getQueryBuilderForTable('moselwal_podcast_episode_meta');
        $qb->setRestrictions(GeneralUtility::makeInstance(FrontendRestrictionContainer::class));

        $rows = $qb->select('moselwal_podcastepisodemeta_transcript')
            ->from('moselwal_podcast_episode_meta')
            ->where(
                $qb->expr()->eq(
                    'foreign_table_parent_uid',
                    $qb->createNamedParameter($pluginUid, ParameterType::INTEGER),
                ),
                $qb->expr()->eq(
                    'tablenames',
                    $qb->createNamedParameter('tt_content'),
                ),
                $qb->expr()->eq(
                    'fieldname',
                    $qb->createNamedParameter('moselwal_videopodcastfeed_episode_meta'),
                ),
                $qb->expr()->eq(
                    'moselwal_podcastepisodemeta_episode_slug',
                    $qb->createNamedParameter($episodeSlug),
                ),
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageId, ParameterType::INTEGER),
                ),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $merged = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['moselwal_podcastepisodemeta_transcript'] ?? ''));
            if ('' !== $value) {
                $merged[] = $value;
            }
        }

        return implode("\n\n", $merged);
    }
}
