<?php

declare(strict_types=1);

/*
 * The moselwal_person table as the Content Blocks record type from
 * moselwal/content-blocks-collection registers it: translatable, every field
 * prefixed with the table name — the portrait field `image` becomes
 * `moselwal_person_image`.
 */
return [
    'ctrl' => [
        'title' => 'Person',
        'label' => 'moselwal_person_lastname',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'languageField' => 'sys_language_uid',
        'transOrigPointerField' => 'l10n_parent',
        'transOrigDiffSourceField' => 'l10n_diffsource',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    'columns' => [
        'moselwal_person_firstname' => ['label' => 'First name', 'config' => ['type' => 'input']],
        'moselwal_person_lastname' => ['label' => 'Last name', 'config' => ['type' => 'input']],
        'moselwal_person_slug' => ['label' => 'Slug', 'config' => ['type' => 'input']],
        'moselwal_person_job_title' => ['label' => 'Job title', 'config' => ['type' => 'input']],
        'moselwal_person_bio' => ['label' => 'Bio', 'config' => ['type' => 'text']],
        'moselwal_person_image' => [
            'label' => 'Image',
            'config' => ['type' => 'file', 'allowed' => 'common-image-types', 'maxitems' => 1],
        ],
    ],
    'types' => [
        '0' => ['showitem' => 'moselwal_person_firstname, moselwal_person_lastname, moselwal_person_slug, moselwal_person_job_title, moselwal_person_bio, moselwal_person_image'],
    ],
];
