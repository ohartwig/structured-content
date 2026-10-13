CREATE TABLE pages (
    ai_context_audience varchar(50) DEFAULT '' NOT NULL,
    ai_context_tone varchar(50) DEFAULT '' NOT NULL,
    ai_context_channels varchar(255) DEFAULT '' NOT NULL,
    tx_structuredcontent_schema_type varchar(50) DEFAULT '' NOT NULL,
    tx_structuredcontent_service_type varchar(255) DEFAULT '' NOT NULL,
    tx_structuredcontent_service_category varchar(255) DEFAULT '' NOT NULL,
    tx_structuredcontent_code_repository varchar(255) DEFAULT '' NOT NULL,
    tx_structuredcontent_programming_language varchar(255) DEFAULT '' NOT NULL,
    tx_structuredcontent_software_version varchar(50) DEFAULT '' NOT NULL,
    tx_structuredcontent_software_license varchar(100) DEFAULT '' NOT NULL,
    tx_structuredcontent_operating_system varchar(255) DEFAULT '' NOT NULL,
    tx_structuredcontent_same_as text,
    tx_structuredcontent_about int(11) unsigned DEFAULT '0' NOT NULL,
    tx_structuredcontent_llms_featured smallint(5) unsigned DEFAULT '0' NOT NULL
);

CREATE TABLE tx_structuredcontent_about_entry (
    uid int(11) NOT NULL auto_increment,
    pid int(11) unsigned DEFAULT '0' NOT NULL,

    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,
    hidden tinyint(4) unsigned DEFAULT '0' NOT NULL,

    sys_language_uid int(11) DEFAULT '0' NOT NULL,
    l10n_parent int(11) DEFAULT '0' NOT NULL,
    l10n_source int(11) DEFAULT '0' NOT NULL,
    l10n_diffsource mediumblob,

    parent_table varchar(255) DEFAULT 'pages' NOT NULL,
    parent_uid int(11) unsigned DEFAULT '0' NOT NULL,
    parent_field varchar(255) DEFAULT 'tx_structuredcontent_about' NOT NULL,
    sorting int(11) DEFAULT '0' NOT NULL,
    schema_type varchar(50) DEFAULT 'Thing' NOT NULL,
    name varchar(255) DEFAULT '' NOT NULL,
    same_as text,

    PRIMARY KEY (uid),
    KEY parent (pid),
    KEY idx_parent (parent_table, parent_uid, parent_field),
    KEY language (l10n_parent, sys_language_uid)
);

CREATE TABLE tx_structuredcontent_schema_cache (
    page_uid int(11) unsigned DEFAULT '0' NOT NULL,
    language_uid int(11) DEFAULT '0' NOT NULL,
    schema_data mediumtext,
    cache_hash varchar(64) DEFAULT '' NOT NULL,
    expires int(11) unsigned DEFAULT '0' NOT NULL,

    PRIMARY KEY (page_uid, language_uid),
    KEY idx_expires (expires)
);

CREATE TABLE tt_content (
    ai_context_audience varchar(50) DEFAULT '' NOT NULL,
    ai_context_tone varchar(50) DEFAULT '' NOT NULL,
    ai_context_channels varchar(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_structuredcontent_relation (
    source_table varchar(255) DEFAULT '' NOT NULL,
    source_uid int(11) DEFAULT '0' NOT NULL,
    target_table varchar(255) DEFAULT '' NOT NULL,
    target_uid int(11) DEFAULT '0' NOT NULL,
    relation_type varchar(50) DEFAULT '' NOT NULL,
    direction varchar(20) DEFAULT 'unidirectional' NOT NULL,
    confidence double(5,4) DEFAULT '1.0000' NOT NULL,
    created_by varchar(100) DEFAULT 'manual' NOT NULL,
    description text,

    KEY idx_source (source_table, source_uid),
    KEY idx_target (target_table, target_uid),
    KEY idx_type (relation_type),
    KEY idx_direction (direction)
);
