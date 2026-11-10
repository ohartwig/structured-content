<?php

declare(strict_types=1);

/*
 * The parts of the moselwal_podcast_episode_meta table (Content Blocks
 * collection of the podcast plugin from moselwal/content-blocks-collection)
 * that the PodcastEpisode schema reads: language handling, sorting, enable
 * fields, the inline relation columns and the per-episode values.
 */
return [
    'ctrl' => [
        'title' => 'Podcast episode meta',
        'label' => 'moselwal_podcastepisodemeta_episode_slug',
        'sortby' => 'sorting',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'translationSource' => 'l10n_source',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
        'hideTable' => true,
    ],
    'columns' => [
        'foreign_table_parent_uid' => ['config' => ['type' => 'passthrough']],
        'tablenames' => ['config' => ['type' => 'passthrough']],
        'fieldname' => ['config' => ['type' => 'passthrough']],
        'moselwal_podcastepisodemeta_episode_slug' => ['label' => 'Episode slug', 'config' => ['type' => 'input']],
        'moselwal_podcastepisodemeta_guests' => ['label' => 'Guests', 'config' => ['type' => 'text']],
        'moselwal_podcastepisodemeta_absent_hosts' => ['label' => 'Absent hosts', 'config' => ['type' => 'text']],
        'moselwal_podcastepisodemeta_transcript' => ['label' => 'Transcript', 'config' => ['type' => 'text']],
    ],
    'types' => [
        '0' => ['showitem' => 'moselwal_podcastepisodemeta_episode_slug, moselwal_podcastepisodemeta_transcript'],
    ],
];
