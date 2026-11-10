CREATE TABLE tt_content (
    moselwal_videopodcastfeed_feed_url varchar(1024) DEFAULT '' NOT NULL,
    moselwal_videopodcastfeed_hosts text,
    moselwal_videopodcastfeed_episode_meta int(11) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE moselwal_podcast_episode_meta (
    foreign_table_parent_uid int(11) unsigned DEFAULT '0' NOT NULL,
    tablenames varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(255) DEFAULT '' NOT NULL,
    moselwal_podcastepisodemeta_episode_slug varchar(1024) DEFAULT '' NOT NULL,
    moselwal_podcastepisodemeta_guests text,
    moselwal_podcastepisodemeta_absent_hosts text,
    moselwal_podcastepisodemeta_transcript mediumtext
);

CREATE TABLE moselwal_person (
    moselwal_person_firstname varchar(255) DEFAULT '' NOT NULL,
    moselwal_person_lastname varchar(255) DEFAULT '' NOT NULL,
    moselwal_person_slug varchar(255) DEFAULT '' NOT NULL
);
