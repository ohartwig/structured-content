<?php

declare(strict_types=1);

/*
 * The parts of the moselwal_person table (Content Blocks record type from
 * moselwal/content-blocks-collection) that a person reference is built from.
 */
return [
    'ctrl' => [
        'title' => 'Person',
        'label' => 'moselwal_person_lastname',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'enablecolumns' => [
            'disabled' => 'hidden',
        ],
    ],
    'columns' => [
        'moselwal_person_firstname' => ['label' => 'First name', 'config' => ['type' => 'input']],
        'moselwal_person_lastname' => ['label' => 'Last name', 'config' => ['type' => 'input']],
        'moselwal_person_slug' => ['label' => 'Slug', 'config' => ['type' => 'input']],
    ],
    'types' => [
        '0' => ['showitem' => 'moselwal_person_firstname, moselwal_person_lastname, moselwal_person_slug'],
    ],
];
