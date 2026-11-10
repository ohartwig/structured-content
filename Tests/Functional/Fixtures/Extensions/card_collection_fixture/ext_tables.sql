CREATE TABLE moselwal_card (
    foreign_table_parent_uid int(11) unsigned DEFAULT '0' NOT NULL,
    tablenames varchar(255) DEFAULT '' NOT NULL,
    fieldname varchar(255) DEFAULT '' NOT NULL,
    moselwal_card_header varchar(255) DEFAULT '' NOT NULL,
    moselwal_card_bodytext text,
    moselwal_card_cta_link varchar(1024) DEFAULT '' NOT NULL,
    moselwal_card_cta_label varchar(255) DEFAULT '' NOT NULL,
    moselwal_card_bodytext2 text,
    moselwal_card_works_for varchar(255) DEFAULT '' NOT NULL,
    moselwal_card_rating_value double(11,2) DEFAULT '0.00' NOT NULL,
    moselwal_card_review_count int(11) DEFAULT '0' NOT NULL
);
