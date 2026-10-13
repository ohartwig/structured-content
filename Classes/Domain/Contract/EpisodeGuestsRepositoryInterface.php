<?php

declare(strict_types=1);

namespace Koh\StructuredContent\Domain\Contract;

/**
 * Resolves per-episode guest UIDs and transcripts from the
 * moselwal_podcast_episode_meta table.
 */
interface EpisodeGuestsRepositoryInterface
{
    /**
     * @return int[] guest moselwal_person UIDs for the given plugin + slug
     */
    public function findGuestUidsByPluginAndSlug(
        int $pluginUid,
        string $episodeSlug,
        int $languageId = 0,
    ): array;

    /**
     * @return int[] moselwal_person UIDs of plugin-level hosts marked as NOT
     *               present in the given episode (plugin + slug). Consumers
     *               subtract these from the resolved host list.
     */
    public function findAbsentHostUidsByPluginAndSlug(
        int $pluginUid,
        string $episodeSlug,
        int $languageId = 0,
    ): array;

    public function findTranscriptByPluginAndSlug(
        int $pluginUid,
        string $episodeSlug,
        int $languageId = 0,
    ): string;
}
